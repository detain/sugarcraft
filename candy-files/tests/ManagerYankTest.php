<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-32: yank / paste. A yank only stages; the confirm gate (the same
 * one `c` and `m` use) fires at paste time against the cursor pane's cwd.
 */
final class ManagerYankTest extends FsTestCase
{
    private string $src;
    private string $dst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = $this->tmp . '/src';
        $this->dst = $this->tmp . '/dst';
        mkdir($this->src);
        mkdir($this->dst);
        file_put_contents($this->src . '/one.txt', '1');
        file_put_contents($this->src . '/two.txt', '2');
    }

    private function mgr(): Manager
    {
        return Manager::start($this->src, $this->dst, \SugarCraft\Files\FsLister::lister());
    }

    /** Drive a scheduled async copy to completion; the Manager that absorbed its CopyCompletedMsg. */
    private function settle(Manager $pending, ?\Closure $cmd): Manager
    {
        if ($cmd === null) {
            return $pending;
        }
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $final = $pending;
        $async->promise->then(function (?Msg $resolved) use ($pending, &$final): void {
            if ($resolved !== null) {
                [$final] = $pending->update($resolved);
            }
        });
        Loop::run();
        return $final;
    }

    public function testYankStagesWithoutTouchingTheFilesystem(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $this->assertSame(['dir' => $this->src, 'names' => ['one.txt'], 'op' => 'copy'], $m->yank);
        $this->assertStringContainsString('yanked 1 entries', $m->status);
        $this->assertSame(ConfirmState::None, $m->confirm);
        $this->assertFileDoesNotExist($this->dst . '/one.txt');
    }

    public function testYankPrefersTheSelectionAndRefusesTheSentinel(): void
    {
        $m = $this->mgr();
        $this->assertSame('nothing to yank', $this->press($m, 'y')->status);
        $m = $this->cursorOn($m, 'one.txt');
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Space, ' '));
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Space, ' '));
        $m = $this->press($m, 'y');
        $this->assertSame(['one.txt', 'two.txt'], $m->yank['names']);
    }

    public function testPasteWithNothingYankedSaysSo(): void
    {
        $m = $this->press($this->mgr(), 'p');
        $this->assertSame('nothing to paste — y or Y stages entries first', $m->status);
        $this->assertSame(ConfirmState::None, $m->confirm);
    }

    public function testPasteArmsTheCopyGateAtTheActivePanesCwd(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $m = $this->key($m, KeyType::Tab);              // cursor pane = dst
        $m = $this->press($m, 'p');
        $this->assertSame(ConfirmState::CopySelected, $m->confirm);
        $this->assertSame($this->dst, $m->pendingOpDest);
        $this->assertStringContainsString("copy 'one.txt' to " . $this->dst, $m->status);
        $this->assertFileDoesNotExist($this->dst . '/one.txt', 'the gate fires at paste, not before the answer');
    }

    public function testConfirmedCopyPasteLandsBytesAndKeepsTheYank(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $m = $this->press($this->key($m, KeyType::Tab), 'p');
        [$m, $cmd] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'y'));
        $m = $this->settle($m, $cmd);
        $this->assertSame('1', file_get_contents($this->dst . '/one.txt'));
        $this->assertFileExists($this->src . '/one.txt');
        $this->assertNotNull($m->yank, 'a copy-yank pastes again');
        $this->assertNull($m->pasteSrc);
    }

    public function testYankMovePastesAsMoveAndConsumesTheRegister(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'two.txt'), 'Y');
        $this->assertStringContainsString('cut 1 entries', $m->status);
        $m = $this->press($this->key($m, KeyType::Tab), 'p');
        $this->assertSame(ConfirmState::MoveSelected, $m->confirm);
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'y'));
        $this->assertFileDoesNotExist($this->src . '/two.txt');
        $this->assertSame('2', file_get_contents($this->dst . '/two.txt'));
        $this->assertNull($m->yank);
        $this->assertCount(1, $m->undoStack);
        $m = $m->undo();
        $this->assertFileExists($this->src . '/two.txt');
    }

    public function testDecliningThePasteCancelsAndTouchesNothing(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'two.txt'), 'Y');
        $m = $this->press($this->key($m, KeyType::Tab), 'p');
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'n'));
        $this->assertSame('cancelled', $m->status);
        $this->assertFileExists($this->src . '/two.txt');
        $this->assertNotNull($m->yank, 'a cancelled paste keeps the register');
        $this->assertNull($m->pasteSrc);
    }

    public function testPasteSourcesDoNotLeakIntoALaterPlainCopy(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $m = $this->press($this->key($m, KeyType::Tab), 'p');
        [$m] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'n'));
        // Back on src with the cursor on two.txt: a plain c must copy two.txt, not the stash.
        $m = $this->key($m, KeyType::Tab);
        $m = $this->press($this->cursorOn($m, 'two.txt'), 'c');
        $this->assertStringContainsString("'two.txt'", $m->status);
        $this->assertNull($m->pasteSrc);
    }

    public function testPasteIntoTheSameDirectoryIsReportedAsSkipped(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $m = $this->press($m, 'p');                      // active pane is still src
        [$m, $cmd] = $m->update(new \SugarCraft\Core\Msg\KeyMsg(KeyType::Char, 'y'));
        $m = $this->settle($m, $cmd);
        $this->assertStringContainsString('skipped', $m->status);
        $this->assertSame('1', file_get_contents($this->src . '/one.txt'));
    }

    public function testYankSurvivesDirectoryNavigation(): void
    {
        $m = $this->press($this->cursorOn($this->mgr(), 'one.txt'), 'y');
        $m = $this->key($m, KeyType::Tab);
        $m = $this->key($m, KeyType::Left);              // dst -> its parent
        $this->assertNotNull($m->yank);
        $this->assertSame($this->src, $m->yank['dir']);
    }
}
