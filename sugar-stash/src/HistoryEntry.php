<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

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
     * Convenience factory for checkout operations.
     */
    public static function checkout(string $branch): self
    {
        return new self('checkout', ['branch' => $branch], 'checkout', ['branch' => $branch]);
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
     */
    public static function createBranch(string $name): self
    {
        return new self('createBranch', ['name' => $name], 'deleteBranch', ['name' => $name]);
    }

    /**
     * Convenience factory for stage all.
     */
    public static function stageAll(): self
    {
        return new self('stageAll', [], 'unstageAll', []);
    }

    /**
     * Convenience factory for stage patch (hunk).
     */
    public static function stagePatch(string $path, string $hunk): self
    {
        return new self('stagePatch', ['path' => $path, 'hunk' => $hunk], 'unstagePatch', ['path' => $path, 'hunk' => $hunk]);
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
     */
    public static function deleteBranch(string $name): self
    {
        return new self('deleteBranch', ['name' => $name], 'createBranch', ['name' => $name]);
    }
}
