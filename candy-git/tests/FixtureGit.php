<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\GitDriver;

/**
 * Scriptable GitDriver double: canned status/branch/log rows, a record of
 * every mutating call, and per-method injected failures via `throwOn`.
 *
 * Lives in its own autoloadable file because AppTest and a dozen other test
 * files use it. Declared inside AppTest.php it resolved elsewhere only when
 * AppTest.php had already been loaded: always under serial phpunit, which
 * loads every test file before running any, and not in a ParaTest worker,
 * which loads one file per dispatch ("Class not found").
 */
final class FixtureGit implements GitDriver
{
    public array $statusRows;
    public array $branchRows;
    public array $logRows;
    /** @var list<int> limits the App requested from log() (candy-git-100) */
    public array $logLimits = [];
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
        $this->logLimits[] = $limit;

        return array_slice($this->logRows, 0, $limit);
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
    public bool $commitGpgSignsReturn = false;
    public function commitGpgSigns(): bool { return $this->commitGpgSignsReturn; }
    public array $conflictedMarkersReturn = [];
    public int $conflictedMarkersCalls = 0;
    public bool $localHooksReturn = false;
    public int $localHooksCalls = 0;
    public function conflictedPathsCarryingMarkers(): array { $this->conflictedMarkersCalls++; return $this->conflictedMarkersReturn; }
    public function localHooksPresent(): bool { $this->localHooksCalls++; return $this->localHooksReturn; }
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
