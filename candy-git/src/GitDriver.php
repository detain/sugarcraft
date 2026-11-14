<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Pluggable git backend. Production is {@see Git} (shells out to the
 * `git` binary). Tests inject a fixture closure-driven implementation
 * so transition correctness can be asserted without staging real
 * repos in tmp dirs.
 *
 * @deprecated All methods are synchronous-only. Async git operations are
 * planned for v2; the off-loop transport is still to be chosen. The shipped
 * Process layer is deliberately a bounded proc_open drain loop.
 */
interface GitDriver
{
    /**
     * Parse `git status --porcelain=v1 -b`.
     *
     * For renamed/copied entries (R/C status) `path` is the destination
     * (the working path) and `orig_path` carries the source, so callers can
     * drive rename-aware operations without re-parsing the "old -> new" field.
     *
     * @return list<array{
     *     branch_summary?: string,
     *     index_status?:   string,
     *     work_status?:    string,
     *     path?:           string,
     *     orig_path?:      string,
     * }>
     */
    public function status(): array;

    /**
     * @return list<array{name: string, sha: string, current: bool, remote: bool, ahead: ?int, behind: ?int, gone: bool}>
     */
    public function branches(): array;

    /**
     * @return list<array{sha: string, subject: string, author: string, ago: string}>
     */
    public function log(int $limit = 25): array;

    public function stage(string $path): void;

    public function unstage(string $path): void;

    public function checkout(string $branch): void;

    /**
     * Check out a remote-tracking branch (`origin/feature`) as a local branch
     * tracking it — the DWIM leg of the branches-pane Space key (candy-git-037).
     */
    public function checkoutRemote(string $remoteBranch): void;

    public function commit(string $message): void;

    public function stageAll(): void;

    /**
     * @param bool $cached show the staged side (git diff --cached) for files
     *                     whose worktree copy already matches the index
     * @return list<string> unified diff lines for a file (no color)
     */
    public function diff(string $path, bool $cached = false): array;

    /** Discard working-tree changes for a file (git restore --worktree <path>). */
    public function discard(string $path): void;

    /**
     * The all-additions diff of an untracked path (candy-git-035: a
     * plain diff is empty for ?? rows, so the review action opened a
     * blank box). Read from the file, never spawned.
     *
     * @return list<string>
     */
    public function untrackedDiff(string $path): array;

    /**
     * Remove an untracked file from disk - the armed d on a ?? row.
     * git restore cannot touch untracked paths, so this is a true,
     * unrecoverable unlink (candy-git-035).
     */
    public function deleteUntracked(string $path): void;

    /** Amend the last commit without changing its message (--no-edit). */
    public function amend(): void;

    /**
     * Rewrite the last commit with a new message (candy-git-013: the
     * pre-filled amend collector submits through here).
     */
    public function amendWith(string $message): void;

    /**
     * The last commit's raw message (body included), used to pre-fill the
     * amend collector. Throws when there is no HEAD yet.
     */
    public function lastCommitMessage(): string;

    /**
     * Stage a single hunk via git apply --cached.
     * @param string $hunk The hunk patch text (unified diff format)
     */
    public function stagePatch(string $path, string $hunk): void;

    /** Unstage all staged changes (inverse of stageAll). */
    public function unstageAll(): void;

    /**
     * Unstage a single hunk by applying the patch in reverse.
     * @param string $hunk The hunk patch text (unified diff format)
     */
    public function unstagePatch(string $path, string $hunk): void;

    /**
     * Create and switch to a new branch.
     * @param string|null $from start point (candy-git-038: `n` from the log
     * pane branches from the selected commit); null/empty keeps the HEAD default
     */
    public function createBranch(string $name, ?string $from = null): void;

    /**
     * Full commit display — headers, message, stat summary and patch.
     * Feeds the log pane's read-only details overlay (candy-git-015).
     * @return list<string>
     */
    public function commitDetails(string $ref): array;

    /** Invert a commit onto HEAD as a new commit (`revert --no-edit`). */
    public function revert(string $ref): void;

    /**
     * Move HEAD to a ref and overwrite the worktree (`reset --hard`).
     * Destructive by definition — the UI arms it.
     */
    public function resetHard(string $ref): void;

    /** Delete a branch (safe, -d flag). */
    public function deleteBranch(string $name): void;

    /** Restore a deleted branch pointing at a previously recorded sha. */
    public function restoreBranch(string $name, string $sha): void;

    /** Merge a branch into the current branch. */
    public function merge(string $branch): void;

    /** Abandon a stopped (conflicted) merge, restoring the pre-merge state. */
    public function mergeAbort(): void;

    /** Commit the resolution of a conflicted merge (continue leg). */
    public function mergeContinue(): void;

    /** Fetch from the default remote: updates tracking refs, never the worktree. */
    public function fetch(): void;

    /** Pull the tracked remote branch into the current one (merge back-end, non-interactive). */
    public function pull(): void;

    /**
     * Push the current branch. A branch without an upstream goes to
     * `origin` with `--set-upstream` recorded; a detached HEAD has no
     * branch to push and throws.
     *
     * @throws \RuntimeException on detached HEAD or a failing git push
     */
    public function push(): void;

    /** Continue an in-progress rebase. */
    public function rebaseContinue(): void;

    /** Abort an in-progress rebase. */
    public function rebaseAbort(): void;

    /** Skip the current rebase commit. */
    public function rebaseSkip(): void;

    /** Reset HEAD to the parent of the last commit (undo last commit, keep changes staged). */
    public function reset(): void;

    /**
     * List stashes (parse `git stash list`).
     * @return list<StashEntry>
     */
    public function stashList(): array;

    /** Apply a stash by its ref (e.g. "stash@{0}"). */
    public function stashApply(string $stashRef): void;

    /** Drop (delete) a stash by its ref. */
    public function stashDrop(string $stashRef): void;

    /**
     * Create a stash from the current worktree/index (git stash push).
     *
     * A null or empty message lets git decorate the entry itself ("WIP on
     * <branch>: ..."); $includeUntracked surfaces the -u leg so untracked
     * files ride into the stash too, and $keepIndex leaves the staged state
     * in place (the full lazygit stash-keeping flow).
     *
     * Mirrors jesseduffield/lazygit handlers.StashChanges.
     */
    public function stashPush(?string $message, bool $keepIndex = false, bool $includeUntracked = false): void;

    /**
     * Pop a stash: apply then drop (candy-git-014). Composed from the two
     * guarded verbs rather than spawning `git stash pop` — a conflicted
     * apply throws before the drop, so the entry survives for a retry,
     * and the merge-capable dispatch guard already verifies the `apply`
     * shape without needing a new argv lane in its tables.
     */
    public function stashPop(string $stashRef): void;

    /** Cherry-pick a commit. */
    public function cherryPick(string $commit): void;

    /** Continue an in-progress cherry-pick. */
    public function cherryPickContinue(): void;

    /** Abort an in-progress cherry-pick. */
    public function cherryPickAbort(): void;

    /**
     * List worktrees (parse `git worktree list --porcelain`).
     * @return list<WorktreeEntry>
     */
    public function worktreeList(): array;

    /**
     * Add a new worktree at $path. A non-empty $branch is CREATED from HEAD
     * (`-b`); an empty one lets git name the branch from the path basename.
     * Neither leg detaches (CG-24).
     */
    public function worktreeAdd(string $path, string $branch): void;

    /** Remove an existing worktree at $path. */
    public function worktreeRemove(string $path): void;

    /** Sequencer stop state: 'rebase' | 'cherry' | 'merge' | '' (none). */
    public function sequenceState(): string;
}
