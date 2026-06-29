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
    public bool $unstageAllCalled = false;
    /** @var list<string> */
    public array $diffs = [];
    public array $discards = [];
    public bool $amendCalled = false;
    /** @var array<string, string> path => hunk patch */
    public array $stagePatches = [];
    /** @var array<string, string> path => hunk patch */
    public array $unstagePatches = [];
    public array $branchCreations = [];
    public array $branchDeletions = [];
    public array $merges = [];
    public bool $resetCalled = false;
    public bool $rebaseContinueCalled = false;
    public bool $rebaseAbortCalled = false;
    public bool $rebaseSkipCalled = false;
    /** @var list<\SugarCraft\Stash\StashEntry> */
    public array $stashListResult = [];
    /** @var list<string> */
    public array $stashApplies = [];
    /** @var list<string> */
    public array $stashDrops = [];
    public array $cherryPicks = [];
    public bool $cherryPickContinueCalled = false;
    public bool $cherryPickAbortCalled = false;
    /** @var list<\SugarCraft\Stash\WorktreeEntry> */
    public array $worktreeListResult = [];
    /** @var list<array{path:string, branch:string}> */
    public array $worktreeAdds = [];
    /** @var list<string> */
    public array $worktreeRemoves = [];

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
    public function diff(string $path): array    { return $this->diffs; }
    public function discard(string $path): void   { $this->discards[] = $path; }
    public function amend(): void                 { $this->amendCalled = true; }
    public function stagePatch(string $path, string $hunk): void { $this->stagePatches[$path] = $hunk; }
    public function unstageAll(): void { $this->unstageAllCalled = true; }
    public function unstagePatch(string $path, string $hunk): void { $this->unstagePatches[$path] = $hunk; }
    public function createBranch(string $name): void { $this->branchCreations[] = $name; }
    public function deleteBranch(string $name): void { $this->branchDeletions[] = $name; }
    public function merge(string $branch): void { $this->merges[] = $branch; }
    public function rebaseContinue(): void { $this->rebaseContinueCalled = true; }
    public function rebaseAbort(): void { $this->rebaseAbortCalled = true; }
    public function rebaseSkip(): void { $this->rebaseSkipCalled = true; }
    public function reset(): void { $this->resetCalled = true; }

    public function stashList(): array { return $this->stashListResult; }
    public function stashApply(string $stashRef): void { $this->stashApplies[] = $stashRef; }
    public function stashDrop(string $stashRef): void { $this->stashDrops[] = $stashRef; }
    public function cherryPick(string $commit): void { $this->cherryPicks[] = $commit; }
    public function cherryPickContinue(): void { $this->cherryPickContinueCalled = true; }
    public function cherryPickAbort(): void { $this->cherryPickAbortCalled = true; }
    public function worktreeList(): array { return $this->worktreeListResult; }
    public function worktreeAdd(string $path, string $branch): void { $this->worktreeAdds[] = ['path' => $path, 'branch' => $branch]; }
    public function worktreeRemove(string $path): void { $this->worktreeRemoves[] = $path; }
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

    public function testEscapeClosesDiffViewer(): void
    {
        $g = $this->git();
        $g->diffs = ['+added line'];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        $this->assertNotNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->diffViewer);
    }

    public function testAmendKeyCallsAmendOnGit(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertFalse($g->amendCalled);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'A'));
        $this->assertTrue($g->amendCalled);
    }

    public function testCreateBranchKeyStartsCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertFalse($a->collectingBranchName);
        $this->assertSame('', $a->branchName);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertTrue($a->collectingBranchName);
        $this->assertSame('', $a->branchName);
    }

    public function testBranchNameCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertTrue($a->collectingBranchName);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        $this->assertSame('f', $a->branchName);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('fe', $a->branchName);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('fea', $a->branchName);
    }

    public function testCreateBranchOnEnter(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'e'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['fea'], $g->branchCreations);
        $this->assertFalse($a->collectingBranchName);
    }

    public function testBranchCollectionEscapeCancels(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->collectingBranchName);
        $this->assertSame('', $a->branchName);
    }

    public function testSpaceInDiffViewerStagesCurrentHunk(): void
    {
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        $this->assertNotNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertArrayHasKey('src/A.php', $g->stagePatches);
        $this->assertNull($a->diffViewer);
    }

    public function testDiffViewerHunkNavigation(): void
    {
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            '@@ -5,3 +5,4 @@',
            '-line 5',
            '+line 5 modified',
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        $this->assertNotNull($a->diffViewer);
        $this->assertSame(2, $a->diffViewer->hunkCount());

        // Navigate down to second hunk
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertNotNull($a->diffViewer);
    }

    public function testDiscardKeyCallsDiscardOnGit(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertSame([], $g->discards);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(['src/A.php'], $g->discards);
    }

    public function testDiscardKeyOnlyInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame([], $g->discards);
    }

    public function testPDiffViewerKeyOpensDiffViewer(): void
    {
        $g = $this->git();
        $g->diffs = ['+added line'];
        $a = App::start($g);
        $this->assertNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        $this->assertNotNull($a->diffViewer);
        $this->assertSame('src/A.php', $a->diffViewer->path);
    }

    public function testPDiffViewerKeyOnlyInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'P'));
        $this->assertNull($a->diffViewer);
    }

    public function testUKeyStartsUndoShowsErrorWhenNothingToUndo(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Initially nothing to undo
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('nothing to undo', $a->error);
    }

    public function testUKeyUndoesStageOperation(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Stage a file (cursor 0 is src/A.php which is already staged)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertSame(['src/A.php'], $g->unstages);
        // Now undo should stage it back
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertSame(['src/A.php'], $g->stages);
    }

    public function testCtrlRRedoShowsErrorWhenNothingToRedo(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Initially nothing to redo - Ctrl+R is KeyType::Char with ctrl=true
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('nothing to redo', $a->error);
    }

    public function testDKeyDeletesSelectedBranch(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // branches
        $this->assertSame(Pane::Branches, $a->pane);
        // Cursor 1 is 'feature' (not current branch)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $a->branchesCursor);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        $this->assertSame(['feature'], $g->branchDeletions);
    }

    public function testDKeyDoesNotDeleteCurrentBranch(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // branches
        $this->assertSame(Pane::Branches, $a->pane);
        // Cursor 0 is 'main' (current branch)
        $this->assertTrue($a->branches[0]['current']);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        $this->assertSame([], $g->branchDeletions);
        $this->assertNotNull($a->error);
    }

    public function testMKeyStartsMergeCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertFalse($a->collectingMergeTarget);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        $this->assertTrue($a->collectingMergeTarget);
        $this->assertSame('', $a->mergeTarget);
    }

    public function testMergeTargetCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        $this->assertTrue($a->collectingMergeTarget);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        $this->assertSame('f', $a->mergeTarget);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('fe', $a->mergeTarget);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('fea', $a->mergeTarget);
    }

    public function testMergeExecutesOnEnter(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'e'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['fe'], $g->merges);
        $this->assertFalse($a->collectingMergeTarget);
    }

    public function testMergeEscapeCancelsCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->collectingMergeTarget);
        $this->assertSame('', $a->mergeTarget);
        $this->assertSame([], $g->merges);
    }

    public function testRKeyShowsRebaseMenuWhenRebaseInProgress(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Simulate rebase in progress by checking if showRebaseMenu becomes true
        // when we press 'r'. The isRebaseInProgress check returns false for fixture git
        // so we can't actually test this without a real git repo.
        // Instead, just verify the key doesn't crash and shows error when no rebase
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('no rebase', $a->error);
    }

    public function testRKeyWithEscClosesRebaseMenu(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // This test would require a real git rebase in progress to be meaningful
        // For the fixture, pressing r shows an error, not the menu
        $this->markTestSkipped('Rebase menu requires real git repo with rebase in progress');
    }

    public function testSKeyOpensStashManager(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertNull($a->stashManager);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        $this->assertNotNull($a->stashManager);
    }

    public function testEscapeClosesStashManager(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        $this->assertNotNull($a->stashManager);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->stashManager);
    }

    public function testVKeyStartsCherryPickCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertNull($a->cherryPick);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'V'));
        $this->assertNotNull($a->cherryPick);
        $this->assertTrue($a->cherryPick->collecting);
        $this->assertSame('', $a->cherryPick->commitRef);
    }

    public function testCherryPickCommitRefCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'V'));
        $this->assertTrue($a->cherryPick->collecting);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $a->cherryPick->commitRef);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('ab', $a->cherryPick->commitRef);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame('abc', $a->cherryPick->commitRef);
    }

    public function testCherryPickExecutesOnEnter(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'V'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'b'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['ab'], $g->cherryPicks);
        $this->assertNull($a->cherryPick);
    }

    public function testCherryPickEscapeCancelsCollection(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'V'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->cherryPick);
    }

    public function testWKeyOpensWorktreeManagerInBranchesPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // → branches
        $this->assertSame(Pane::Branches, $a->pane);
        $this->assertNull($a->worktrees);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertNotNull($a->worktrees);
    }

    public function testWKeyDoesNothingInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertSame(Pane::Status, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertNull($a->worktrees);
    }

    public function testEscapeClosesWorktreeManager(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertNotNull($a->worktrees);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->worktrees);
    }

    public function testIKeyStartsInteractiveRebase(): void
    {
        $g = $this->git();
        $a = App::start($g);
        $this->assertNull($a->interactiveRebase);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertNotNull($a->interactiveRebase);
        $this->assertTrue($a->interactiveRebase->selectingN);
    }

    public function testInteractiveRebaseCountInput(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertTrue($a->interactiveRebase->selectingN);
        $this->assertSame('', $a->interactiveRebase->countInput);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertSame('3', $a->interactiveRebase->countInput);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame('32', $a->interactiveRebase->countInput);
    }

    public function testEscapeClosesInteractiveRebase(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertNotNull($a->interactiveRebase);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->interactiveRebase);
    }
}
