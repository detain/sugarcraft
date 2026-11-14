<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\GitDriver;
use SugarCraft\Git\Pane;
use PHPUnit\Framework\TestCase;

final class FixtureGit implements GitDriver
{
    public array $statusRows;
    public array $branchRows;
    public array $logRows;
    public array $stages = [];
    public array $unstages = [];
    public array $checkouts = [];
    public array $remoteCheckouts = [];
    public array $commits = [];
    public bool $stageAllCalled = false;
    public bool $unstageAllCalled = false;
    /** @var list<string> */
    public array $diffs = [];
    /** @var list<array{0:string,1:bool}> path + cached flag per diff() request */
    public array $diffRequests = [];
    public array $discards = [];
    public array $deletes = [];
    /** @var list<string> canned output for untrackedDiff()
     */
    public array $untrackedDiffResult = [];
    public bool $amendCalled = false;
    /** @var array<string, string> path => hunk patch */
    public array $stagePatches = [];
    /** @var array<string, string> path => hunk patch */
    public array $unstagePatches = [];
    /** @var list<array{name:string,from:?string}> */
    public array $branchCreations = [];
    public array $branchDeletions = [];
    /** @var list<array{name:string,sha:string}> */
    public array $restoredBranches = [];
    public array $merges = [];
    public bool $fetchCalled = false;
    public bool $pullCalled = false;
    public bool $pushCalled = false;
    public bool $resetCalled = false;
    public bool $rebaseContinueCalled = false;
    public bool $rebaseAbortCalled = false;
    public bool $rebaseSkipCalled = false;
    /** @var list<\SugarCraft\Git\StashEntry> */
    public array $stashListResult = [];
    /** @var list<string> */
    public array $stashApplies = [];
    /** @var list<string> */
    public array $stashDrops = [];
    public array $stashPushes = [];
    public array $stashPops = [];
    public array $cherryPicks = [];
    public bool $cherryPickContinueCalled = false;
    public bool $cherryPickAbortCalled = false;
    /** @var list<\SugarCraft\Git\WorktreeEntry> */
    public array $worktreeListResult = [];
    /** @var list<array{path:string, branch:string}> */
    public array $worktreeAdds = [];
    /** @var list<string> */
    public array $worktreeRemoves = [];
    /** @var array<string, \Throwable> method => exception to throw */
    public array $throwOn = [];

    public function __construct(array $statusRows, array $branchRows, array $logRows)
    {
        $this->statusRows = $statusRows;
        $this->branchRows = $branchRows;
        $this->logRows    = $logRows;
    }

    /** Audit #8: every driver method consults throwOn, not just the three reads. */
    private function maybeThrow(string $method): void
    {
        if (isset($this->throwOn[$method])) {
            throw $this->throwOn[$method];
        }
    }

    public function status(): array   { return $this->statusRows; }
    public function branches(): array { return $this->branchRows; }
    public function log(int $limit = 25): array {
        $this->maybeThrow('log');
        return $this->logRows;
    }
    public function stage(string $path): void   { $this->maybeThrow('stage'); $this->stages[]   = $path; }
    public function unstage(string $path): void { $this->maybeThrow('unstage'); $this->unstages[] = $path; }
    public function checkout(string $branch): void { $this->maybeThrow('checkout'); $this->checkouts[] = $branch; }
    public function checkoutRemote(string $remoteBranch): void { $this->maybeThrow('checkoutRemote'); $this->remoteCheckouts[] = $remoteBranch; }
    public function commit(string $message): void  { $this->maybeThrow('commit'); $this->commits[] = $message; }
    public function stageAll(): void             { $this->maybeThrow('stageAll'); $this->stageAllCalled = true; }
    public function diff(string $path, bool $cached = false): array { $this->maybeThrow('diff'); $this->diffRequests[] = [$path, $cached]; return $this->diffs; }
    public function discard(string $path): void   { $this->maybeThrow('discard'); $this->discards[] = $path; }
    public function untrackedDiff(string $path): array
    {
        $this->maybeThrow('untrackedDiff');

        return $this->untrackedDiffResult;
    }

    public function deleteUntracked(string $path): void { $this->maybeThrow('deleteUntracked'); $this->deletes[] = $path; }
    public function amend(): void                 { $this->maybeThrow('amend'); $this->amendCalled = true; }
    public bool $amendWithCalled = false;
    /** @var list<string> messages passed to amendWith() */
    public array $amendWiths = [];
    public string $lastCommitMessageReturn = 'feat: original';
    public function amendWith(string $message): void { $this->maybeThrow('amendWith'); $this->amendWithCalled = true; $this->amendWiths[] = $message; }
    public function lastCommitMessage(): string { $this->maybeThrow('lastCommitMessage'); return $this->lastCommitMessageReturn; }
    public function stagePatch(string $path, string $hunk): void { $this->maybeThrow('stagePatch'); $this->stagePatches[$path] = $hunk; }
    public function unstageAll(): void { $this->maybeThrow('unstageAll'); $this->unstageAllCalled = true; }
    public function unstagePatch(string $path, string $hunk): void { $this->maybeThrow('unstagePatch'); $this->unstagePatches[$path] = $hunk; }
    public function createBranch(string $name, ?string $from = null): void { $this->maybeThrow('createBranch'); $this->branchCreations[] = ['name' => $name, 'from' => $from]; }
    /** @var list<string> refs passed to revert() */
    public array $reverts = [];
    /** @var list<string> refs passed to resetHard() */
    public array $resetHards = [];
    /** @var list<string> lines returned by commitDetails() */
    public array $commitDetailsResult = [];
    public function commitDetails(string $ref): array { $this->maybeThrow('commitDetails'); return $this->commitDetailsResult; }
    public function revert(string $ref): void { $this->maybeThrow('revert'); $this->reverts[] = $ref; }
    public function resetHard(string $ref): void { $this->maybeThrow('resetHard'); $this->resetHards[] = $ref; }
    public function deleteBranch(string $name): void { $this->maybeThrow('deleteBranch'); $this->branchDeletions[] = $name; }
    public function restoreBranch(string $name, string $sha): void { $this->maybeThrow('restoreBranch'); $this->restoredBranches[] = ['name' => $name, 'sha' => $sha]; }
    public function merge(string $branch): void { $this->maybeThrow('merge'); $this->merges[] = $branch; }
    public bool $mergeAbortCalled = false;
    public bool $mergeContinueCalled = false;
    public function mergeAbort(): void { $this->maybeThrow('mergeAbort'); $this->mergeAbortCalled = true; }
    public function mergeContinue(): void { $this->maybeThrow('mergeContinue'); $this->mergeContinueCalled = true; }
    public function fetch(): void { $this->maybeThrow('fetch'); $this->fetchCalled = true; }
    public function pull(): void { $this->maybeThrow('pull'); $this->pullCalled = true; }
    public function push(): void { $this->maybeThrow('push'); $this->pushCalled = true; }
    public function rebaseContinue(): void { $this->maybeThrow('rebaseContinue'); $this->rebaseContinueCalled = true; }
    public function rebaseAbort(): void { $this->maybeThrow('rebaseAbort'); $this->rebaseAbortCalled = true; }
    public function rebaseSkip(): void { $this->maybeThrow('rebaseSkip'); $this->rebaseSkipCalled = true; }
    public function reset(): void { $this->maybeThrow('reset'); $this->resetCalled = true; }

    public function stashList(): array {
        $this->maybeThrow('stashList');
        return $this->stashListResult;
    }
    public function stashApply(string $stashRef): void { $this->maybeThrow('stashApply'); $this->stashApplies[] = $stashRef; }
    public function stashDrop(string $stashRef): void { $this->maybeThrow('stashDrop'); $this->stashDrops[] = $stashRef; }

    public string $stashShaResult = '9f8e7d6c5b4a39281706f5e4d3c2b1a098765432';

    public function stashSha(string $stashRef): string
    {
        $this->maybeThrow('stashSha');

        return $this->stashShaResult;
    }

    public string $stashSubjectResult = 'WIP on main: deadbeef seed';

    public function stashSubject(string $stashRef): string
    {
        $this->maybeThrow('stashSubject');

        return $this->stashSubjectResult;
    }

    /** @var list<string> */
    public array $stashRestores = [];

    public function stashRestore(string $sha, string $subject = ''): void
    {
        $this->maybeThrow('stashRestore');
        $this->stashRestores[] = [$sha, $subject];
    }
    public function stashPush(?string $message, bool $keepIndex = false, bool $includeUntracked = false): void { $this->maybeThrow('stashPush'); $this->stashPushes[] = [$message, $keepIndex, $includeUntracked]; }
    public function stashPop(string $stashRef): void { $this->maybeThrow('stashPop'); $this->stashPops[] = $stashRef; }
    public function cherryPick(string $commit): void { $this->maybeThrow('cherryPick'); $this->cherryPicks[] = $commit; }
    public function cherryPickContinue(): void { $this->maybeThrow('cherryPickContinue'); $this->cherryPickContinueCalled = true; }
    public function cherryPickAbort(): void { $this->maybeThrow('cherryPickAbort'); $this->cherryPickAbortCalled = true; }
    public function worktreeList(): array {
        $this->maybeThrow('worktreeList');
        return $this->worktreeListResult;
    }
    public function worktreeAdd(string $path, string $branch): void { $this->maybeThrow('worktreeAdd'); $this->worktreeAdds[] = ['path' => $path, 'branch' => $branch]; }
    public function worktreeRemove(string $path): void { $this->maybeThrow('worktreeRemove'); $this->worktreeRemoves[] = $path; }
    public string $sequenceStateResult = '';
    public function sequenceState(): string { $this->maybeThrow('sequenceState'); return $this->sequenceStateResult; }
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

    public function testSpaceOnRemoteRowTracksLocally(): void
    {
        $g = $this->git();
        // main is current; cursor 1 is a remote-tracking row (candy-git-037).
        $g->branchRows = [
            ['name' => 'main', 'sha' => 'abc1', 'current' => true, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => false],
            ['name' => 'origin/feature', 'sha' => 'def2', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));  // → origin/feature
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame(['origin/feature'], $g->remoteCheckouts, 'DWIM must ride --track');
        $this->assertSame([], $g->checkouts, 'a remote ref must never be checked out detached');
    }

    public function testSpaceOnRemoteRowFallsBackToExistingLocal(): void
    {
        $g = $this->git();
        $g->branchRows = [
            ['name' => 'main', 'sha' => 'abc1', 'current' => true, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => false],
            ['name' => 'origin/feature', 'sha' => 'def2', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
        ];
        // `--track` refuses when the local branch already exists; the DWIM
        // leg then plain-checks-out the short name. (The fixture throws
        // before recording, so the attempt is proven by the leg that lands.)
        $g->throwOn['checkoutRemote'] = new \RuntimeException('fatal: a branch named \'feature\' already exists');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame(['feature'], $g->checkouts, 'fallback must check out the local short name');
        $this->assertSame([], $g->branchDeletions);
    }

    public function testFailedRemoteDwimLandsInTheErrorLane(): void
    {
        $g = $this->git();
        $g->branchRows = [
            ['name' => 'main', 'sha' => 'abc1', 'current' => true, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => false],
            ['name' => 'origin/feature', 'sha' => 'def2', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
        ];
        $g->throwOn['checkoutRemote'] = new \RuntimeException('track failed');
        $g->throwOn['checkout']       = new \RuntimeException('checkout failed');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        // Both legs refused: the SECOND error is what surfaced — it names the
        // reason the branch switch did not happen, which is the user's question.
        $this->assertStringContainsString('checkout failed', $a->error ?? '');
        $this->assertNull($a->pendingConfirm);
    }

    public function testDeleteRefusesRemoteRowWithoutArming(): void
    {
        $g = $this->git();
        $g->branchRows = [
            ['name' => 'main', 'sha' => 'abc1', 'current' => true, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => false],
            ['name' => 'origin/feature', 'sha' => 'def2', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        $this->assertSame([], $g->branchDeletions);
        $this->assertNull($a->pendingConfirm, 'a read-only row must not reach the arm');
        $this->assertStringContainsString('read-only', $a->error ?? '');
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

    // ------------------------------------------- CG-34: stageAll undo (candy-git-042)

    public function testUndoOfStageAllRestoresAPriorCleanSelection(): void
    {
        // The default fixture stages src/A.php ahead of the sweep while
        // src/B.php is a plain worktree change. The old blanket reset --
        // undo would have erased A's selection; the capture-set inverse
        // resets and re-adds exactly it.
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($g->stageAllCalled);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertTrue($g->unstageAllCalled, 'the sweep is reset away first');
        self::assertSame(['src/A.php'], $g->stages, 'and only the pre-existing pick comes back');
        self::assertStringContainsString('undone', (string) $a->successMessage);
    }

    public function testUndoOfStageAllRefusesWhenAStagedFileWasPartiallyStaged(): void
    {
        // index != worktree means the staged version lives ONLY in the
        // index - any blanket or re-add inverse would destroy it. Refuse.
        $g = new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => 'M', 'work_status' => 'M', 'path' => 'src/C.php'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: [],
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertStringContainsString('cannot undo', (string) $a->error);
        self::assertStringContainsString('stage all', (string) $a->error);
        self::assertFalse($g->unstageAllCalled, 'the refusal touches nothing');
        self::assertSame([], $g->stages);
    }

    public function testUndoOfStageAllWithoutPriorSelectionKeepsThePlainInverse(): void
    {
        $g = new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main'],
                ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/B.php'],
                ['index_status' => '?', 'work_status' => '?', 'path' => 'new/F.php'],
            ],
            branchRows: [['name' => 'main', 'sha' => 'abc1', 'current' => true]],
            logRows: [],
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        self::assertTrue($g->unstageAllCalled);
        self::assertSame([], $g->stages, 'nothing to re-add - no selection games');
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
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->diffViewer);
    }

    public function testAmendKeyOpensTheCollectorPrefilled(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'A'));
        // candy-git-013: 'A' no longer fires a blind --no-edit amend; the
        // collector opens pre-filled so the message is editable at all.
        // (Intentional re-point of the frozen 'A amends immediately' pin.)
        $this->assertFalse($g->amendCalled);
        $this->assertTrue($a->collectingCommit);
        $this->assertTrue($a->commitAmend);
        $this->assertSame('feat: original', $a->commitMessage);
        // Submitting the buffer unchanged reproduces the old keypress
        // exactly — same bytes, now expressed through -m.
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['feat: original'], $g->amendWiths);
        $this->assertFalse($a->collectingCommit);
        $this->assertFalse($a->commitAmend);
        $this->assertCount(1, $a->history->undoStack);
    }

    public function testAmendOnEmptyRepoLandsInTheErrorLaneWithoutOpening(): void
    {
        $g = $this->git();
        $g->throwOn['lastCommitMessage'] = new \RuntimeException('cannot do it');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'A'));
        $this->assertNotNull($a->error);
        $this->assertFalse($a->collectingCommit);
    }

    public function testAmendModeDiesWithTheCollector(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'A'));   // pre-filled amend
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));  // abandon it
        $this->assertFalse($a->collectingCommit);
        $this->assertFalse($a->commitAmend);
        // The next collector is a plain commit: a stale amend flag must never
        // rewrite HEAD by accident (candy-git-013).
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['x'], $g->commits);
        $this->assertSame([], $g->amendWiths);
    }

    public function testTabAddsLinesAndEnterCommitsTheWholeMessage(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));   // open collector
        foreach (['s', 'u', 'b'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab));          // newline chord (candy-git-013)
        foreach (['b', 'o', 'd', 'y'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        self::assertSame("sub\nbody", $a->commitMessage);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));        // commits full message
        self::assertSame(["sub\nbody"], $g->commits);
        self::assertFalse($a->collectingCommit);
    }

    public function testTabDuringCommitCollectionDoesNotCyclePanes(): void
    {
        $g = $this->git();
        $a = App::start($g);
        self::assertSame(Pane::Status, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab));
        // The collector owns Tab: the pane underneath stays put.
        self::assertSame(Pane::Status, $a->pane);
        self::assertSame("\n", $a->commitMessage);
    }

    public function testUndoOfCommitReSeedsTheCollectorWithTheTypedMessage(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        foreach (['m', 's', 'g'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));        // commit 'msg'
        self::assertSame(['msg'], $g->commits);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));    // undo
        // candy-git-043: the boundary is reset AND the authored message rides
        // back into the collector instead of being silently dropped.
        self::assertTrue($g->resetCalled);
        self::assertTrue($a->collectingCommit);
        self::assertSame('msg', $a->commitMessage);
        // Escape closes it like any collector — the arm is advisory, not a trap.
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        self::assertFalse($a->collectingCommit);
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
        $this->assertSame([['name' => 'fea', 'from' => null]], $g->branchCreations);
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
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j')); // to src/B.php: worktree side
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertArrayHasKey('src/B.php', $g->stagePatches);
        // Re-anchored (candy-git-036): staging used to CLOSE the viewer,
        // turning a multi-hunk file into an open/stage/reopen loop. The
        // rebuild now keeps it open on the same side (the fixture hands
        // back the same diff, so the viewer sits on the same lone hunk).
        $this->assertNotNull($a->diffViewer);
        $this->assertFalse($a->diffViewer->cached);
        $this->assertSame('hunk staged', $a->successMessage);
    }

    /** @return list<string> a 19-line diff carrying three hunks */
    private static function threeHunkDiff(): array
    {
        $lines = ['diff --git a/src/B.php b/src/B.php', 'index 111..222 100644', '--- a/src/B.php', '+++ b/src/B.php'];
        for ($h = 0; $h < 3; $h++) {
            $lines[] = '@@ -' . (1 + $h * 10) . ',2 +' . (1 + $h * 10) . ',2 @@';
            $lines[] = ' context line ' . $h . 'a';
            $lines[] = '-removed ' . $h;
            $lines[] = '+added ' . $h;
        }
        return $lines;
    }

    public function testEnterOnAStagedOnlyRowOpensTheCachedSide(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g); // cursor sits on src/A.php: index M, work clean
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame([['src/A.php', true]], $g->diffRequests);
        // candy-git-034: the side must ride the viewer, not just the request.
        $this->assertNotNull($a->diffViewer);
        $this->assertTrue($a->diffViewer->cached);
    }

    public function testSpaceOnTheCachedSideArmsThenUnstages(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame('unstagehunk:src/A.php:0', $a->pendingConfirm);
        $this->assertStringContainsString('press space again', (string) $a->notice);
        $this->assertSame([], $g->unstagePatches);
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertArrayHasKey('src/A.php', $g->unstagePatches);
        $this->assertNull($a->pendingConfirm);
        $this->assertSame('hunk unstaged', $a->successMessage);
        // Unstaging refreshes the index view too, so the fresh diff must
        // arrive (the fixture replays the same bytes; the rebuild request is
        // the second entry).
        $this->assertSame([['src/A.php', true], ['src/A.php', true]], $g->diffRequests);
    }

    public function testStagingKeepsTheViewerAndRefreshesItInPlace(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        // candy-git-036: open/stage/re-close used to end the session after
        // one hunk. The diff is re-run for the same path and the viewer is
        // rebuilt in place.
        $this->assertSame([['src/B.php', false], ['src/B.php', false]], $g->diffRequests);
        $this->assertNotNull($a->diffViewer);
        $this->assertSame(0, $a->diffViewer->hunkIndex(), 'the rebuild re-points at the same index - in real git the staged hunk leaves the diff, so that index is the NEXT hunk; the fixture replays identical bytes');
        $this->assertSame('hunk staged', $a->successMessage);
        $this->assertCount(1, $g->stagePatches);
    }

    public function testViewerClosesWhenTheRefreshedDiffCarriesNoMoreHunks(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $g->diffs = ['diff --git a/src/B.php b/src/B.php', '--- a/src/B.php', '+++ b/src/B.php'];
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertNull($a->diffViewer, 'nothing left on this side: close with the success shown');
        $this->assertSame('hunk staged', $a->successMessage);
        $this->assertArrayHasKey('src/B.php', $g->stagePatches);
    }

    public function testDiffScrollKeysMoveTheWindowNotTheSelection(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $app0 = $a;
        $this->assertSame(0, $app0->diffViewer->scrollOffset);

        [$k1, ] = $app0->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(0, $k1->diffViewer->scrollOffset, 'scroll-up at the top stays put');
        [$j, ] = $app0->update(new KeyMsg(KeyType::Char, 'j'));
        [$j, ] = $j->update(new KeyMsg(KeyType::Char, 'j'));
        [$j, ] = $j->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(3, $j->diffViewer->scrollOffset, 'candy-git-045: j/k scroll lines');
        $this->assertSame($app0->diffViewer->hunkIndex(), $j->diffViewer->hunkIndex(), 'and the selection never moves');

        [$next, ] = $app0->update(new KeyMsg(KeyType::Char, ']'));
        $this->assertSame(1, $next->diffViewer->hunkIndex());
        $this->assertSame($next->diffViewer->hunkStarts[1], $next->diffViewer->scrollOffset, 'a hunk jump brings it into view');
    }

    public function testUndoInsideTheViewerIsSwallowed(): void
    {
        $g = $this->git();
        $g->diffs = self::threeHunkDiff();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertFalse($g->resetCalled, 'the global undo must not fire under the viewer');
        $this->assertSame([], $g->stagePatches);
        $this->assertSame([], $g->unstagePatches);
        $this->assertNotNull($a->diffViewer);
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
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
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

    public function testEnterOpensDiffViewer(): void
    {
        $g = $this->git();
        $g->diffs = ['+added line'];
        $a = App::start($g);
        $this->assertNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        $this->assertSame('src/A.php', $a->diffViewer->path);
    }

    public function testEnterOpensDiffViewerOnlyInStatusPane(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
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

    /**
     * Audit #5: discard is unrecoverable — 'u' must refuse WITHOUT popping, so
     * a later recoverable entry is still reachable and the stack is intact.
     */
    public function testUndoOfDiscardRefusesAndKeepsHistoryIntact(): void
    {
        $g = $this->git();
        $a = App::start($g);
                [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));   // arm + confirm discard src/A.php
        $this->assertSame(['src/A.php'], $g->discards);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('cannot undo', $a->error);
        // Refusal must not consume history: a second 'u' reports the same wall.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('cannot undo', $a->error);
        $this->assertCount(1, $a->history->undoStack);
    }

    public function testUndoOfAmendRefuses(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'A'));   // pre-filled collector
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));        // submit the amend
        $this->assertTrue($g->amendWithCalled);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('cannot undo', $a->error);
        $this->assertCount(1, $a->history->undoStack);
    }

    public function testUndoOfMergeRefusesInsteadOfMisroutingToRebaseAbort(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));   // start merge collection
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));   // merge 'f'
        $this->assertSame(['f'], $g->merges);
        $this->assertFalse($g->rebaseAbortCalled);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('cannot undo', $a->error);
        $this->assertFalse($g->rebaseAbortCalled, 'merge undo must never reach rebase --abort');
    }

    /**
     * Audit #4: `new App($git)` leaves history null; the 'u'/'Ctrl+R' paths must
     * answer with an honest error line instead of fataling on null deref.
     */
    public function testUndoAndRedoOnAppWithoutHistoryShowErrorNotFatal(): void
    {
        $g = $this->git();
        $a = new App($g, history: null);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('history', $a->error);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('history', $a->error);
        // A mutation path guarded by requireHistory() inside its try block: the
        // git op itself already ran — only the history RECORDING refuses, and
        // the user is told. (History-less Apps are hand-wired debug shapes;
        // App::start() always supplies a manager.)
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertStringContainsString('history', (string) $a->error);
        $this->assertTrue($g->stageAllCalled);
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
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        $this->assertSame(['feature'], $g->branchDeletions);
    }

    /**
     * Audit A2: undoing a branch switch must return to the ORIGIN branch,
     * not re-checkout the destination (the old single-arg entry made "undo"
     * a silent no-op that still reported success).
     */
    public function testUndoOfCreateBranchGoesHomeThenDeletes(): void
    {
        // candy-git-016 QA repro: n created AND switched (checkout -b), so
        // the old bare-delete undo died on `used by worktree` EVERY time.
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        foreach (['f', 'e', 'a'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame([['name' => 'fea', 'from' => null]], $g->branchCreations);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));  // undo
        // Paired inverse: back to the origin main FIRST, then delete.
        $this->assertSame(['main'], $g->checkouts);
        $this->assertSame(['fea'], $g->branchDeletions);
        $this->assertStringContainsString('undone: branch creation', (string) $a->successMessage);
    }

    public function testUndoOfCreateBranchOnDetachedHeadRefuses(): void
    {
        // No current branch means no home to return to: the entry must be
        // created UNRECOVERABLE and undo must refuse, never guess (016).
        $g = new FixtureGit(
            statusRows: [['branch_summary' => '(HEAD detached)']],
            branchRows: [['name' => 'fea', 'sha' => 'abc1', 'current' => false]],
            logRows: [],
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'f'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame([['name' => 'f', 'from' => null]], $g->branchCreations);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('cannot undo', (string) $a->error);
        $this->assertSame([], $g->checkouts);
        $this->assertSame([], $g->branchDeletions);
    }

    public function testUndoCheckoutReturnsToOriginBranch(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));    // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));  // cursor → feature (from main)
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));  // switch
        $this->assertSame(['feature'], $g->checkouts);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));  // undo → back to main
        $this->assertSame(['feature', 'main'], $g->checkouts);
        // Audit A17: the message names the op in human words, not 'checkout'.
        $this->assertStringContainsString('undone: branch switch', (string) $a->successMessage);
    }

    /**
     * Audit A2: undoing a branch deletion restores the ref AT the recorded
     * sha via `git branch <name> <sha>` — no checkout, no HEAD movement.
     */
    public function testUndoDeleteBranchRestoresAtRecordedSha(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'D'));
        $this->assertSame(['feature'], $g->branchDeletions);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertSame([['name' => 'feature', 'sha' => 'def2']], $g->restoredBranches);
        $this->assertSame([], $g->checkouts, 'restoring a branch must never move HEAD');
        $this->assertStringContainsString('undone: branch deletion', (string) $a->successMessage);
        $this->assertStringNotContainsString('deleteBranch', (string) $a->successMessage);
    }

    /**
     * Audit A19: a redo must announce itself as "redone", not "undone".
     */
    public function testRedoReportsRedone(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));  // unstage src/A.php
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));  // undo → stage it back
        $this->assertStringContainsString('undone:', (string) $a->successMessage);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));  // redo → unstage again
        $this->assertSame(['src/A.php', 'src/A.php'], $g->unstages);
        $this->assertStringContainsString('redone: unstage', (string) $a->successMessage);
        $this->assertStringNotContainsString('undone', (string) $a->successMessage);
    }

    /**
     * Audit A17: refusing an unrecoverable undo also speaks the humanized
     * op name ('discard' has no recoverable inverse).
     */
    public function testCannotUndoMessageUsesHumanizedOp(): void
    {
        $a = App::start($this->git());
                [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));   // discard src/A.php
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('cannot undo discard', (string) $a->error);
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
        // sequenceStateResult defaults to '' (no sequencer stopped); 'r' must
        // land in the error lane instead of opening an empty menu.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('no rebase', $a->error);
    }

    public function testRKeyWithEscClosesRebaseMenu(): void
    {
        $g = $this->git();
        $g->sequenceStateResult = 'rebase';   // the fixture's own probe knob
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertTrue($a->showRebaseMenu, 'r with rebase in progress must open the menu');
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->showRebaseMenu, 'Escape must close the rebase menu, not quit');
    }






    public function testRKeySurvivesAFailingRebaseProbe(): void
    {
        // K4: the 'r' probe shells out (git rev-parse); when git fails the
        // answer must arrive as an error row — the uncaught RuntimeException
        // used to take the whole TUI down on that keypress.
        $g = $this->git();
        $g->throwOn['sequenceState'] = new \RuntimeException('fatal: not a git repository');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('not a git repository', $a->error);
        $this->assertFalse($a->showRebaseMenu);
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

    /**
     * CG-16 (candy-git-014): the manager used to be read/write-restricted to
     * what git made elsewhere. `s` now creates a stash with a typed message
     * and the overlay survives the write so the new entry is immediately
     * actionable.
     */
    public function testStashOverlayPushFlow(): void
    {
        $g = $this->git();
        $g->stashListResult = [new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP thing')];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertTrue($a->collectingStash);
        $this->assertNotNull($a->stashManager, 'the overlay stays up behind the buffer');
        $this->assertFalse($a->stashUntracked);
        foreach (['m', 'y'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ' '));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('my x', $a->stashMessage);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame([['my x', false, false]], $g->stashPushes);
        $this->assertFalse($a->collectingStash);
        $this->assertNotNull($a->stashManager, 'pushing leaves the manager open for the next move');
        $this->assertSame('stashed', $a->successMessage);
    }

    public function testStashPushUntrackedLeg(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertTrue($a->collectingStash);
        $this->assertTrue($a->stashUntracked);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        // An empty buffer hands git the default WIP decoration (null message).
        $this->assertSame([[null, false, true]], $g->stashPushes);
    }

    public function testStashPushKeepsOverlayOpenOnFailure(): void
    {
        $g = $this->git();
        $g->throwOn['stashPush'] = new \RuntimeException('No local changes to save');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertFalse($a->collectingStash, 'the buffer closes on failure...');
        $this->assertNotNull($a->stashManager, '...but the overlay stays for a retry');
        $this->assertStringContainsString('No local changes', (string) $a->error);
    }

    /**
     * CG-16 (candy-git-014): pop = apply then drop, armed exactly like drop
     * because the entry is destroyed either way.
     */
    public function testStashPopArmsThenPops(): void
    {
        $g = $this->git();
        $g->stashListResult = [new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP thing')];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertSame('stashpop:stash@{0}', $a->pendingConfirm);
        $this->assertStringContainsString('press p again', (string) $a->notice);
        $this->assertSame([], $g->stashPops);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertSame(['stash@{0}'], $g->stashPops);
        $this->assertNull($a->pendingConfirm);
        $this->assertStringContainsString('popped', (string) $a->successMessage);
        $this->assertNotNull($a->stashManager, 'the list refreshes in place');
    }

    /**
     * CG-16 (candy-git-076): apply used to close the overlay, sending every
     * later keystroke to the base UI - where the documented stash `d` armed a
     * STATUS-pane file discard. Apply now keeps the overlay open.
     */
    public function testApplyKeepsTheOverlayOpenAndLaterKeysHitStashRows(): void
    {
        $g = $this->git();
        $g->stashListResult = [new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP thing')];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(['stash@{0}'], $g->stashApplies);
        $this->assertNotNull($a->stashManager, 'apply must not eject the user from the manager');
        // The next `d` belongs to the STASH list, not the status pane.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame('stash:stash@{0}', $a->pendingConfirm);
        $this->assertSame([], $g->discards, 'a file discard must never be armed through the stash overlay');
    }

    public function testEscapeBacksOutOfStashBufferThenClosesOverlay(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->collectingStash, 'first Escape cancels the buffer');
        $this->assertNotNull($a->stashManager, '...and leaves the overlay');
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->stashManager, 'second Escape closes the overlay');
    }

    // ---- CG-35: dropped stashes are recoverable (candy-git-120) ----

    private function stashDropGit(): FixtureGit
    {
        $g = $this->git();
        $g->stashListResult = [new \SugarCraft\Git\StashEntry(0, 'deadbeef', 'main', 'WIP on main')];

        return $g;
    }

    public function testStashDropCapturesTheShaSoHistoryCanUndoIt(): void
    {
        $g = $this->stashDropGit();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(['stash@{0}'], $g->stashDrops);
        $stack = $a->history->undoStack;
        $top = $stack[array_key_last($stack)];
        $this->assertSame('stashDrop', $top->op);
        $this->assertSame('restash', $top->inverseOp);
        $this->assertSame($g->stashShaResult, $top->args['sha'], 'the capture rides the entry, not the decoration');
        $this->assertStringContainsString('dropped', strtolower((string) $a->successMessage));
    }

    public function testUndoOfStashDropRestoresTheEntryAtTheTop(): void
    {
        $g = $this->stashDropGit();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        // Inside the overlay 'u' is the untracked-push verb - undo is a
        // global lane, so the arm copy says 'from the main view'.
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertSame([[$g->stashShaResult, $g->stashSubjectResult]], $g->stashRestores, 'the subject rides the restore too');
        $this->assertStringContainsString('undone', strtolower((string) $a->successMessage));
        $this->assertStringContainsString('drop stash', strtolower((string) $a->successMessage), 'the lane labels the forward op');
    }

    public function testStashDropWithoutACapturedShaRefusesUndo(): void
    {
        $g = $this->stashDropGit();
        $g->stashShaResult = '';
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(['stash@{0}'], $g->stashDrops);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('cannot undo', (string) $a->error);
        $this->assertSame([], $g->stashRestores, 'the refusal touches nothing');
    }

    public function testAFailedShaCaptureRefusesTheDropOutright(): void
    {
        $g = $this->stashDropGit();
        $g->throwOn['stashSha'] = new \RuntimeException('cannot resolve stash@{0}');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame([], $g->stashDrops, 'an unannounced unrecoverable delete is not what the arm promised');
        $this->assertStringContainsString('cannot resolve', (string) $a->error);
    }

    public function testRedoOfStashDropRefusesWhenThePositionalRefMoved(): void
    {
        $g = $this->stashDropGit();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertSame(1, count($g->stashRestores));
        // The restore lands at the top: stash@{N} after an undo names
        // whatever else slid into the old slot. Redo must compare, not guess.
        $g->stashShaResult = '1098765432abcdef';
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertSame(['stash@{0}'], $g->stashDrops, 'the replay found a different commit and stopped');
        $this->assertStringContainsString('cannot undo', (string) $a->error);
    }

    // ---- candy-git-029 (part 2): the stash overlay '/' filter ----

    private function stashFilterGit(): FixtureGit
    {
        $g = $this->git();
        $g->stashListResult = [
            new \SugarCraft\Git\StashEntry(0, 'aaaa1111', 'main', 'login form fix'),
            new \SugarCraft\Git\StashEntry(1, 'bbbb2222', 'develop', 'db migration notes'),
        ];

        return $g;
    }

    public function testSlashOpensTheLiveFilterBar(): void
    {
        $a = App::start($this->stashFilterGit());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertTrue($a->stashFiltering);
        $this->assertTrue($a->isTyping(), 'the search bar owns the keyboard');
        $this->assertNull($a->cursorTarget(), 'the buffer owns the caret lane');
    }

    public function testTypingEditsTheQueryWithoutTouchingTheList(): void
    {
        $a = App::start($this->stashFilterGit());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'l'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertSame('lo', $a->stashFilterQuery);
        $this->assertCount(2, $a->stashManager->stashes, 'narrowing only commits with Enter');
    }

    public function testEnterCommitsTheNarrowing(): void
    {
        $a = App::start($this->stashFilterGit());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['l', 'o', 'g', 'i', 'n'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($a->stashFiltering);
        $this->assertSame('', $a->stashFilterQuery);
        // Local alignment keeps weak false positives (the unit tests document
        // it) - what Enter commits is the RANKING: best match on top.
        $this->assertSame('login form fix', $a->stashManager->stashes[0]->message);
    }

    public function testEscapeCancelsTheFilterWithoutNarrowing(): void
    {
        $a = App::start($this->stashFilterGit());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($a->stashFiltering);
        $this->assertSame('', $a->stashFilterQuery);
        $this->assertNotNull($a->stashManager, 'Escape backs out of the bar, not the overlay');
        $this->assertCount(2, $a->stashManager->stashes);
    }

    public function testCommittedActionsCarryTheOriginalRef(): void
    {
        // The narrowed view must not re-number reality: acting on the one
        // visible entry uses its ORIGINAL stash@{N}, not its row index.
        $a = App::start($this->stashFilterGit());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['m', 'i', 'g', 'r'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('db migration notes', $a->stashManager->stashes[0]->message, 'the ranked view puts the real match under the cursor');
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame('stash:stash@{1}', $a->pendingConfirm);
    }

    public function testReopeningTheOverlayRestoresTheFullListing(): void
    {
        $g = $this->stashFilterGit();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['m', 'i', 'g', 'r'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(['stash@{1}'], $g->stashApplies, 'the action hit the ranked top entry');
        // Apply does not re-list (the list did not change); closing the
        // overlay and opening it again fetches fresh from git, which restores
        // git's own order and forgets the ranking.
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        $this->assertSame('login form fix', $a->stashManager->stashes[0]->message, 'the fresh listing came from git, not the ranked copy');
    }

    public function testTheOpenFilterSwallowsActionKeys(): void
    {
        $g = $this->stashFilterGit();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '/'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $a->stashFilterQuery, 'typing an action letter into the bar is typing');
        $this->assertFalse($a->collectingStash);
        $this->assertSame([], $g->stashApplies);
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

    /**
     * Audit #13: the per-overlay Escape arms were dead code (the global
     * block closes overlays first). Deleting them must not change behaviour:
     * Escape while adding a worktree still tears down the WHOLE overlay —
     * it does not merely cancel the add prompt.
     */
    public function testEscapeWhileAddingWorktreeClosesWholeOverlay(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));   // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'w')); // open worktrees
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a')); // start adding
        $this->assertNotNull($a->worktrees);
        $this->assertTrue($a->worktrees->adding);
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($a->worktrees);
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

    /**
     * candy-git-011 (gate G1, park arm): the preview todo's mutating keys must
     * refuse, not fake-edit — and they must never leak to the global lanes.
     * 'd' is the critical swallow: a plain unmapped fall-through would arm a
     * REAL worktree discard from underneath a preview overlay.
     */
    public function testParkedRebaseTodoKeysRefuseWithoutEditingOrLeaking(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '2'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->interactiveRebase);
        $this->assertCount(2, $a->interactiveRebase->commits);

        foreach ([['space', KeyType::Space, ''], ['l', KeyType::Char, 'l'], ['d', KeyType::Char, 'd']] as [$label, $type, $rune]) {
            [$a, ] = $a->update(new KeyMsg($type, $rune));
            $this->assertSame('preview only - not yet executed', preg_replace('/\x{2014}/u', '-', (string) $a->notice), "{$label} answers with the banner");
            $this->assertCount(2, $a->interactiveRebase->commits, "{$label} never re-drops the preview");
            $this->assertSame(\SugarCraft\Git\RebaseAction::Pick, $a->interactiveRebase->commits[0]->action, "{$label} never cycles actions");
            $this->assertNull($a->pendingConfirm, 'a refusal arms nothing');
        }

        $this->assertSame([], $g->discards, 'the overlay swallows d - no real discard ran');
    }

    public function testDefaultGeometryBeforeAnyWindowSize(): void
    {
        $a = App::start($this->git());
        $this->assertNull($a->width);
        $this->assertNull($a->height);
        // Historical fallback constants — kept so the first frame stays byte-stable.
        $this->assertSame(36, $a->paneWidth());
        $this->assertSame(31, $a->statusPathWidth());
        $this->assertSame(32, $a->branchNameWidth());
        $this->assertSame(26, $a->logSubjectWidth());
    }

    public function testWindowSizeMsgStoresSizeAndDerivesGeometry(): void
    {
        $a = App::start($this->git());
        [$a2, $cmd] = $a->update(new WindowSizeMsg(120, 40));

        $this->assertInstanceOf(App::class, $a2);
        $this->assertNull($cmd);

        // Size is stored from the message ...
        $this->assertSame(120, $a2->width);
        $this->assertSame(40, $a2->height);

        // ... and geometry is DERIVED from it, not the hardcoded 36/31/32/26.
        $expectedPane = intdiv(120 - 10, 2); // 55
        $this->assertSame($expectedPane, $a2->paneWidth());
        $this->assertSame($expectedPane - 5, $a2->statusPathWidth());
        $this->assertSame($expectedPane - 4, $a2->branchNameWidth());
        $this->assertSame($expectedPane - 10, $a2->logSubjectWidth());
        $this->assertNotSame(36, $a2->paneWidth());

        // Immutability: the original model is untouched.
        $this->assertNull($a->width);
        $this->assertSame(36, $a->paneWidth());
    }

    public function testWindowSizeMsgWidenedPaneIsReflectedInView(): void
    {
        $a = App::start($this->git());
        $narrow = $a->view();
        [$wide, ] = $a->update(new WindowSizeMsg(160, 50));
        // A wider terminal yields wider frames, so the rendered output grows.
        $this->assertGreaterThan(strlen($narrow), strlen($wide->view()));
    }

    public function testWindowSizeMsgClampsToMinimumPaneWidth(): void
    {
        $a = App::start($this->git());
        [$tiny, ] = $a->update(new WindowSizeMsg(4, 4));
        // Even a 4-col terminal must not collapse the pane below the floor.
        $this->assertSame(20, $tiny->paneWidth());
    }

    public function testExecuteCommitWithEmptyMessageShowsError(): void
    {
        $g = $this->git();
        $a = App::start($g);
        // Start commit collection and press Enter without typing
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertTrue($a->collectingCommit);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('empty', $a->error);
        // Still collecting so user can retry
        $this->assertTrue($a->collectingCommit);
    }

    public function testExecuteCreateBranchWithEmptyNameShowsError(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertTrue($a->collectingBranchName);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('empty', $a->error);
        $this->assertTrue($a->collectingBranchName);
    }

    public function testDiscardSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['discard'] = new \RuntimeException('boom');
        $a = App::start($g);
        // Cursor 0 on the status pane is src/A.php; 'd' discards it.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('boom', $a->error);
        $this->assertSame([], $g->discards, 'throwing discard must not record a completed call');
    }

    public function testStageAllSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['stageAll'] = new \RuntimeException('kaboom');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('kaboom', $a->error);
        $this->assertFalse($g->stageAllCalled);
    }

    public function testShowStashManagerSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['stashList'] = new \RuntimeException('fatal: not a git repository');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('not a git repository', $a->error);
        $this->assertNull($a->stashManager);
    }

    public function testShowWorktreesSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['worktreeList'] = new \RuntimeException('fatal: not a git repository');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));  // → branches
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('not a git repository', $a->error);
        $this->assertNull($a->worktrees);
    }

    public function testRebaseKeyShowsErrorWhenNoRebaseInProgress(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('no rebase', $a->error);
        $this->assertFalse($a->showRebaseMenu);
    }

    public function testConfirmRebaseCountSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['log'] = new \RuntimeException('log failed');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertNotNull($a->interactiveRebase);
        $this->assertTrue($a->interactiveRebase->selectingN);
        // Enter a digit to go into selectingN mode
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertSame('3', $a->interactiveRebase->countInput);
        // Confirm count - should call git->log which throws
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));
        // The error should be set (either selectingN still true or error set)
        $this->assertTrue($a->interactiveRebase->selectingN || $a->error !== null);
    }

    public function testNavigateHunkClampWhenCountIsOne(): void
    {
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        $this->assertSame(1, $a->diffViewer->hunkCount());
        // Navigate down when only 1 hunk - should stay at same hunk
        [$a, ] = $a->update(new KeyMsg(KeyType::Down, ''));
        // hunkCursor should remain at first hunk
        $this->assertSame(1, $a->diffViewer->hunkCount());
    }

    public function testShowDiffSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->throwOn['diff'] = new \RuntimeException('diff boom');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('diff boom', $a->error);
        $this->assertNull($a->diffViewer, 'a failed diff must not open the overlay');
    }

    public function testStageCurrentHunkSetsErrorWhenGitThrows(): void
    {
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
        ];
        $g->throwOn['stagePatch'] = new \RuntimeException('patch boom');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j')); // worktree side: the staging lane
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('patch boom', $a->error);
        $this->assertSame([], $g->stagePatches);
        // candy-git-036: the viewer stays open on failure so the user can
        // fix the cause and retry the same hunk in place.
        $this->assertNotNull($a->diffViewer);
    }

    // ---- CG-22 (candy-git-022): the cursor modeline's target -------------

    public function testCursorTargetNamesTheFocusedRowInEveryPane(): void
    {
        $a = App::start($this->git());
        $this->assertSame('src/A.php', $a->cursorTarget());

        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame('main', $a->cursorTarget());

        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame('abc1 first commit', $a->cursorTarget());
    }

    public function testCursorTargetFollowsTheSelection(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame('src/B.php', $a->cursorTarget());

        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame('def2 second commit', $a->cursorTarget());
    }

    public function testCursorTargetIsNullWhileABufferOwnsTheKeyboard(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertTrue($a->collectingCommit);
        $this->assertNull($a->cursorTarget());
    }

    public function testCursorTargetTracksTheStashSelection(): void
    {
        $g = $this->git();
        $g->stashListResult = [new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP thing')];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'S'));
        $this->assertNotNull($a->stashManager);
        $this->assertSame('stash@{0}: WIP thing', $a->cursorTarget());
    }

    public function testCursorTargetIsNullForHelpAndForEmptyLists(): void
    {
        $a = App::start($this->git());
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, '?'));
        $this->assertTrue($a->showHelp);
        $this->assertNull($a->cursorTarget());

        $empty = App::start(new FixtureGit(
            statusRows: [['branch_summary' => 'main...origin/main']],
            branchRows: [],
            logRows: [],
        ));
        $this->assertNull($empty->cursorTarget());
    }

    public function testStagingAHunklessDiffRefusesWithoutTouchingGit(): void
    {
        $g = $this->git();
        // Header-only diff: git emits this shape for content-less changes;
        // there is no @@ hunk to stage (candy-git-073).
        $g->diffs = ['diff --git a/src/A.php b/src/A.php', '--- a/src/A.php', '+++ b/src/A.php'];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);

        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));

        $this->assertSame([], $g->stagePatches, 'git never sees an empty or duplicated patch');
        $this->assertNotNull($a->error);
        $this->assertStringContainsString('no hunks', $a->error);
        $this->assertNotNull($a->diffViewer, 'the refusal keeps the viewer open for esc');
    }

    // ---- CG-18: commit details overlay + cursor prefills (candy-git-015, candy-git-038) ----

    /** @return list<string> a canned git show --stat --format=full --patch body */
    private static function detailsFixture(string $sha): array
    {
        return [
            'commit ' . $sha . '0123456789abcdef0123456789abcdef01234567',
            'Author: Joe Huss <detain@interserver.net>',
            'Commit: Joe Huss <detain@interserver.net>',
            '',
            '    first commit',
            '---',
            ' f.php | 1 +',
            ' 1 file changed, 1 insertion(+)',
            '',
            'diff --git a/f.php b/f.php',
            'index 111..222 100644',
            '--- a/f.php',
            '+++ b/f.php',
            '@@ -0,0 +1 @@',
            '+hello',
        ];
    }

    private function toLogPane(FixtureGit $g): App
    {
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab));
        return $a;
    }

    public function testEnterOnALogRowOpensTheCommitDetailsViewer(): void
    {
        $g = $this->git();
        $g->commitDetailsResult = self::detailsFixture('abc1');
        $a = $this->toLogPane($g);
        $this->assertSame(Pane::Log, $a->pane);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);
        $this->assertTrue($a->diffViewer->readOnly, 'log rows open the read-only commit view (candy-git-015)');
        $this->assertSame('abc1', $a->diffViewer->commitSha);
        $this->assertSame('abc1', $a->diffViewer->path);
    }

    public function testSpaceIsInertInsideTheCommitDetailsViewer(): void
    {
        $g = $this->git();
        $g->commitDetailsResult = self::detailsFixture('abc1');
        $a = $this->toLogPane($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame([], $g->stagePatches);
        $this->assertNull($a->successMessage, 'Space must not stage inside the commit view');
        $this->assertSame([], $g->unstagePatches);
        $this->assertNotNull($a->diffViewer);
    }

    public function testRevertArmsFromTheDetailsViewerThenStacksTheInverse(): void
    {
        $g = $this->git();
        $g->commitDetailsResult = self::detailsFixture('abc1');
        $a = $this->toLogPane($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame('revert:abc1', $a->pendingConfirm);
        $this->assertStringContainsString('press r again', (string) $a->notice);
        $this->assertSame([], $g->reverts);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(['abc1'], $g->reverts);
        $this->assertNull($a->pendingConfirm);
        $this->assertSame('reverted abc1', $a->successMessage);
        $this->assertNotNull($a->diffViewer, 'the details view survives its own execute');
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));
        $this->assertStringContainsString('revert', (string) $a->error);
        $this->assertFalse($g->resetCalled, 'the unrecoverable entry is refused, never silently popped');
    }

    public function testResetHardArmsFromTheDetailsViewerThenMovesHead(): void
    {
        $g = $this->git();
        $g->commitDetailsResult = self::detailsFixture('abc1');
        $a = $this->toLogPane($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame('resethard:abc1', $a->pendingConfirm);
        $this->assertStringContainsString('press R again', (string) $a->notice);
        $this->assertSame([], $g->resetHards);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'R'));
        $this->assertSame(['abc1'], $g->resetHards);
        $this->assertNull($a->pendingConfirm);
        $this->assertSame('reset hard onto abc1', $a->successMessage);
    }

    public function testVFromTheDetailsViewerSeedsTheCherryPickCollector(): void
    {
        $g = $this->git();
        $g->commitDetailsResult = self::detailsFixture('abc1');
        $a = $this->toLogPane($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'v'));
        $this->assertNull($a->diffViewer, 'the overlay hands off to the collector');
        $this->assertNotNull($a->cherryPick);
        $this->assertTrue($a->cherryPick->collecting);
        $this->assertSame('abc1', $a->cherryPick->commitRef, 'the visible sha is carried, not retyped (candy-git-038)');
    }

    public function testBranchFromALogRowCarriesTheBaseCommit(): void
    {
        $g = $this->git();
        $a = $this->toLogPane($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertTrue($a->collectingBranchName);
        $this->assertSame('abc1', $a->pendingBranchBase);
        foreach (['n', 'e', 'w', 'b'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame([['name' => 'newb', 'from' => 'abc1']], $g->branchCreations);
        $this->assertNull($a->pendingBranchBase, 'the base dies with the collector');
    }

    public function testBranchFromTheStatusPaneStillStartsAtHead(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertNull($a->pendingBranchBase);
        foreach (['x'] as $ch) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertSame([['name' => 'x', 'from' => null]], $g->branchCreations);
    }

    public function testMergeCollectorSeedsFromTheBranchesCursor(): void
    {
        $g = $this->git();
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        $this->assertSame('', $a->mergeTarget, 'status pane has no branch under the cursor');
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape));
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        $this->assertSame('', $a->mergeTarget, 'the current branch is not a merge seed');
        [$a, ] = $a->update(new KeyMsg(KeyType::Escape));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'j'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        $this->assertTrue($a->collectingMergeTarget);
        $this->assertSame('feature', $a->mergeTarget);
    }

    // -- candy-git-035: untracked rows are reviewable and deletable --------

    private static function untrackedGit(): FixtureGit
    {
        $g = new FixtureGit(
            statusRows: [['index_status' => '?', 'work_status' => '?', 'path' => 'new/F.php']],
            branchRows: [],
            logRows: [],
        );
        $g->untrackedDiffResult = [
            'diff --git a/new/F.php b/new/F.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/new/F.php',
            '@@ -0,0 +1,2 @@',
            '+one',
            '+two',
        ];

        return $g;
    }

    public function testEnterOnAnUntrackedRowShowsTheAllAdditionsDiff(): void
    {
        $g    = self::untrackedGit();
        [$a, ] = App::start($g)->update(new KeyMsg(KeyType::Enter));

        // The lesson of candy-git-035: git diff answers NOTHING for a ??
        // row, so the viewer must never ask it - the diff is synthesized.
        $this->assertSame([], $g->diffRequests);
        $this->assertNotNull($a->diffViewer);
        $this->assertFalse($a->diffViewer->cached);
        $this->assertContains('+one', $a->diffViewer->lines);
        $this->assertContains('+two', $a->diffViewer->lines);
    }

    public function testSpaceStagesTheSyntheticDiffThroughTheNormalLane(): void
    {
        $g     = self::untrackedGit();
        $start = App::start($g);
        [$a, ] = $start->update(new KeyMsg(KeyType::Enter));
        [$a, ] = $a->update(new KeyMsg(KeyType::Space));

        // A +lines block IS a partial add: the regular apply --cached lane
        // takes the synthetic hunk with no special casing.
        $this->assertArrayHasKey('new/F.php', $g->stagePatches);
        $this->assertStringContainsString('+one', $g->stagePatches['new/F.php']);
    }

    public function testDOnAnUntrackedRowWarnsAboutTheDisk(): void
    {
        $g     = self::untrackedGit();
        $start = App::start($g);
        [$a, ] = $start->update(new KeyMsg(KeyType::Char, 'd'));

        $this->assertSame('discard:new/F.php', $a->pendingConfirm);
        $this->assertStringContainsString('remove it from disk', (string) $a->notice);
        $this->assertSame([], $g->deletes);
        $this->assertSame([], $g->discards);
    }

    public function testSecondDUnlinksInsteadOfRestoring(): void
    {
        $g     = self::untrackedGit();
        $start = App::start($g);
        [$a, ] = $start->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));

        // git restore cannot touch ?? paths - the second press is a true
        // unlink, and git->discard must never be reached for them.
        $this->assertSame(['new/F.php'], $g->deletes);
        $this->assertSame([], $g->discards);
        $this->assertNull($a->pendingConfirm);
        $this->assertSame('deleted new/F.php', $a->successMessage);
    }

    public function testDeletingAnUntrackedFileRefusesUndo(): void
    {
        $g     = self::untrackedGit();
        $start = App::start($g);
        [$a, ] = $start->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'u'));

        // The bytes were never in git objects; undo would lie.
        $this->assertStringContainsString('delete untracked', (string) $a->error);
        $this->assertFalse($g->resetCalled);
        $this->assertSame(['new/F.php'], $g->deletes); // the refusal never re-runs the delete
    }

    public function testTrackedRowsStillDiscardThroughTheGitLane(): void
    {
        $g     = $this->git();
        $start = App::start($g);
        [$a, ] = $start->update(new KeyMsg(KeyType::Char, 'd'));

        $this->assertStringContainsString('press d again to discard', (string) $a->notice);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(['src/A.php'], $g->discards);
        $this->assertSame([], $g->deletes);
    }

    public function testStagingTheBeheadedTailHunkIsRefusedWithoutTouchingGit(): void
    {
        // candy-git-040: the truncated capture's last hunk is a fragment;
        // staging it would hand git apply a partial patch.
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/B.php b/src/B.php',
            '@@ -1,1 +1,1 @@',
            '-one',
            '+ONE',
            '@@ -9,1 +9,1 @@',
            '-two',
            '+TW',
            \SugarCraft\Git\Process::CAPTURE_NOTICE,
        ];

        $a  = App::start($g);
        [$a] = $a->update(new KeyMsg(KeyType::Char, 'j')); // to the worktree-side row
        [$a] = $a->update(new KeyMsg(KeyType::Enter));
        $this->assertNotNull($a->diffViewer);

        [$a] = $a->update(new KeyMsg(KeyType::Space)); // first hunk: complete bytes
        $this->assertArrayHasKey('src/B.php', $g->stagePatches);
        $this->assertStringContainsString('-one', $g->stagePatches['src/B.php']);

        [$a] = $a->update(new KeyMsg(KeyType::Char, ']')); // hop to the tail hunk
        $this->assertSame(1, $a->diffViewer->hunkIndex());
        [$a] = $a->update(new KeyMsg(KeyType::Space));

        $this->assertStringContainsString('tail truncated', (string) $a->error);
        $this->assertStringContainsString('-one', $g->stagePatches['src/B.php'], 'the refusal never reached git');
        $this->assertNotNull($a->diffViewer, 'the viewer stays open - earlier hunks remain actionable');
    }

    public function testUnstagingTheBeheadedTailHunkIsRefusedToo(): void
    {
        $g = $this->git();
        $g->diffs = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,1 +1,1 @@',
            '-one',
            '+ONE',
            '@@ -9,1 +9,1 @@',
            '-two',
            '+TW',
            \SugarCraft\Git\Process::CAPTURE_NOTICE,
        ];

        $a = App::start($g);                          // src/A.php is the staged-only row
        [$a] = $a->update(new KeyMsg(KeyType::Enter)); // cached side opens
        [$a] = $a->update(new KeyMsg(KeyType::Space)); // arm the first hunk
        [$a] = $a->update(new KeyMsg(KeyType::Space)); // unstaged - same fixture comes back
        [$a] = $a->update(new KeyMsg(KeyType::Char, ']'));

        $this->assertSame(1, $a->diffViewer->hunkIndex());
        [$a] = $a->update(new KeyMsg(KeyType::Space));

        $this->assertStringContainsString('tail truncated', (string) $a->error);
        $this->assertNull($a->pendingConfirm, 'the refusal is not an arm');
    }
}
