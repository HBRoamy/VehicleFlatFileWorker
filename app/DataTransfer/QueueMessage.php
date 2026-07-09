<?php

declare(strict_types=1);

namespace App\DataTransfer;

/**
 * Immutable representation of a single message pulled from the queue.
 *
 * The queue payload tells the application which file to process and where
 * to find it. `receiptHandle` is the driver-specific token required to
 * acknowledge (delete) the message once processing has completed.
 */
final readonly class QueueMessage
{
    /**
     * @param string $messageId      Driver-assigned unique message identifier.
     * @param string $receiptHandle  Token used to delete/ack the message.
     * @param string $bucket         Logical storage bucket / container name.
     * @param string $folderPath     Folder (prefix) the file lives under.
     * @param string $fileName       CSV file name to process.
     */
    public function __construct(
        public string $messageId,
        public string $receiptHandle,
        public string $bucket,
        public string $folderPath,
        public string $fileName,
    ) {
    }

    /**
     * Build a message from a decoded array, tolerating both camelCase and
     * snake_case keys so producers in either convention are accepted.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (string) ($data['messageId'] ?? $data['message_id'] ?? ''),
            receiptHandle: (string) ($data['receiptHandle'] ?? $data['receipt_handle'] ?? ''),
            bucket: (string) ($data['bucket'] ?? ''),
            folderPath: (string) ($data['folderPath'] ?? $data['folder_path'] ?? ''),
            fileName: (string) ($data['fileName'] ?? $data['file_name'] ?? ''),
        );
    }

    /**
     * Full object key/path of the target file within the bucket.
     */
    public function objectKey(): string
    {
        $folder = trim($this->folderPath, '/');

        return $folder === ''
            ? $this->fileName
            : $folder . '/' . $this->fileName;
    }
}
