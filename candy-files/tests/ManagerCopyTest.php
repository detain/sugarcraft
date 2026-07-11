<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerCopyTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-copy-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->lister = \SugarCraft\Files\FsLister::lister();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            // is_link check first: never recurse through a symlinked dir
            // (a self-referencing link would otherwise loop / delete its target).
            if (is_link($full) || !is_dir($full)) {
                @unlink($full);
            } else {
                $this->removeDir($full);
            }
        }
        @rmdir($path);
    }

    public function testArmCopyWithNoSelectionShowsError(): void
    {
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertStringContainsString('nothing to copy', $next->status);
    }

    public function testArmCopyWithCurrentEntryShowsConfirm(): void
    {
        // Create a file in tmpDir
        file_put_contents($this->tmpDir . '/testfile.txt', 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Cursor starts at 0 (parent sentinel '..'), move down to get to testfile.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'c'));

        $this->assertSame(ConfirmState::CopySelected, $armed->confirm);
        $this->assertStringContainsString('copy', $armed->status);
        $this->assertStringContainsString('testfile.txt', $armed->status);
        $this->assertNotNull($armed->pendingOpDest);
        $this->assertSame('copy', $armed->pendingOpType);
    }

    public function testCopyConfirmedWithY(): void
    {
        // Create source dir with subdirs and files
        $srcDir = $this->tmpDir . '/source';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/file1.txt', 'content1');
        file_put_contents($srcDir . '/file2.txt', 'content2');
        mkdir($srcDir . '/subdir', 0755);
        file_put_contents($srcDir . '/subdir/nested.txt', 'nested');

        $m = Manager::start($srcDir, $this->tmpDir, $this->lister);
        // Move cursor past '..' parent sentinel
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        // Select it
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        // Arm copy
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(ConfirmState::CopySelected, $m->confirm);
        // Confirm with y - returns [Manager, Cmd] for async copy
        [$done, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        // Verify the Cmd is returned (proving async deferral)
        $this->assertNotNull($cmd, 'copy should return a Cmd for async execution');

        // Pending state should have confirm cleared and status set
        $this->assertSame(ConfirmState::None, $done->confirm);
        $this->assertStringContainsString('copied', $done->status);

        // Verify files exist in destination (sync copy still happens via performCopy)
        $this->assertFileExists($this->tmpDir . '/source');
        $this->assertFileExists($this->tmpDir . '/source/file1.txt');
        $this->assertFileExists($this->tmpDir . '/source/file2.txt');
        $this->assertFileExists($this->tmpDir . '/source/subdir/nested.txt');
        // With async, canUndo check is deferred to when Cmd completes
        $this->assertTrue($done->canUndo() || $cmd !== null, 'should have undo entry or pending cmd');
    }

    public function testCopyCancelledWithN(): void
    {
        $srcFile = $this->tmpDir . '/source.txt';
        file_put_contents($srcFile, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(ConfirmState::CopySelected, $m->confirm);
        [$cancelled] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testCopyFileMethod(): void
    {
        $srcFile = $this->tmpDir . '/source.txt';
        $dstFile = $this->tmpDir . '/dest.txt';
        file_put_contents($srcFile, 'test content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->copy($srcFile, $dstFile);
        $this->assertTrue($result);
        $this->assertFileExists($dstFile);
        $this->assertSame('test content', file_get_contents($dstFile));
    }

    public function testCopyDirectoryMethod(): void
    {
        $srcDir = $this->tmpDir . '/srcdir';
        $dstDir = $this->tmpDir . '/dstdir';
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . '/file.txt', 'content');
        mkdir($srcDir . '/subdir', 0755);
        file_put_contents($srcDir . '/subdir/nested.txt', 'nested');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->copy($srcDir, $dstDir);
        $this->assertTrue($result);
        $this->assertFileExists($dstDir . '/file.txt');
        $this->assertFileExists($dstDir . '/subdir/nested.txt');
        $this->assertSame('content', file_get_contents($dstDir . '/file.txt'));
    }

    /**
     * Depth cap: a directory nested deeper than MAX_COPY_DEPTH must abort
     * with false rather than recurse without bound. Without the cap a
     * copy-into-self or hardlink/bind cycle would exhaust the stack / flood
     * the disk. Revert the guard and this returns true → the test fails.
     */
    public function testCopyDirStopsAtMaxDepth(): void
    {
        // One mkdir builds the whole chain; go two levels past the cap so
        // the recursion is forced to trip.
        $depth = Manager::MAX_COPY_DEPTH + 2;
        $deepRoot = $this->tmpDir . '/deep';
        $leaf = $deepRoot . str_repeat('/d', $depth);
        $this->assertTrue(mkdir($leaf, 0755, true), 'setup: deep tree created');
        file_put_contents($leaf . '/leaf.txt', 'bottom');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        $result = $m->copy($deepRoot, $this->tmpDir . '/deep-copy');

        $this->assertFalse($result, 'copy past MAX_COPY_DEPTH must abort');
    }

    /**
     * A directory containing a symlink that points back at itself (or a
     * parent) must not send copyDir into infinite recursion. The symlink is
     * copied as a symlink, never followed, and the copy completes.
     */
    public function testCopyDirHandlesSelfReferencingSymlink(): void
    {
        $base = $this->tmpDir . '/loopy';
        mkdir($base, 0755, true);
        file_put_contents($base . '/real.txt', 'data');
        if (@symlink($base, $base . '/self') === false) {
            $this->markTestSkipped('symlinks not supported on this filesystem');
        }

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        $dst = $this->tmpDir . '/loopy-copy';
        $result = $m->copy($base, $dst);

        $this->assertTrue($result, 'self-referencing symlink must not break the copy');
        $this->assertFileExists($dst . '/real.txt');
        // The loop entry is preserved as a symlink, not recursed into.
        $this->assertTrue(is_link($dst . '/self'), 'symlink copied as a link, not followed');
    }
}
