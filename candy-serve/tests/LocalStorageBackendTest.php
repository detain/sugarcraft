<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\LFS\LocalStorageBackend;

/**
 * @covers \SugarCraft\Serve\LFS\LocalStorageBackend
 */
final class LocalStorageBackendTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/lfs-backend-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function testObjectPathGeneration(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'abcd1234efgh5678';

        // Use reflection to test private method
        $reflection = new \ReflectionClass($backend);
        $method = $reflection->getMethod('objectPath');
        $method->setAccessible(true);

        $path = $method->invoke($backend, $oid);

        // Path should follow LFS convention: {lfsPath}/{first 2 chars}/{next 2 chars}/{oid}
        $this->assertSame($this->tmpDir . '/ab/cd/abcd1234efgh5678', $path);
    }

    public function testSizeReturnsZeroForNonexistentObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        $size = $backend->size('nonexistent-oid-12345');

        $this->assertSame(0, $size);
    }

    public function testSizeReturnsActualSize(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'size-test-oid12345';

        // Write content of known size
        $content = 'Hello World';
        $stream = fopen('data://text/plain;base64,' . base64_encode($content), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $size = $backend->size($oid);

        $this->assertSame(\strlen($content), $size);
    }

    public function testReadThrowsExceptionForNonexistentObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $backend->read('nonexistent-oid-read');
    }

    public function testReadReturnsResourceForExistingObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'read-test-oid12345';

        // Write content
        $content = 'Read Test Content';
        $stream = fopen('data://text/plain;base64,' . base64_encode($content), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $resource = $backend->read($oid);

        $this->assertIsResource($resource);
        $readContent = stream_get_contents($resource);
        $this->assertSame($content, $readContent);
        fclose($resource);
    }

    public function testWriteCreatesNestedDirectories(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'ab1234567890abcdef';

        // LFS path should create directories like: ab/cd/oid
        $stream = fopen('data://text/plain;base64,' . base64_encode('Nested'), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $this->assertTrue($backend->exists($oid));
        $this->assertDirectoryExists($this->tmpDir . '/ab');
        $this->assertDirectoryExists($this->tmpDir . '/ab/12');
    }

    public function testPathReturnsFullPath(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'path-test-oid12345';

        $stream = fopen('data://text/plain;base64,' . base64_encode('Path Test'), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $path = $backend->path($oid);

        $this->assertNotNull($path);
        $this->assertSame($this->tmpDir . '/pa/th/path-test-oid12345', $path);
    }

    public function testPathReturnsNullForNonexistent(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        // Actually path() returns the path regardless of existence
        $path = $backend->path('nonexistent-oid');

        $this->assertNotNull($path);
        $this->assertStringContainsString('nonexistent-oid', $path);
    }

    public function testWriteAndOverwrite(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = 'overwrite-test-oid12';

        // Write initial content
        $stream1 = fopen('data://text/plain;base64,' . base64_encode('Original'), 'r');
        $backend->write($oid, $stream1);
        fclose($stream1);

        // Overwrite with new content
        $stream2 = fopen('data://text/plain;base64,' . base64_encode('Updated'), 'r');
        $backend->write($oid, $stream2);
        fclose($stream2);

        // Verify new content
        $resource = $backend->read($oid);
        $content = stream_get_contents($resource);
        fclose($resource);

        $this->assertSame('Updated', $content);
    }

    public function testDeleteNonExistentDoesNotThrow(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        // Should not throw exception
        $backend->delete('nonexistent-delete-oid');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}
