<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Abstraction over the file store that holds incoming CSV files.
 *
 * Production binds this to an S3-backed implementation; tests/local runs bind
 * it to a local-filesystem implementation reading from a directory on disk.
 */
interface FileStorageServiceInterface
{
    /**
     * Return the full textual contents of the object identified by
     * ($bucket, $objectKey).
     *
     * @throws \App\Exceptions\FileNotFoundException When the object is absent.
     */
    public function getFileContents(string $bucket, string $objectKey): string;

    /**
     * Whether the object exists in the store.
     */
    public function exists(string $bucket, string $objectKey): bool;
}
