<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\InteractiveRebase;
use SugarCraft\Stash\RebaseAction;
use SugarCraft\Stash\RebaseCommit;
use PHPUnit\Framework\TestCase;

final class InteractiveRebaseTest extends TestCase
{
    public function testSelectingNFactory(): void
    {
        $ir = InteractiveRebase::selectingN();
        $this->assertTrue($ir->selectingN);
        $this->assertSame('', $ir->countInput);
        $this->assertSame([], $ir->commits);
        $this->assertFalse($ir->done);
    }

    public function testWithCountDigitAppendsDigit(): void
    {
        $ir = InteractiveRebase::selectingN();
        $this->assertSame('', $ir->countInput);

        $ir2 = $ir->withCountDigit('3');
        $this->assertSame('3', $ir2->countInput);

        $ir3 = $ir2->withCountDigit('2');
        $this->assertSame('32', $ir3->countInput);

        // Non-digit is ignored
        $ir4 = $ir3->withCountDigit('a');
        $this->assertSame('32', $ir4->countInput);
    }

    public function testBuildFromLogCreatesRebaseCommits(): void
    {
        $logCommits = [
            ['sha' => 'abc1234', 'subject' => 'first commit'],
            ['sha' => 'def5678', 'subject' => 'second commit'],
        ];
        $rebaseCommits = InteractiveRebase::buildFromLog($logCommits);

        $this->assertCount(2, $rebaseCommits);
        $this->assertSame('abc1234', $rebaseCommits[0]->sha);
        $this->assertSame('first commit', $rebaseCommits[0]->subject);
        $this->assertSame(RebaseAction::Pick, $rebaseCommits[0]->action);
    }

    public function testConfirmCountBuildsTodoList(): void
    {
        $ir = InteractiveRebase::selectingN()->withCountDigit('2');
        $logCommits = [
            ['sha' => 'abc', 'subject' => 'first'],
            ['sha' => 'def', 'subject' => 'second'],
            ['sha' => 'ghi', 'subject' => 'third'],
        ];
        $confirmed = $ir->confirmCount($logCommits);

        $this->assertFalse($confirmed->selectingN);
        $this->assertSame('', $confirmed->countInput);
        $this->assertCount(2, $confirmed->commits);
    }

    public function testConfirmCountWithInvalidCountReturnsError(): void
    {
        $ir = InteractiveRebase::selectingN()->withCountDigit('0');
        $logCommits = [
            ['sha' => 'abc', 'subject' => 'first'],
        ];
        $confirmed = $ir->confirmCount($logCommits);
        $this->assertNotNull($confirmed->error);
    }

    public function testWithCursorNavigates(): void
    {
        $commits = [
            new RebaseCommit('abc', 'first'),
            new RebaseCommit('def', 'second'),
            new RebaseCommit('ghi', 'third'),
        ];
        $ir = new InteractiveRebase(commits: $commits, cursor: 0, selectingN: false);

        $moved = $ir->withCursor(1);
        $this->assertSame(1, $moved->cursor);

        $moved2 = $moved->withCursor(1);
        $this->assertSame(2, $moved2->cursor);

        // Clamp at end
        $moved3 = $moved2->withCursor(1);
        $this->assertSame(2, $moved3->cursor);

        // Clamp at beginning
        $moved4 = $ir->withCursor(-1);
        $this->assertSame(0, $moved4->cursor);
    }

    public function testCycleActionChangesAction(): void
    {
        $commits = [new RebaseCommit('abc', 'first', RebaseAction::Pick)];
        $ir = new InteractiveRebase(commits: $commits, cursor: 0, selectingN: false);

        $cycled = $ir->cycleAction();
        $this->assertSame(RebaseAction::Reword, $cycled->commits[0]->action);

        $cycled2 = $cycled->cycleAction();
        $this->assertSame(RebaseAction::Edit, $cycled2->commits[0]->action);

        $cycled3 = $cycled2->cycleAction();
        $this->assertSame(RebaseAction::Squash, $cycled3->commits[0]->action);

        $cycled4 = $cycled3->cycleAction();
        $this->assertSame(RebaseAction::Drop, $cycled4->commits[0]->action);

        // Wraps back to Pick
        $cycled5 = $cycled4->cycleAction();
        $this->assertSame(RebaseAction::Pick, $cycled5->commits[0]->action);
    }

    public function testCycleActionOnlyWorksWhenNotSelectingN(): void
    {
        $ir = InteractiveRebase::selectingN();
        $cycled = $ir->cycleAction();
        // Should be unchanged
        $this->assertSame($ir->commits, $cycled->commits);
    }

    public function testDropCurrentRemovesCommit(): void
    {
        $commits = [
            new RebaseCommit('abc', 'first'),
            new RebaseCommit('def', 'second'),
            new RebaseCommit('ghi', 'third'),
        ];
        $ir = new InteractiveRebase(commits: $commits, cursor: 0, selectingN: false);

        $dropped = $ir->dropCurrent();
        $this->assertCount(2, $dropped->commits);
        $this->assertSame('def', $dropped->commits[0]->sha);
        $this->assertSame('ghi', $dropped->commits[1]->sha);
    }

    public function testDropCurrentAtEndAdjustsCursor(): void
    {
        $commits = [
            new RebaseCommit('abc', 'first'),
            new RebaseCommit('def', 'second'),
        ];
        $ir = new InteractiveRebase(commits: $commits, cursor: 1, selectingN: false);

        $dropped = $ir->dropCurrent();
        $this->assertCount(1, $dropped->commits);
        $this->assertSame(0, $dropped->cursor);
    }

    public function testMarkDoneSetsFlag(): void
    {
        $ir = new InteractiveRebase([], selectingN: false);
        $done = $ir->markDone();
        $this->assertTrue($done->done);
    }

    public function testWithErrorSetsError(): void
    {
        $ir = new InteractiveRebase([], selectingN: false);
        $withError = $ir->withError('something went wrong');
        $this->assertSame('something went wrong', $withError->error);
    }

    public function testCancelReturnsEmptyState(): void
    {
        $ir = new InteractiveRebase([], selectingN: false);
        $cancelled = $ir->cancel();
        $this->assertTrue($cancelled->selectingN);
        $this->assertSame('', $cancelled->countInput);
        $this->assertSame([], $cancelled->commits);
        $this->assertFalse($cancelled->done);
    }

    public function testRebaseCommitWithAction(): void
    {
        $commit = new RebaseCommit('abc', 'test commit', RebaseAction::Edit);
        $this->assertSame('abc', $commit->sha);
        $this->assertSame('test commit', $commit->subject);
        $this->assertSame(RebaseAction::Edit, $commit->action);

        $withPick = $commit->withAction(RebaseAction::Pick);
        $this->assertSame(RebaseAction::Pick, $withPick->action);
        // Original unchanged
        $this->assertSame(RebaseAction::Edit, $commit->action);
    }

    public function testRebaseCommitDisplayLine(): void
    {
        $commit = new RebaseCommit('abc1234', 'my subject', RebaseAction::Pick);
        $this->assertSame('pick abc1234 my subject', $commit->displayLine());
    }
}
