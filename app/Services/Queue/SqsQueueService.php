<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Contracts\QueueServiceInterface;
use App\DataTransfer\QueueMessage;
use Aws\Sqs\SqsClient;
use Psr\Log\LoggerInterface;

/**
 * Production queue implementation backed by Amazon SQS.
 *
 * Message bodies are expected to be JSON describing the file to process:
 *   {"bucket": "...", "folderPath": "...", "fileName": "vehicles.csv"}
 *
 * Long polling is used (WaitTimeSeconds) so an empty queue costs a single
 * blocking call rather than a busy spin; the higher-level poller layers its
 * own idle-backoff on top of this.
 */
final class SqsQueueService implements QueueServiceInterface
{
    public function __construct(
        private readonly SqsClient $client,
        private readonly string $queueUrl,
        private readonly LoggerInterface $logger,
        private readonly int $waitTimeSeconds = 20,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function receiveMessages(int $maxMessages = 1): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl'            => $this->queueUrl,
            'MaxNumberOfMessages'=> max(1, min($maxMessages, 10)),
            'WaitTimeSeconds'    => $this->waitTimeSeconds,
        ]);

        $rawMessages = $result->get('Messages') ?? [];
        $messages = [];

        foreach ($rawMessages as $raw) {
            /** @var array<string, mixed> $body */
            $body = json_decode((string) ($raw['Body'] ?? '{}'), true) ?: [];
            $body['messageId'] = (string) ($raw['MessageId'] ?? '');
            $body['receiptHandle'] = (string) ($raw['ReceiptHandle'] ?? '');

            $messages[] = QueueMessage::fromArray($body);
        }

        return $messages;
    }

    public function deleteMessage(QueueMessage $message): void
    {
        $this->client->deleteMessage([
            'QueueUrl'      => $this->queueUrl,
            'ReceiptHandle' => $message->receiptHandle,
        ]);

        $this->logger->debug('Deleted SQS message', ['message_id' => $message->messageId]);
    }
}
