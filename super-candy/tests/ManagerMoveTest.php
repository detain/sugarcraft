<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\SuperCandy\ConfirmState;
use SugarCraft\SuperCandy\Entry;
use SugarCraft\SuperCandy\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerMoveTest extends TestCase
{
    private string $tmpDir;
    private string $srcDir;
    private string $dstDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-move-test-' . uniqid('', true);
        $this->srcDir = $this->tmpDir . '/src';
        $this->dstDir = $this->tmpDir . '/dst';
        mkdir($this->srcDir, 0755, true);
        mkdir($this->dstDir, 0755, true);
        $this->lister = \SugarCraft\SuperCandy\FsLister::lister();
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

    public function testArmMoveWithNoSelectionShowsError(): void
    {
        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertStringContainsString('nothing to move', $next->status);
    }

    public function testArmMoveWithCurrentEntryShowsConfirm(): void
    {
        file_put_contents($this->srcDir . '/testfile.txt', 'content');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        // Cursor starts at 0 (parent sentinel '..'), move down to get to testfile.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'm'));

        $this->assertSame(ConfirmState::MoveSelected, $armed->confirm);
        $this->assertStringContainsString('move', $armed->status);
        $this->assertStringContainsString('testfile.txt', $armed->status);
        $this->assertSame($this->dstDir, $armed->pendingOpDest);
        $this->assertSame('move', $armed->pendingOpType);
    }

    public function testMoveConfirmedWithY(): void
    {
        $file = $this->srcDir . '/file.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        // Move past '..' parent sentinel
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        // Select it
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        // Arm move
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame(ConfirmState::MoveSelected, $m->confirm);
        // Confirm with y
        [$done] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame(ConfirmState::None, $done->confirm);
        $this->assertStringContainsString('moved', $done->status);
        // Verify file moved
        $this->assertFileDoesNotExist($file);
        $this->assertFileExists($this->dstDir . '/file.txt');
        $this->assertSame('content', file_get_contents($this->dstDir . '/file.txt'));
        $this->assertTrue($done->canUndo());
    }

    public function testMoveCancelledWithN(): void
    {
        $file = $this->srcDir . '/file.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame(ConfirmState::MoveSelected, $m->confirm);
        [$cancelled] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
        // File should still exist in source
        $this->assertFileExists($file);
    }

    public function testMoveFileMethod(): void
    {
        $srcFile = $this->srcDir . '/source.txt';
        $dstFile = $this->dstDir . '/dest.txt';
        file_put_contents($srcFile, 'test content');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);

        $result = $m->move($srcFile, $dstFile);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($srcFile);
        $this->assertFileExists($dstFile);
        $this->assertSame('test content', file_get_contents($dstFile));
    }
}
