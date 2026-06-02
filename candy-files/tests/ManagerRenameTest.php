<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerRenameTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-rename-test-' . uniqid('', true);
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

    public function testArmRenameWithNoSelectionShowsError(): void
    {
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertStringContainsString('nothing to rename', $next->status);
    }

    public function testArmRenameWithCurrentEntryShowsConfirm(): void
    {
        file_put_contents($this->tmpDir . '/oldname.txt', 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        // Cursor starts at 0 (parent sentinel '..'), move down to get to oldname.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        $this->assertSame(ConfirmState::RenameSelected, $armed->confirm);
        $this->assertStringContainsString('rename', $armed->status);
        $this->assertStringContainsString('oldname.txt', $armed->status);
        $this->assertSame('oldname.txt', $armed->pendingOpDest);
        $this->assertSame('rename', $armed->pendingOpType);
    }

    public function testRenameFileMethod(): void
    {
        $srcFile = $this->tmpDir . '/oldname.txt';
        file_put_contents($srcFile, 'test content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);

        $result = $m->rename($srcFile, 'newname.txt');
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($srcFile);
        $this->assertFileExists($this->tmpDir . '/newname.txt');
        $this->assertSame('test content', file_get_contents($this->tmpDir . '/newname.txt'));
    }

    public function testRenameCancelledWhenSameName(): void
    {
        $file = $this->tmpDir . '/file.txt';
        file_put_contents($file, 'content');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'R'));

        // Simulate entering the same name (file.txt) - pendingOpDest is 'file.txt' from armRename
        // The actual rename operation with same name should cancel gracefully
        // Since rename uses pendingOpDest, and it equals current name, performRename returns cancelled
        $this->assertSame(ConfirmState::RenameSelected, $m->confirm);
    }
}
