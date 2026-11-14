<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Immutable value object representing a single entry in the undo/redo history.
 *
 * @param string       $op          Human-readable operation name (e.g. 'stage', 'commit')
 * @param array       $args        Arguments passed to the operation
 * @param string      $inverseOp   The operation name that undoes this (e.g. 'unstage', 'reset')
 * @param array       $inverseArgs Arguments for the inverse operation
 */
final readonly class HistoryEntry
{
    /**
     * Sentinel inverse op meaning "this operation cannot be honestly undone".
     * executeUndo refuses these BEFORE popping, so history stays consistent;
     * a matching arm in App::applyHistoryEntry fails loud as defense in depth.
     */
    public const UNRECOVERABLE_INVERSE = 'refuse';

    public function __construct(
        public string $op,
        public array $args,
        public string $inverseOp,
        public array $inverseArgs,
    ) {}

    /**
     * Convenience factory for stage operations.
     */
    public static function stage(string $path): self
    {
        return new self('stage', ['path' => $path], 'unstage', ['path' => $path]);
    }

    /**
     * Convenience factory for unstage operations.
     */
    public static function unstage(string $path): self
    {
        return new self('unstage', ['path' => $path], 'stage', ['path' => $path]);
    }

    /**
     * Convenience factory for discard operations.
     *
     * UNRECOVERABLE by design: `git restore --worktree` destroys uncommitted
     * bytes that no later command can recreate — the old 'stage' inverse only
     * re-staged a now-empty diff and the UI claimed "undone: discard".
     */
    public static function discard(string $path): self
    {
        return new self('discard', ['path' => $path], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Deleting an untracked file destroyed bytes no git object ever
     * held - the inverse would need a backup that does not exist
     * (candy-git-035), so it joins the refuse-undo lane with discard.
     */
    public static function deleteUntracked(string $path): self
    {
        return new self('deleteUntracked', ['path' => $path], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Convenience factory for checkout (branch switch) operations.
     *
     * Records the ORIGIN branch so the inverse returns there: the old
     * one-argument shape stored only the destination and its "inverse"
     * re-checked-out the same branch — a no-op that reported "undone"
     * (audit A2). When no origin is known (detached HEAD, branch list
     * empty), the entry refuses undo rather than guessing.
     */
    public static function checkout(string $from, string $to): self
    {
        if ($from === '') {
            return new self('checkout', ['branch' => $to, 'from' => ''], self::UNRECOVERABLE_INVERSE, []);
        }

        return new self(
            'checkout',
            ['branch' => $to, 'from' => $from],
            'checkout',
            ['branch' => $from, 'from' => $to],
        );
    }

    /**
     * Convenience factory for commit operations.
     */
    public static function commit(string $message): self
    {
        return new self('commit', ['message' => $message], 'reset', ['message' => $message]);
    }

    /**
     * Convenience factory for amend operations.
     *
     * UNRECOVERABLE by design: the old 'reset' inverse ran `reset --soft HEAD~1`
     * which deletes the ORIGINAL commit boundary and message together — "undoing"
     * an amend destroyed more than it restored. Restoring the pre-amend commit
     * would need the reflog sha, which this model never records.
     */
    public static function amend(): self
    {
        return new self('amend', [], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Convenience factory for branch creation.
     *
     * `checkout -b` creates the ref AND moves HEAD, so the inverse must
     * travel home first and only then delete: a bare deleteBranch inverse
     * was refused by git while the fresh branch was current (every undo of
     * `n` failed, candy-git-016) and, after a later manual switch, silently
     * left HEAD where creation had put it. Mirrors the checkout() lesson
     * above: record the origin, and when none is known (detached HEAD)
     * refuse the undo honestly instead of guessing a way back.
     */
    public static function createBranch(string $name, string $from, string $base = ''): self
    {
        if ($from === '') {
            return new self('createBranch', ['name' => $name, 'from' => '', 'base' => $base], self::UNRECOVERABLE_INVERSE, []);
        }

        return new self(
            'createBranch',
            ['name' => $name, 'from' => $from, 'base' => $base],
            'removeBranch',
            ['name' => $name, 'from' => $from],
        );
    }

    /**
     * Convenience factory for reverting a commit (candy-git-015).
     *
     * UNRECOVERABLE by design: a revert stacks an inverse commit on HEAD.
     * Undoing that would rewrite published history (the amend/force-push
     * lesson), and re-running the same revert as a redo only stacks twice -
     * git answers a re-applied revert with a conflict, so even redo is the
     * users business, not ours to promise. The entry exists so the action
     * is at least named in the history roster.
     */
    public static function revert(string $sha): self
    {
        return new self('revert', ['sha' => $sha], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Convenience factory for resetting HEAD hard (candy-git-015).
     *
     * UNRECOVERABLE by design: moving HEAD back to the pre-reset commit
     * would need the old HEAD sha, which this model never records (the
     * reflog is outside the apps knowledge), and the --hard half already
     * destroyed uncommitted bytes no command can recreate. Both losses
     * are the reason the UI arms the gesture twice.
     */
    public static function resetHard(string $sha): self
    {
        return new self('resetHard', ['sha' => $sha], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Convenience factory for stage all.
     */
    /**
     * Convenience factory for stage-all, carrying what the index looked like
     * BEFORE the sweep (candy-git-042).
     *
     * `git reset --` as a blanket inverse destroyed every selective staged
     * pick along with the sweep it was supposed to undo - and a partially
     * staged file (index differs from worktree) loses its staged content
     * FOREVER, because only the index ever held that version. So the undo
     * has three honest shapes:
     *  - a partial pick existed: refuse (UNRECOVERABLE; refusing beats
     *    guessing),
     *  - nothing was staged before: the plain unstageAll inverse is exact,
     *  - a clean selection existed (staged == worktree per path): reset --
     *    then re-add exactly those paths, restoring the selection shape.
     * Re-add stages the worktree bytes, so a worktree edit made BETWEEN the
     * sweep and the undo lands as it is now - the entry restores what was
     * SELECTED, not a frozen blob; that boundary is why the partial case
     * refuses outright instead of guessing per file.
     *
     * @param list<string> $stagedPaths paths that were staged before the sweep
     */
    public static function stageAll(array $stagedPaths = [], bool $partial = false): self
    {
        if ($partial) {
            return new self('stageAll', [], self::UNRECOVERABLE_INVERSE, []);
        }

        if ($stagedPaths === []) {
            return new self('stageAll', [], 'unstageAll', []);
        }

        return new self('stageAll', [], 'restoreSelection', ['paths' => $stagedPaths]);
    }

    /**
     * Convenience factory for stage patch (hunk).
     */
    public static function stagePatch(string $path, string $hunk): self
    {
        return new self('stagePatch', ['path' => $path, 'hunk' => $hunk], 'unstagePatch', ['path' => $path, 'hunk' => $hunk]);
    }

    /**
     * Convenience factory for unstage patch (hunk) — the cached-side
     * gesture of the diff viewer (candy-git-034). Its forward arm was
     * already routed; the missing piece was an entry that remembers the
     * side it came from, so undo stages the hunk back.
     */
    public static function unstagePatch(string $path, string $hunk): self
    {
        return new self('unstagePatch', ['path' => $path, 'hunk' => $hunk], 'stagePatch', ['path' => $path, 'hunk' => $hunk]);
    }

    /**
     * Convenience factory for merge.
     *
     * UNRECOVERABLE by design: the old 'abort' inverse was routed to
     * `git rebase --abort`, which either errors ("no rebase in progress") or —
     * worse — aborts an UNRELATED rebase the user happens to be mid-way through.
     * A merge undo needs `reset --hard ORIG_HEAD` semantics this model does not
     * carry, so refusing beats guessing.
     */
    public static function merge(string $branch): self
    {
        return new self('merge', ['branch' => $branch], self::UNRECOVERABLE_INVERSE, []);
    }

    /**
     * Convenience factory for delete branch.
     *
     * Stores the branch tip sha so the inverse can restore the branch AT
     * that commit: the old inverse was 'createBranch', which runs
     * `git checkout -b` — recreating the name at HEAD (wrong commit) and
     * yanking the working tree onto it (audit A2). Without a sha (fixture
     * rows, stripped branch lists) the entry refuses undo.
     */
    public static function deleteBranch(string $name, string $sha): self
    {
        if ($sha === '') {
            return new self('deleteBranch', ['name' => $name, 'sha' => ''], self::UNRECOVERABLE_INVERSE, []);
        }

        return new self(
            'deleteBranch',
            ['name' => $name, 'sha' => $sha],
            'restoreBranch',
            ['name' => $name, 'sha' => $sha],
        );
    }
}
