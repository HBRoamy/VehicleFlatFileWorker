<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataTransfer\QueueMessage;

/**
 * Abstraction over the source queue.
 *
 * Production binds this to an SQS-backed implementation; tests/local runs bind
 * it to a file- or memory-backed implementation so no AWS account is required.
 */
interface QueueServiceInterface
{
    /**
     * Fetch up to $maxMessages from the queue. Returns an empty array when the
     * queue is currently empty (this is expected and drives the backoff logic).
     *
     * @return list<QueueMessage>
     */
    public function receiveMessages(int $maxMessages = 1): array;

    /**
     * Acknowledge a message so it will not be redelivered.
     */
    public function deleteMessage(QueueMessage $message): void;
}
