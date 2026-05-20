<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Pluggable git backend. Production is {@see Git} (shells out to the
 * `git` binary). Tests inject a fixture closure-driven implementation
 * so transition correctness can be asserted without staging real
 * repos in tmp dirs.
 */
interface GitDriver
{
    /**
     * Parse `git status --porcelain=v1 -b`.
     *
     * @return list<array{
     *     branch_summary?: string,
     *     index_status?:   string,
     *     work_status?:    string,
     *     path?:           string,
     * }>
     */
    public function status(): array;

    /**
     * @return list<array{name: string, sha: string, current: bool}>
     */
    public function branches(): array;

    /**
     * @return list<array{sha: string, subject: string, author: string, ago: string}>
     */
    public function log(int $limit = 25): array;

    public function stage(string $path): void;

    public function unstage(string $path): void;

    public function checkout(string $branch): void;

    public function commit(string $message): void;

    public function stageAll(): void;

    /**
     * @return list<string> unified diff lines for a file (no color)
     */
    public function diff(string $path): array;

    /** Discard working-tree changes for a file (git restore --worktree <path>). */
    public function discard(string $path): void;

    /** Amend the last commit without changing its message (--no-edit). */
    public function amend(): void;

    /**
     * Stage a single hunk via git apply --cached.
     * @param string $hunk The hunk patch text (unified diff format)
     */
    public function stagePatch(string $path, string $hunk): void;

    /** Create and switch to a new branch. */
    public function createBranch(string $name): void;
}
