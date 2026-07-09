<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Contracts\FileStorageServiceInterface;
use App\Exceptions\FileNotFoundException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * Production file store backed by Amazon S3.
 */
final class S3FileStorageService implements FileStorageServiceInterface
{
    public function __construct(
        private readonly S3Client $client,
    ) {
    }

    public function getFileContents(string $bucket, string $objectKey): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $bucket,
                'Key'    => $objectKey,
            ]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                throw new FileNotFoundException(
                    sprintf('S3 object not found: s3://%s/%s', $bucket, $objectKey),
                    previous: $e,
                );
            }

            throw $e;
        }

        return (string) $result->get('Body');
    }

    public function exists(string $bucket, string $objectKey): bool
    {
        return $this->client->doesObjectExist($bucket, $objectKey);
    }
}
