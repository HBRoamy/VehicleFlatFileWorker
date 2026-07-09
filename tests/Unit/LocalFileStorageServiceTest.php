<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\FileNotFoundException;
use App\Services\Storage\LocalFileStorageService;
use PHPUnit\Framework\TestCase;

final class LocalFileStorageServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/vdh-storage-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);
    }

    public function test_reads_file_at_base_bucket_key_path(): void
    {
        mkdir($this->base.'/local-bucket', 0775, true);
        file_put_contents($this->base.'/local-bucket/vehicles.csv', 'hello,world');

        $storage = new LocalFileStorageService($this->base);

        self::assertSame('hello,world', $storage->getFileContents('local-bucket', 'vehicles.csv'));
        self::assertTrue($storage->exists('local-bucket', 'vehicles.csv'));
    }

    public function test_throws_when_file_is_missing(): void
    {
        $storage = new LocalFileStorageService($this->base);

        self::assertFalse($storage->exists('local-bucket', 'nope.csv'));

        $this->expectException(FileNotFoundException::class);
        $storage->getFileContents('local-bucket', 'nope.csv');
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
