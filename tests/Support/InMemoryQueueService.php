<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\QueueServiceInterface;
use App\DataTransfer\QueueMessage;

/**
 * Test-only, in-memory implementation of {@see QueueServiceInterface}.
 *
 * Messages are held in a plain array ("some object instead of SQS"). Received
 * messages move to an in-flight set and are only removed once acknowledged via
 * {@see deleteMessage()}; acknowledged messages are recorded so tests can
 * assert that a message was (or was not) acked. Bound only from the test
 * suite; never used in production.
 */
final class InMemoryQueueService implements QueueServiceInterface
{
    /** @var list<QueueMessage> */
    private array $pending = [];

    /** @var array<string, QueueMessage> keyed by receiptHandle */
    private array $inFlight = [];

    /** @var list<string> receiptHandles that were acknowledged */
    private array $deleted = [];

    private int $sequence = 0;

    /**
     * Enqueue a message for a file. Returns the created message.
     */
    public function push(string $fileName, string $bucket = 'local-bucket', string $folderPath = ''): QueueMessage
    {
        $id = 'msg-'.(++$this->sequence);

        $message = new QueueMessage(
            messageId: $id,
            receiptHandle: 'rh-'.$id,
            bucket: $bucket,
            folderPath: $folderPath,
            fileName: $fileName,
        );

        $this->pending[] = $message;

        return $message;
    }

    /**
     * {@inheritDoc}
     */
    public function receiveMessages(int $maxMessages = 1): array
    {
        $received = [];

        while ($this->pending !== [] && count($received) < max(1, $maxMessages)) {
            $message = array_shift($this->pending);
            $this->inFlight[$message->receiptHandle] = $message;
            $received[] = $message;
        }

        return $received;
    }

    public function deleteMessage(QueueMessage $message): void
    {
        unset($this->inFlight[$message->receiptHandle]);
        $this->deleted[] = $message->receiptHandle;
    }

    /**
     * @return list<string> receiptHandles that were acknowledged
     */
    public function acknowledged(): array
    {
        return $this->deleted;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function inFlightCount(): int
    {
        return count($this->inFlight);
    }
}
