<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Contracts\FileStorageServiceInterface;
use App\Exceptions\FileNotFoundException;

/**
 * Local-filesystem file store used for development and tests.
 *
 * The "bucket" maps to a sub-directory beneath a configured base path, letting
 * the same (bucket, objectKey) addressing used in production work unchanged
 * against files on local disk.
 */
final class LocalFileStorageService implements FileStorageServiceInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function getFileContents(string $bucket, string $objectKey): string
    {
        $path = $this->resolvePath($bucket, $objectKey);

        if (!is_file($path)) {
            throw new FileNotFoundException(sprintf('Local file not found: %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileNotFoundException(sprintf('Failed to read local file: %s', $path));
        }

        return $contents;
    }

    public function exists(string $bucket, string $objectKey): bool
    {
        return is_file($this->resolvePath($bucket, $objectKey));
    }

    private function resolvePath(string $bucket, string $objectKey): string
    {
        $segments = array_filter([
            rtrim($this->basePath, '/\\'),
            trim($bucket, '/\\'),
            ltrim($objectKey, '/\\'),
        ], static fn (string $s): bool => $s !== '');

        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
