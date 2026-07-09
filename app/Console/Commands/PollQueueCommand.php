<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\QueueServiceInterface;
use App\Exceptions\LockAcquisitionException;
use App\Services\Processing\VehicleFileProcessor;
use Illuminate\Console\Command;

/**
 * Long-running queue poller (the application's primary "hosted service").
 *
 * Behaviour:
 *  - Continuously polls the queue for file messages.
 *  - When a message arrives, processes the file and acknowledges the message.
 *  - When a file is already locked by another instance, the message is simply
 *    acknowledged and skipped (no error).
 *  - Adaptive idle backoff: after the queue has been continuously empty for
 *    `empty_poll_threshold_seconds`, the poller sleeps for
 *    `backoff_sleep_seconds` before resuming normal-interval polling. Any
 *    received message resets the idle timer.
 *  - Graceful shutdown: SIGTERM/SIGINT stop the loop at the next safe point,
 *    including waking early from a backoff sleep (where signals are supported).
 */
final class PollQueueCommand extends Command
{
    protected $signature = 'vehicle:poll
                            {--once : Process a single receive cycle then exit (useful for testing)}';

    protected $description = 'Poll the queue and process incoming vehicle data files.';

    private bool $shouldStop = false;

    public function handle(
        QueueServiceInterface $queue,
        VehicleFileProcessor $processor,
        \App\Services\Processing\AmpRecordProcessor $recordProcessor,
    ): int {
        $this->registerSignalHandlers();

        $pollInterval = (int) config('vehicle.polling.interval_seconds');
        $emptyThreshold = (int) config('vehicle.polling.empty_poll_threshold_seconds');
        $backoffSleep = (int) config('vehicle.polling.backoff_sleep_seconds');

        $emptySince = null; // timestamp when the queue first became empty

        $this->info(sprintf(
            'Poller started (instance=%s, interval=%ds, empty_threshold=%ds, backoff=%ds).',
            (string) config('vehicle.instance_id'),
            $pollInterval,
            $emptyThreshold,
            $backoffSleep,
        ));

        try {
            do {
                $messages = $queue->receiveMessages(maxMessages: 1);

                if ($messages === []) {
                    $emptySince ??= time();
                    $idleFor = time() - $emptySince;

                    if ($idleFor >= $emptyThreshold) {
                        $this->line(sprintf('Queue empty for %ds; backing off for %ds.', $idleFor, $backoffSleep));
                        $this->interruptibleSleep($backoffSleep);
                        $emptySince = time(); // restart the idle window after backoff
                    } else {
                        $this->interruptibleSleep($pollInterval);
                    }

                    continue;
                }

                // Work arrived: reset the idle window.
                $emptySince = null;

                foreach ($messages as $message) {
                    $this->processMessage($queue, $processor, $message);

                    if ($this->shouldStop) {
                        break;
                    }
                }
            } while (!$this->shouldStop && !$this->option('once'));
        } finally {
            // Ensure worker processes are not left orphaned when we stop.
            $recordProcessor->shutdown();
        }

        $this->info('Poller stopped cleanly.');

        return self::SUCCESS;
    }

    private function processMessage(
        QueueServiceInterface $queue,
        VehicleFileProcessor $processor,
        \App\DataTransfer\QueueMessage $message,
    ): void {
        try {
            $summary = $processor->handle($message);
            $queue->deleteMessage($message);

            $this->info(sprintf(
                'Processed %s: %d good, %d bad.',
                $message->fileName,
                $summary['good'],
                $summary['bad'],
            ));
        } catch (LockAcquisitionException $e) {
            // Another instance owns this file: ack and move on, no error.
            $queue->deleteMessage($message);
            $this->line(sprintf('Skipped %s (already locked): %s', $message->fileName, $e->getMessage()));
        } catch (\Throwable $e) {
            // Do NOT delete the message; let the queue's redelivery handle retry.
            $this->error(sprintf('Failed %s: %s', $message->fileName, $e->getMessage()));
        }
    }

    /**
     * Register POSIX signal handlers when the extension is available. On
     * platforms without pcntl (e.g. native Windows) this is a no-op and the
     * process can still be stopped with Ctrl+C.
     */
    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function (): void {
            $this->shouldStop = true;
            $this->line('Shutdown signal received; finishing current work...');
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    /**
     * Sleep for up to $seconds, waking early if a stop signal arrives. Falls
     * back to a plain sleep when pcntl is unavailable.
     */
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
