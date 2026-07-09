<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SecretsProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic secrets refresh (the application's secondary "hosted service").
 *
 * On the configured interval it re-pulls secrets from the backing store and
 * rebuilds the database connection using the freshly rotated credentials, so a
 * long-lived process keeps working across credential rotation.
 *
 * The whole loop is gated by `secrets.enable_refresh`; when disabled (typical
 * in tests) the command performs a single refresh and exits, which keeps the
 * refresh code path exercised without spawning a daemon.
 */
final class RefreshSecretsCommand extends Command
{
    protected $signature = 'vehicle:refresh-secrets
                            {--once : Refresh once and exit regardless of config}';

    protected $description = 'Refresh secrets (and the DB connection) on a fixed interval.';

    private bool $shouldStop = false;

    public function handle(SecretsProviderInterface $secrets): int
    {
        $this->registerSignalHandlers();

        $enabled = (bool) config('vehicle.secrets.enable_refresh');
        $intervalHours = (int) config('vehicle.secrets.refresh_interval_hours');
        $runOnce = $this->option('once') || !$enabled;

        do {
            $count = $secrets->refresh();
            $this->reconfigureDatabase($secrets);

            $this->info(sprintf('Refreshed %d secret(s) and rebuilt the DB connection.', $count));

            if ($runOnce) {
                break;
            }

            $this->interruptibleSleep($intervalHours * 3600);
        } while (!$this->shouldStop);

        return self::SUCCESS;
    }

    /**
     * Rebuild the default database connection from the (possibly rotated)
     * connection secret. Purging then reconnecting forces Laravel to pick up
     * the new credentials on the next query.
     */
    private function reconfigureDatabase(SecretsProviderInterface $secrets): void
    {
        try {
            $connectionString = $secrets->getSecret('db_connection_string');
        } catch (\App\Exceptions\SecretNotFoundException) {
            // No rotated DB secret present (e.g. local env) - nothing to do.
            return;
        }

        $parsed = $this->parseConnectionString($connectionString);
        if ($parsed === []) {
            return;
        }

        $connection = (string) config('database.default');

        foreach ($parsed as $key => $value) {
            config(["database.connections.{$connection}.{$key}" => $value]);
        }

        DB::purge($connection);
        DB::reconnect($connection);
    }

    /**
     * Parse a key=value;key=value connection string into config overrides.
     *
     * @return array<string, string>
     */
    private function parseConnectionString(string $dsn): array
    {
        $map = [];

        foreach (explode(';', $dsn) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $pair, 2));

            $normalised = match (strtolower($key)) {
                'host', 'server'          => 'host',
                'port'                    => 'port',
                'database', 'dbname', 'db'=> 'database',
                'username', 'user', 'uid' => 'username',
                'password', 'pwd'         => 'password',
                default                   => null,
            };

            if ($normalised !== null) {
                $map[$normalised] = $value;
            }
        }

        return $map;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = fn () => $this->shouldStop = true;
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    private function interruptibleSleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $hasPcntl = function_exists('pcntl_signal_dispatch');
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            if ($this->shouldStop) {
                return;
            }

            sleep(1);

            if ($hasPcntl) {
                pcntl_signal_dispatch();
            }
        }
    }
}
