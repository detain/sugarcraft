<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Represents a single git worktree.
 *
 * Mirrors jesseduffield/lazygit worktree list — the path, branch and HEAD
 * record parsed out of `git worktree list --porcelain`.
 */
final readonly class WorktreeEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $branch,
        public readonly bool $isBare,
        public readonly string $HEAD,
    ) {}

    /**
     * Parse `git worktree list --porcelain` output into WorktreeEntry list.
     *
     * Porcelain output is blank-line separated records like:
     *   worktree /path/to/worktree
     *   HEAD abc123
     *   branch refs/heads/main
     *   bare
     *
     * @param list<string> $lines Raw output lines from `git worktree list --porcelain`
     * @return list<WorktreeEntry>
     */
    public static function fromGitOutput(array $lines): array
    {
        $worktrees = [];
        $pending = ['path' => '', 'HEAD' => '', 'branch' => '', 'isBare' => false];

        foreach ($lines as $line) {
            if ($line === '') {
                // Flush completed record
                if ($pending['path'] !== '') {
                    $worktrees[] = new self(
                        path: $pending['path'],
                        branch: $pending['branch'],
                        isBare: $pending['isBare'],
                        HEAD: $pending['HEAD'],
                    );
                    $pending = ['path' => '', 'HEAD' => '', 'branch' => '', 'isBare' => false];
                }
                continue;
            }

            if (str_starts_with($line, 'worktree ') === true) {
                // Porcelain is verbatim — a path may legitimately end in a
                // space, so substr() without trim().
                $pending['path'] = substr($line, 9);
            } elseif (str_starts_with($line, 'HEAD ') === true) {
                $pending['HEAD'] = trim(substr($line, 5));
            } elseif (str_starts_with($line, 'branch ') === true) {
                $branch = trim(substr($line, 7));
                // Strip "refs/heads/" prefix to get short name
                if (str_starts_with($branch, 'refs/heads/') === true) {
                    $branch = substr($branch, 11);
                }
                $pending['branch'] = $branch;
            } elseif ($line === 'bare') {
                $pending['isBare'] = true;
            } elseif (str_starts_with($line, 'detached')) {
                $pending['branch'] = '';
            }
        }

        // Flush final record (no trailing blank line)
        if ($pending['path'] !== '') {
            $worktrees[] = new self(
                path: $pending['path'],
                branch: $pending['branch'],
                isBare: $pending['isBare'],
                HEAD: $pending['HEAD'],
            );
        }

        return $worktrees;
    }
}
