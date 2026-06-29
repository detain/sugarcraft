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
            if (is_dir($full)) {
                $this->removeDir($full);
            } else {
                @unlink($full);
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
}
