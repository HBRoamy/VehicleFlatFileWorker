<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RefreshSecretsCommand;
use ReflectionMethod;
use Tests\TestCase;

final class RefreshSecretsCommandTest extends TestCase
{
    public function test_refresh_once_runs_and_exits_successfully(): void
    {
        // Env driver has no db_connection_string secret, so the DB rebuild is a
        // no-op; the command should still refresh and exit 0.
        $this->artisan('vehicle:refresh-secrets', ['--once' => true])
            ->assertExitCode(0);
    }

    public function test_parse_connection_string_maps_keys_and_aliases(): void
    {
        $method = new ReflectionMethod(RefreshSecretsCommand::class, 'parseConnectionString');

        $parsed = $method->invoke(
            new RefreshSecretsCommand(),
            'Server=db.internal; Port=5432; dbname=vehicles; uid=svc; pwd=s3cr3t',
        );

        self::assertSame([
            'host'     => 'db.internal',
            'port'     => '5432',
            'database' => 'vehicles',
            'username' => 'svc',
            'password' => 's3cr3t',
        ], $parsed);
    }

    public function test_parse_connection_string_ignores_unknown_and_malformed_pairs(): void
    {
        $method = new ReflectionMethod(RefreshSecretsCommand::class, 'parseConnectionString');

        $parsed = $method->invoke(
            new RefreshSecretsCommand(),
            'host=h; sslmode=require; garbage; =nokey; port=6543',
        );

        self::assertSame(['host' => 'h', 'port' => '6543'], $parsed);
    }
}
