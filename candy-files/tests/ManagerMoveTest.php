<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
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
    /**
     * CF-06 (closes audit 006): a move that replaces an existing destination
     * must announce the victim count — undo reverses the mover but cannot
     * resurrect the clobbered file.
     */
    public function testMoveAnnouncesOverwrittenVictim(): void
    {
        $src = $this->srcDir . '/file.txt';
        $victim = $this->dstDir . '/file.txt';
        file_put_contents($src, 'payload');
        file_put_contents($victim, 'old bytes');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // onto file.txt
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame(ConfirmState::MoveSelected, $armed->confirm);
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame('moved 1 entries (1 overwrote)', $done->status);
        $this->assertFileDoesNotExist($src);
        $this->assertFileExists($victim);
        $this->assertSame('payload', file_get_contents($victim), 'the replacement really happened');
    }

    /**
     * A plain move into fresh ground must NOT claim an overwrite.
     */
    public function testMoveIntoEmptyDestinationStaysPlain(): void
    {
        $src = $this->srcDir . '/file.txt';
        file_put_contents($src, 'payload');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame('moved 1 entries', $done->status);
    }
    /**
     * CF-08/007+067: undo of a move refuses to overwrite a recreated
     * original (occupied leg, action stays pressable), and once the user
     * clears the collision the SAME stack entry reverses the move home.
     */
    public function testMoveUndoRefusesRecreatedOriginalThenSucceedsWhenCleared(): void
    {
        $src = $this->srcDir . '/a.txt';
        file_put_contents($src, 'payload');

        $m = Manager::start($this->srcDir, $this->dstDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        [$moved] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFileExists($this->dstDir . '/a.txt');

        file_put_contents($src, 'today');   // user recreates the original name

        [$blocked] = $moved->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('occupied', $blocked->status, 'status: ' . $blocked->status);
        $this->assertSame('today', file_get_contents($src), 'undo never ate the file created today');
        $this->assertFileExists($this->dstDir . '/a.txt');
        $this->assertTrue($blocked->canUndo(), 'the failed action stays on the undo stack');

        unlink($src);                        // user clears the collision

        [$home] = $blocked->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringNotContainsString('error', $home->status, 'status: ' . $home->status);
        $this->assertStringNotContainsString('occupied', $home->status, 'status: ' . $home->status);
        $this->assertSame('payload', file_get_contents($src), 'the bytes came home on the retry');
        $this->assertFileDoesNotExist($this->dstDir . '/a.txt');
    }

    /**
     * CF-43 (closes audit 070): with both panes on one cwd — the documented
     * startup default — the move destination equals the source directory.
     * Before the guard, @rename($s,$s) succeeded as a silent no-op, so the
     * status claimed "moved 1 entries" and pushed a self-reversing undo
     * action. A same-path leg counts in neither moved nor errors, and zero
     * undo actions enter history for it.
     */
    public function testMoveOntoSelfIsSkippedNotMoved(): void
    {
        $dir = $this->tmpDir . '/shared';
        mkdir($dir, 0755, true);
        $file = $dir . '/g.txt';
        file_put_contents($file, 'bytes');

        $m = Manager::start($dir, $dir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));   // off '..' onto g.txt
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'm'));
        $this->assertSame(ConfirmState::MoveSelected, $armed->confirm);
        $this->assertSame($dir, $armed->pendingOpDest, 'the arm destination is the same cwd');

        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame('moved 0 entries (1 skipped)', $done->status, 'status: ' . $done->status);
        $this->assertFileExists($file);
        $this->assertSame('bytes', file_get_contents($file), 'the self-leg must not touch the bytes');
        $this->assertFalse($done->canUndo(), 'no undo action may enter history for a self-leg');
    }
}
