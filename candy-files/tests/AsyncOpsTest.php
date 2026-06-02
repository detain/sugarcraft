<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Files\AsyncOps;

final class AsyncOpsTest extends TestCase
{
    private string $tmpDir;
    private AsyncOps $ops;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy_files_async_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->ops = new AsyncOps();
    }

    protected function tearDown(): void
    {
        // Clean up
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testCopyAsyncReturnsPromise(): void
    {
        $promise = $this->ops->copyAsync('/src', '/dst');
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testCopyAsyncResolvesTrueOnSuccess(): void
    {
        $src = $this->tmpDir . '/source.txt';
        $dst = $this->tmpDir . '/dest.txt';
        file_put_contents($src, 'hello');

        $promise = $this->ops->copyAsync($src, $dst);

        // Wait for the promise
        $resolved = false;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        // Run the loop briefly
        Loop::run();

        $this->assertTrue($resolved);
        $this->assertFileExists($dst);
        $this->assertSame('hello', file_get_contents($dst));
    }

    public function testCopyAsyncResolvesFalseOnFailure(): void
    {
        $promise = $this->ops->copyAsync('/nonexistent/src', '/dst');

        $resolved = false;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        Loop::run();

        $this->assertFalse($resolved);
    }

    public function testMoveAsyncReturnsPromise(): void
    {
        $promise = $this->ops->moveAsync('/src', '/dst');
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testMoveAsyncResolvesTrueOnSuccess(): void
    {
        $src = $this->tmpDir . '/source.txt';
        $dst = $this->tmpDir . '/dest.txt';
        file_put_contents($src, 'moved');

        $promise = $this->ops->moveAsync($src, $dst);

        $resolved = false;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        Loop::run();

        $this->assertTrue($resolved);
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($dst);
        $this->assertSame('moved', file_get_contents($dst));
    }

    public function testRenameAsyncReturnsPromise(): void
    {
        $promise = $this->ops->renameAsync('/src', 'new_name');
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testRenameAsyncRenamesFile(): void
    {
        $src = $this->tmpDir . '/oldname.txt';
        file_put_contents($src, 'renamed');

        $promise = $this->ops->renameAsync($src, 'newname.txt');

        $resolved = false;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        Loop::run();

        $this->assertTrue($resolved);
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($this->tmpDir . '/newname.txt');
        $this->assertSame('renamed', file_get_contents($this->tmpDir . '/newname.txt'));
    }

    public function testCopyManyAsyncReturnsPromise(): void
    {
        $promise = $this->ops->copyManyAsync([]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testCopyManyAsyncCopiesMultipleFiles(): void
    {
        $map = [
            $this->tmpDir . '/a.txt' => $this->tmpDir . '/copy_a.txt',
            $this->tmpDir . '/b.txt' => $this->tmpDir . '/copy_b.txt',
        ];
        file_put_contents($map[$this->tmpDir . '/a.txt'], 'file a');
        file_put_contents($map[$this->tmpDir . '/b.txt'], 'file b');

        $promise = $this->ops->copyManyAsync($map);

        $resolved = null;
        $promise->then(function (array $results) use (&$resolved): void {
            $resolved = $results;
            Loop::stop();
        });

        Loop::run();

        $this->assertNotNull($resolved);
        $this->assertSame($map[$this->tmpDir . '/a.txt'], $this->tmpDir . '/copy_a.txt');
        $this->assertSame($map[$this->tmpDir . '/b.txt'], $this->tmpDir . '/copy_b.txt');
        $this->assertFileExists($this->tmpDir . '/copy_a.txt');
        $this->assertFileExists($this->tmpDir . '/copy_b.txt');
    }

    public function testMoveManyAsyncReturnsPromise(): void
    {
        $promise = $this->ops->moveManyAsync([]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testMoveManyAsyncMovesMultipleFiles(): void
    {
        file_put_contents($this->tmpDir . '/a.txt', 'move a');
        file_put_contents($this->tmpDir . '/b.txt', 'move b');

        $map = [
            $this->tmpDir . '/a.txt' => $this->tmpDir . '/moved_a.txt',
            $this->tmpDir . '/b.txt' => $this->tmpDir . '/moved_b.txt',
        ];

        $promise = $this->ops->moveManyAsync($map);

        $resolved = null;
        $promise->then(function (array $results) use (&$resolved): void {
            $resolved = $results;
            Loop::stop();
        });

        Loop::run();

        $this->assertNotNull($resolved);
        $this->assertFileDoesNotExist($this->tmpDir . '/a.txt');
        $this->assertFileDoesNotExist($this->tmpDir . '/b.txt');
        $this->assertFileExists($this->tmpDir . '/moved_a.txt');
        $this->assertFileExists($this->tmpDir . '/moved_b.txt');
    }

    public function testCopyAsyncDirectory(): void
    {
        $srcDir = $this->tmpDir . '/src_dir';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/file.txt', 'nested');

        $dstDir = $this->tmpDir . '/dst_dir';

        $promise = $this->ops->copyAsync($srcDir, $dstDir);

        $resolved = false;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        Loop::run();

        $this->assertTrue($resolved);
        $this->assertFileExists($dstDir . '/file.txt');
        $this->assertSame('nested', file_get_contents($dstDir . '/file.txt'));
    }
}
