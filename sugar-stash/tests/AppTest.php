<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Stash\App;
use SugarCraft\Stash\GitDriver;
use SugarCraft\Stash\Pane;
use PHPUnit\Framework\TestCase;

final class FixtureGit implements GitDriver
{
    public array $statusRows;
    public array $branchRows;
    public array $logRows;
    public array $stages = [];
    public array $unstages = [];
    public array $checkouts = [];
    public array $commits = [];
    public bool $stageAllCalled = false;

    public function __construct(array $statusRows, array $branchRows, array $logRows)
    {
        $this->statusRows = $statusRows;
        $this->branchRows = $branchRows;
        $this->logRows    = $logRows;
    }

    public function status(): array   { return $this->statusRows; }
    public function branches(): array { return $this->branchRows; }
    public function log(int $limit = 25): array { return $this->logRows; }
    public function stage(string $path): void   { $this->stages[]   = $path; }
    public function unstage(string $path): void { $this->unstages[] = $path; }
    public function checkout(string $branch): void { $this->checkouts[] = $branch; }
    public function commit(string $message): void  { $this->commits[] = $message; }
    public function stageAll(): void             { $this->stageAllCalled = true; }
}

final class AppTest extends TestCase
{
    private function git(): FixtureGit
    {
        return new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php'],
                ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/B.php'],
            ],
            branchRows: [
                ['name' => 'main',    'sha' => 'abc1', 'current' => true],
                ['name' => 'feature', 'sha' => 'def2', 'current' => false],
            ],
            logRows: [
                ['sha' => 'abc1', 'subject' => 'first commit',  'author' => 'Joe',  'ago' => '5m ago'],
                ['sha' => 'def2', 'subject' => 'second commit', 'author' => 'Joe',  'ago' => '2m ago'],
            ],
        );
    }

    public function testStartLoadsThreePanes(): void
    {
        $a = App::start($this->git());
        $this->assertCount(2, $a->status);   // branch_summary stripped from list
        $this->assertCount(2, $a->branches);
        $this->assertCount(2, $a->log);
        $this->assertSame('main...origin/main', $a->branchSummary);
        $this->assertSame(Pane::Status, $a->pane);
    }

    public function testTabCyclesPaneFocus(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Branches, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Log, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(Pane::Status, $a->pane);
    }

    public function testJKMovesActivePaneCursor(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $a->statusCursor);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $a->branchesCursor);
        $this->assertSame(1, $a->statusCursor);              // unchanged
    }

    public function testCursorClampsAtListBoundary(): void
    {
        $a = App::start($this->git());
        for ($i = 0; $i < 10; $i++) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame(1, $a->statusCursor);  // 2 items → cap at index 1
    }

    public function testQuit(): void
    {
        $a = App::start($this->git());
        [, $cmd] = $a->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testStageInvokesGitForUnstagedEntry(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // cursor 0 → src/A.php (already staged), 'j' → src/B.php (unstaged)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertSame(['src/B.php'], $g->stages);
    }

    public function testStageOnAlreadyStagedEntryUnstages(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // cursor 0 → src/A.php is staged (M index)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertSame(['src/A.php'], $g->unstages);
    }

    public function testStageOnlyWorksInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertSame([], $g->stages);
        $this->assertSame([], $g->unstages);
    }

    public function testRefreshKeyDoesNotCrash(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertCount(2, $a->status);
    }

    public function testHelpKeySetsShowHelp(): void
    {
        $a = App::start($this->git());
        $this->assertFalse($a->showHelp);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertTrue($a->showHelp);
    }

    public function testEscapeClosesHelpOverlay(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertTrue($a->showHelp);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->showHelp);
    }

    public function testBranchCheckoutOnSpaceKey(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        $this->assertSame(Pane::Branches, $a->pane);
        // cursor 0 is 'main' (current), cursor 1 is 'feature'
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));  // move to index 1 → feature
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame(['feature'], $g->checkouts);
    }

    public function testBranchCheckoutOnlyInBranchesPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Try Space while in Status pane
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame([], $g->checkouts);
    }

    public function testCommitKeyStartsMessageCollection(): void
    {
        $a = App::start($this->git());
        $this->assertFalse($a->collectingCommit);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertTrue($a->collectingCommit);
        $this->assertSame('', $a->commitMessage);
    }

    public function testCommitMessageCollection(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertTrue($a->collectingCommit);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        $this->assertSame('f', $a->commitMessage);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('fi', $a->commitMessage);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('fix', $a->commitMessage);
    }

    public function testCommitExecutesOnEnter(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['fix'], $g->commits);
        $this->assertFalse($a->collectingCommit);
    }

    public function testCommitEscapeCancelsCollection(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->collectingCommit);
        $this->assertSame('', $a->commitMessage);
    }

    public function testStageAllOnAKey(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // 'a' only works in Status pane — stay there
        $this->assertSame(Pane::Status, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($g->stageAllCalled);
    }

    public function testStageAllOnlyInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        $this->assertSame(Pane::Branches, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertFalse($g->stageAllCalled);
    }
}
