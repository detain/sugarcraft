<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\StashEntry;
use SugarCraft\Git\StashManager;

/**
 * Audit K6/A3 (2026-11-03 fix wave): discard / branch delete / stash drop were
 * one-press irreversible actions. They now arm on first press (naming the
 * target in the red lane) and execute only on a second press against the same
 * target; Escape cancels the arm.
 */
final class ConfirmTest extends TestCase
{
    private function git(array $status = [], array $branches = []): FixtureGit
    {
        return new FixtureGit(
            $status ?: [['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/A.php']],
            $branches ?: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            []
        );
    }

    public function testFirstDiscardPressArmsWithoutTouchingGit(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));

        self::assertSame([], $g->discards, 'one press must never discard bytes');
        self::assertSame('discard:src/A.php', $a->pendingConfirm);
        self::assertStringContainsString('src/A.php', (string) $a->error);
        self::assertStringContainsString('press d again', (string) $a->error);
    }

    public function testConfirmOnlyExecutesForSameTarget(): void
    {
        $g = $this->git(status: [
            ['index_status' => ' ', 'work_status' => 'M', 'path' => 'first.txt'],
            ['index_status' => ' ', 'work_status' => 'M', 'path' => 'second.txt'],
        ]);
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // arm first.txt
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));       // move cursor
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // re-arms on second.txt
        self::assertSame([], $g->discards);
        self::assertSame('discard:second.txt', $a->pendingConfirm);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // confirm second.txt
        self::assertSame(['second.txt'], $g->discards);
        self::assertNull($a->pendingConfirm, 'confirming must clear the arm');
    }

    public function testEscapeCancelsTheArm(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        self::assertNull($a->pendingConfirm);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // back to arming
        self::assertSame([], $g->discards);
        self::assertSame('discard:src/A.php', $a->pendingConfirm);
    }

    public function testQuitWhileArmedCancelsInsteadOfDiscarding(): void
    {
        // 'q' rides the Escape chain: with an arm live the first q must cancel
        // it, not execute anything (and the pane quit path is unrelated here).
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNull($a->pendingConfirm);
        self::assertSame([], $g->discards);
    }

    public function testBranchDeleteArmsThenDeletes(): void
    {
        $g = $this->git(branches: [
            ['name' => 'main', 'sha' => 'abc1', 'current' => true],
            ['name' => 'feature', 'sha' => 'def2', 'current' => false],
        ]);
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));

        self::assertSame([], $g->branchDeletions);
        self::assertSame('branch:feature', $a->pendingConfirm);
        self::assertStringContainsString('feature', (string) $a->error);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        self::assertSame(['feature'], $g->branchDeletions);
        self::assertNull($a->pendingConfirm);
    }

    public function testStashDropArmsThenDrops(): void
    {
        $g = $this->git();
        $g->stashListResult = [new StashEntry(0, 'cafe4321', 'main', 'wip')];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        self::assertInstanceOf(StashManager::class, $a->stashManager);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame([], $g->stashDrops);
        self::assertSame('stash:stash@{0}', $a->pendingConfirm);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame(['stash@{0}'], $g->stashDrops);
        self::assertNull($a->pendingConfirm);
    }

    public function testDiscardHistoryEntryOnlyOnConfirmedPress(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertCount(0, $a->history->undoStack, 'arming must not write history');
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertCount(1, $a->history->undoStack);
    }

    public function testArmDoesNotOutliveItsWarningAcrossNavigation(): void
    {
        // candy-git-002 (critical): on a one-row status pane d arms the
        // discard, j is a clamp no-op that still clobbers the red warning
        // lane, and a lone d after that used to EXECUTE the stranded arm —
        // bytes lost on a press the user could not have read as a confirm.
        // The arm is now bound to its warning: after the no-op move it must
        // be gone, the next d must re-arm visibly, and only the d after
        // that discards.
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame('discard:src/A.php', $a->pendingConfirm);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertNull($a->pendingConfirm, 'the arm must die with the warning it rode');
        self::assertNull($a->error, 'the vanished warning confirms the lane really clobbered');
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame([], $g->discards, 'first d after the move may only re-arm');
        self::assertSame('discard:src/A.php', $a->pendingConfirm);
        self::assertStringContainsString('press d again', (string) $a->error, 're-arm must warn again');
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame(['src/A.php'], $g->discards, 'the armed-then-confirmed pair still executes');
    }

    public function testPaneSwitchKillsAStaleArm(): void
    {
        // Same law through the other warning-clearing lane: tab flips panes,
        // the discard warning is gone, so the arm is gone too.
        $g = $this->git(status: [
            ['index_status' => ' ', 'work_status' => 'M', 'path' => 'first.txt'],
            ['index_status' => ' ', 'work_status' => 'M', 'path' => 'second.txt'],
        ]);
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // arm first.txt
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));         // -> Branches, warning clobbered
        self::assertNull($a->pendingConfirm, 'the tab lane must drop the arm with the warning');
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));         // -> Log
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));         // -> Status again (forward cycle)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // must re-arm, not execute
        self::assertSame([], $g->discards);
        self::assertSame('discard:first.txt', $a->pendingConfirm);
    }
}
