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
     */
    public static function discard(string $path): self
    {
        return new self('discard', ['path' => $path], 'stage', ['path' => $path]);
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
     */
    public static function amend(): self
    {
        return new self('amend', [], 'reset', []);
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
        return new self('stagePatch', ['path' => $path, 'hunk' => $hunk], 'unstage', ['path' => $path]);
    }

    /**
     * Convenience factory for merge.
     */
    public static function merge(string $branch): self
    {
        return new self('merge', ['branch' => $branch], 'abort', []);
    }

    /**
     * Convenience factory for delete branch.
     */
    public static function deleteBranch(string $name): self
    {
        return new self('deleteBranch', ['name' => $name], 'createBranch', ['name' => $name]);
    }
}
