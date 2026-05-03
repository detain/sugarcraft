<?php

declare(strict_types=1);

namespace CandyCore\Stash;

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
}
