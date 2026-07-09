<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Contracts\QueueServiceInterface;
use App\DataTransfer\QueueMessage;

/**
 * File-backed queue used for development and tests.
 *
 * Each message is a JSON file inside a directory. Receiving a message renames
 * the file with an ".inflight" suffix (a crude visibility timeout) and deleting
 * it removes the in-flight file. This lets the full poll/ack loop be exercised
 * locally with no AWS dependency: drop a JSON file in the directory to enqueue.
 *
 * Expected JSON shape:
 *   {"bucket": "...", "folderPath": "...", "fileName": "vehicles.csv"}
 */
final class LocalQueueService implements QueueServiceInterface
{
    public function __construct(
        private readonly string $queueDirectory,
    ) {
        if (!is_dir($this->queueDirectory)) {
            mkdir($this->queueDirectory, 0775, true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function receiveMessages(int $maxMessages = 1): array
    {
        $pattern = rtrim($this->queueDirectory, '/\\') . DIRECTORY_SEPARATOR . '*.json';
        $files = glob($pattern) ?: [];
        sort($files); // stable, oldest-first by name

        $messages = [];

        foreach ($files as $file) {
            if (count($messages) >= $maxMessages) {
                break;
            }

            $inflight = $file . '.inflight';

            // Atomic-ish claim: if the rename fails another worker took it.
            if (!@rename($file, $inflight)) {
                continue;
            }

            $raw = file_get_contents($inflight);
            if ($raw === false) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true) ?: [];
            $data['messageId'] = basename($file);
            $data['receiptHandle'] = $inflight;

            $messages[] = QueueMessage::fromArray($data);
        }

        return $messages;
    }

    public function deleteMessage(QueueMessage $message): void
    {
        // The receipt handle is the in-flight file path.
        if (is_file($message->receiptHandle)) {
            @unlink($message->receiptHandle);
        }
    }
}
