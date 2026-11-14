<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests\Support;

use SugarCraft\Git\GitDriver;

/**
 * One inert GitDriver for all tests (candy-git-116).
 *
 * RendererTest carried three near-identical ~40-line inline
 * `new class implements GitDriver {...}` copies next to AppTest's FixtureGit;
 * every interface growth (CG-41's commitGpgSigns, the remote trio, stash
 * verbs...) had to be hand-rolled into each copy, and behaviour decisions
 * re-made per copy go stale silently — AppTest's throwOn machinery exists
 * because exactly that happened once. NullGit answers every verb inertly
 * once; a test overrides ONLY the methods its scenario names:
 *
 *     $g = new class extends NullGit {
 *         public function status(): array { throw new \RuntimeException('boom'); }
 *     };
 *
 * When GitDriver grows a method, this class is the single place to update.
 */
class NullGit implements GitDriver
{
    public function status(): array
    {
        return [];
    }

    public function branches(): array
    {
        return [];
    }

    public function log(int $limit = 25): array
    {
        return [];
    }

    public function stage(string $path): void
    {
    }

    public function unstage(string $path): void
    {
    }

    public function checkout(string $branch): void
    {
    }

    public function checkoutRemote(string $remoteBranch): void
    {
    }

    public function commit(string $message): void
    {
    }

    public function stageAll(): void
    {
    }

    public function diff(string $path, bool $cached = false): array
    {
        return [];
    }

    public function discard(string $path): void
    {
    }

    public function untrackedDiff(string $path): array
    {
        return [];
    }

    public function deleteUntracked(string $path): void
    {
    }

    public function amend(): void
    {
    }

    public function amendWith(string $message): void
    {
    }

    public function lastCommitMessage(): string
    {
        return '';
    }

    public function commitGpgSigns(): bool
    {
        return false;
    }

    public function conflictedPathsCarryingMarkers(): array
    {
        return [];
    }

    public function localHooksPresent(): bool
    {
        return false;
    }

    public function stagePatch(string $path, string $hunk): void
    {
    }

    public function unstageAll(): void
    {
    }

    public function unstagePatch(string $path, string $hunk): void
    {
    }

    public function createBranch(string $name, ?string $from = null): void
    {
    }

    public function commitDetails(string $ref): array
    {
        return [];
    }

    public function revert(string $ref): void
    {
    }

    public function resetHard(string $ref): void
    {
    }

    public function deleteBranch(string $name): void
    {
    }

    public function restoreBranch(string $name, string $sha): void
    {
    }

    public function merge(string $branch): void
    {
    }

    public function mergeAbort(): void
    {
    }

    public function mergeContinue(): void
    {
    }

    public function fetch(): void
    {
    }

    public function pull(): void
    {
    }

    public function push(): void
    {
    }

    public function rebaseContinue(): void
    {
    }

    public function rebaseAbort(): void
    {
    }

    public function rebaseSkip(): void
    {
    }

    public function reset(): void
    {
    }

    public function stashList(): array
    {
        return [];
    }

    public function stashApply(string $stashRef): void
    {
    }

    public function stashDrop(string $stashRef): void
    {
    }

    public function stashPush(?string $message, bool $keepIndex = false, bool $includeUntracked = false): void
    {
    }

    public function stashPop(string $stashRef): void
    {
    }

    public function stashSha(string $stashRef): string
    {
        return '';
    }

    public function stashSubject(string $stashRef): string
    {
        return '';
    }

    public function stashRestore(string $sha, string $subject = ''): void
    {
    }

    public function cherryPick(string $commit): void
    {
    }

    public function cherryPickContinue(): void
    {
    }

    public function cherryPickAbort(): void
    {
    }

    public function worktreeList(): array
    {
        return [];
    }

    public function worktreeAdd(string $path, string $branch): void
    {
    }

    public function worktreeRemove(string $path): void
    {
    }

    public function sequenceState(): string
    {
        return '';
    }
}
