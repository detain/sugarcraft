<?php

declare(strict_types=1);

namespace CandyCore\Stash\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Stash\App;
use CandyCore\Stash\GitDriver;
use CandyCore\Stash\Pane;
use PHPUnit\Framework\TestCase;

final class FixtureGit implements GitDriver
{
    public array $statusRows;
    public array $branchRows;
    public array $logRows;
    public array $stages = [];
    public array $unstages = [];

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
}
