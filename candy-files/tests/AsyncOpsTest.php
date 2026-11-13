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

    /**
     * Depth cap on the async path: a directory nested deeper than
     * MAX_COPY_DEPTH resolves false rather than recursing without bound.
     * Revert the guard and this resolves true → the test fails.
     */
    public function testCopyAsyncStopsAtMaxDepth(): void
    {
        $depth = AsyncOps::MAX_COPY_DEPTH + 2;
        $deepRoot = $this->tmpDir . '/deep';
        $leaf = $deepRoot . str_repeat('/d', $depth);
        $this->assertTrue(mkdir($leaf, 0755, true), 'setup: deep tree created');
        file_put_contents($leaf . '/leaf.txt', 'bottom');

        $promise = $this->ops->copyAsync($deepRoot, $this->tmpDir . '/deep-copy');

        $resolved = null;
        $promise->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });

        Loop::run();

        $this->assertFalse($resolved, 'copy past MAX_COPY_DEPTH must abort');
    }

    /**
     * M-10 (step 13): copying a symlink must REPLACE an existing
     * destination — the bare symlink() used to fail silently there while
     * the schedule-time "(N overwrote)" count had already advertised a
     * replacement. Revert the unlink-then-link guard and this resolves
     * false with the old destination bytes still in place → red.
     */
    public function testSymlinkCopyReplacesExistingDestination(): void
    {
        $target = $this->tmpDir . '/target.txt';
        file_put_contents($target, 'points-here');
        $src = $this->tmpDir . '/link-src';
        $this->assertTrue(symlink($target, $src), 'setup: source symlink');
        $dst = $this->tmpDir . '/link-dst';
        file_put_contents($dst, 'stale-occupant');

        $resolved = null;
        $this->ops->copyAsync($src, $dst)->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });
        Loop::run();

        $this->assertTrue($resolved, 'symlink copy onto an existing path must succeed');
        $this->assertTrue(is_link($dst), 'destination is a link now, not the old file');
        $this->assertSame($target, readlink($dst));
    }

    /**
     * M-10 parity (step 13): the recursive directory walk replaces an
     * existing inner entry when the source tree carries a symlink of the
     * same name (fresh-destination copies were never the broken case).
     */
    public function testCopyDirReplacesExistingSymlinkEntry(): void
    {
        $srcDir = $this->tmpDir . '/src-tree';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/note.txt', 'fresh');
        $this->assertTrue(symlink('elsewhere', $srcDir . '/note-link'), 'setup: inner link');
        $dstDir = $this->tmpDir . '/dst-tree';
        mkdir($dstDir, 0755, true);
        file_put_contents($dstDir . '/note-link', 'occupant');

        $resolved = null;
        $this->ops->copyAsync($srcDir, $dstDir)->then(function (bool $result) use (&$resolved): void {
            $resolved = $result;
            Loop::stop();
        });
        Loop::run();

        $this->assertTrue($resolved);
        $this->assertTrue(is_link($dstDir . '/note-link'), 'inner occupant replaced by the link');
        $this->assertSame('elsewhere', readlink($dstDir . '/note-link'));
    }
    // ------------------------------------------------------- CF-07 engine

    public function testFileLegPreservesModeAndMtime(): void
    {
        $src = $this->tmpDir . '/secret.txt';
        $dst = $this->tmpDir . '/copy.txt';
        file_put_contents($src, 'hush');
        chmod($src, 0600);
        touch($src, 1_600_000_000);

        self::assertTrue(AsyncOps::copyPath($src, $dst));
        clearstatcache();
        self::assertSame(0600, fileperms($dst) & 07777, 'a 0600 secret stays 0600 through copy');
        self::assertSame(1_600_000_000, filemtime($dst));
    }

    public function testFileLegCarriesTheExecBit(): void
    {
        $src = $this->tmpDir . '/tool.sh';
        $dst = $this->tmpDir . '/tool-copy.sh';
        file_put_contents($src, "#!/bin/sh\necho hi\n");
        chmod($src, 0755);

        self::assertTrue(AsyncOps::copyPath($src, $dst));
        clearstatcache();
        self::assertSame(0111, fileperms($dst) & 0111, 'exec bits survive the copy');
    }

    public function testAFailedLegLeavesDestinationIntactAndNoResidue(): void
    {
        $dst = $this->tmpDir . '/victim.txt';
        file_put_contents($dst, 'keep me');

        self::assertFalse(AsyncOps::copyPath($this->tmpDir . '/nope-src.txt', $dst));
        self::assertSame('keep me', file_get_contents($dst), 'a failed copy never touches the old destination');
        self::assertSame([], glob($this->tmpDir . '/*.candyfiles-tmp-*'), 'no temp residue after a failed leg');
    }

    public function testADirectoryDestinationIsCountedAsFailure(): void
    {
        $src = $this->tmpDir . '/file.txt';
        $dstDir = $this->tmpDir . '/occupied-dir';
        file_put_contents($src, 'x');
        mkdir($dstDir);

        self::assertFalse(AsyncOps::copyPath($src, $dstDir));
        self::assertSame([], scandir($dstDir) === false ? [] : array_values(array_diff(scandir($dstDir), ['.', '..'])), 'nothing landed inside the occupied directory');
    }

    public function testRecursiveCopyWithFailingChildRemovesTheCreatedTree(): void
    {
        $src = $this->tmpDir . '/tree';
        $lock = $src . '/locked';
        mkdir($lock, 0755, true);
        file_put_contents($src . '/aaa.txt', 'ok');
        file_put_contents($lock . '/secret.bin', 'nope');
        chmod($lock . '/secret.bin', 0000);
        if (is_readable($lock . '/secret.bin')) {
            $this->markTestSkipped('uid bypasses file modes (running as root?)');
        }

        $dst = $this->tmpDir . '/out';
        self::assertFalse(AsyncOps::copyPath($src, $dst), 'the unreadable child fails the whole copy');
        chmod($lock . '/secret.bin', 0600); // let tearDown delete it

        self::assertDirectoryDoesNotExist($dst, 'a half-tree must not remain as if it were a real copy');
    }

    public function testSymlinkLegCopiesTheLinkItselfDanglingIncluded(): void
    {
        $probe = $this->tmpDir . '/.link-probe';
        if (!@symlink('nowhere', $probe)) {
            $this->markTestSkipped('symlinks unavailable for this user/platform');
        }
        @unlink($probe);

        $link = $this->tmpDir . '/pointer';
        symlink('the-target-need-not-exist', $link);
        $dst = $this->tmpDir . '/pointer-copy';

        self::assertTrue(AsyncOps::copyPath($link, $dst));
        self::assertTrue(is_link($dst));
        self::assertSame('the-target-need-not-exist', readlink($dst));
    }

    public function testSymlinkLegReplacesAnExistingDestination(): void
    {
        $probe = $this->tmpDir . '/.link-probe2';
        if (!@symlink('nowhere', $probe)) {
            $this->markTestSkipped('symlinks unavailable for this user/platform');
        }
        @unlink($probe);

        $link = $this->tmpDir . '/pointer2';
        symlink('fresh-target', $link);
        $dst = $this->tmpDir . '/occupied';
        file_put_contents($dst, 'old file bytes');

        self::assertTrue(AsyncOps::copyPath($link, $dst));
        self::assertTrue(is_link($dst), 'the victim file was replaced by the link (M-10 parity)');
        self::assertSame('fresh-target', readlink($dst));
    }
}
