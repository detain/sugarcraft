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
        self::assertStringContainsString('src/A.php', (string) $a->notice);
        self::assertStringContainsString('press d again', (string) $a->notice);
        self::assertNull($a->error, 'a request for confirmation is not an error state (candy-git-047)');
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
        self::assertStringContainsString('feature', (string) $a->notice);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        self::assertSame(['feature'], $g->branchDeletions);
        self::assertNull($a->pendingConfirm);
    }

    // ----------------------------------------------------------------- remotes (CG-06)

    public function testEachRemoteVerbArmsThenExecutesOnSecondPress(): void
    {
        foreach (['f' => ['fetch', 'fetchCalled'], 'p' => ['pull', 'pullCalled'], 'P' => ['push', 'pushCalled']] as $key => [$verb, $flag]) {
            $g = $this->git();
            $a = App::start($g);
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $key));

            self::assertFalse($g->{$flag}, "one press must never run {$verb}");
            self::assertSame("remote:{$verb}", $a->pendingConfirm);
            self::assertStringContainsString("press {$key} again", (string) $a->notice);

            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $key));
            self::assertTrue($g->{$flag}, "second press must run {$verb}");
            self::assertNull($a->pendingConfirm, 'confirming must clear the arm');
            self::assertStringContainsString($verb, (string) $a->successMessage);
        }
    }

    public function testRemoteArmDiesOnEscapeAndPaneSwitch(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        self::assertNull($a->pendingConfirm);
        self::assertFalse($g->pushCalled);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'p'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // pane switch kills a stale arm
        self::assertNull($a->pendingConfirm);
        self::assertFalse($g->pullCalled);
    }

    public function testFailedPushLandsInTheErrorLaneWithArmCleared(): void
    {
        $g = $this->git();
        $g->throwOn['push'] = new \RuntimeException('push rejected: non-fast-forward');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        self::assertSame('push rejected: non-fast-forward', (string) $a->error);
        self::assertNull($a->pendingConfirm, 'the arm must not survive into the error lane');
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

    public function testArmAndItsNoticePersistAcrossNavigation(): void
    {
        // candy-git-047 (lane root of the critical candy-git-002): the old
        // design rode the confirm request in the stealable red error slot, so
        // a harmless j erased the warning; the minimal cure (CG-03 era) died
        // the arm with it. The amber notice lane is the durable cure — the
        // arm and its request live together: navigation keeps both visible,
        // and a press on a DIFFERENT target can only re-arm (arm keys embed
        // the target, pinned by testConfirmOnlyExecutesForSameTarget).
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame('discard:src/A.php', $a->pendingConfirm);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame('discard:src/A.php', $a->pendingConfirm, 'navigation is not an answer to the arm');
        self::assertStringContainsString('press d again', (string) $a->notice, 'the request persists with the arm');
        self::assertNull($a->error, 'the request never sat in the error lane');

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame(['src/A.php'], $g->discards, 'the confirmed pair executes with the request on screen');
        self::assertNull($a->pendingConfirm);
        self::assertNull($a->notice, 'executing acknowledges the lane');
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
        self::assertNull($a->notice, 'the amber lane dies with its arm — never an orphan request');
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));         // -> Log
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));         // -> Status again (forward cycle)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));       // must re-arm, not execute
        self::assertSame([], $g->discards);
        self::assertSame('discard:first.txt', $a->pendingConfirm);
    }

    // ------------------------------------------------------- notice lane (CG-11)

    public function testEscapeClearsNoticeAlongWithTheArm(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertNotNull($a->notice);

        [$a, ] = $a->update(new KeyMsg(KeyType::Escape));
        self::assertNull($a->pendingConfirm);
        self::assertNull($a->notice, 'cancel acknowledges the amber lane too');
        self::assertSame([], $g->discards);
    }

    public function testRefreshAcknowledgesNoticeAndArmTogether(): void
    {
        // R is a deliberate re-sync: an arm and its notice are one contract,
        // so the refresh must drop the pair together, never strand one.
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNull($a->pendingConfirm, 'refresh drops the arm with its notice');
        self::assertNull($a->notice);
    }

    public function testSuccessPersistsThroughNavigationAndDiesOnRefresh(): void
    {
        // candy-git-098: the line must not evaporate on a harmless j, and it
        // must not ride forever either — refresh/execute acknowledge it.
        $a = new App($this->git(), status: [['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/A.php']], successMessage: 'staged src/A.php', width: 80, height: 24);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame('staged src/A.php', $a->successMessage, 'navigation does not acknowledge a message');

        $a = $a->refresh();
        self::assertNull($a->successMessage, 'the re-sync acknowledges it');
    }
}
