<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Represents a single git worktree.
 *
 * Mirrors jesseduffield/lazygit worktree list — the path, branch and
 * detached record parsed out of `git worktree list --porcelain`.
 */
final readonly class WorktreeEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $branch,
        public readonly bool $detached = false,
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
     * The consumed columns are path, branch and detached (candy-git-082):
     * HEAD and bare are skipped on purpose — the overlay shows neither, and
     * a bare repository can never be the working directory this app drives.
     * The parser still walks over unknown/extra fields without choking, so
     * future git columns cannot break the list.
     *
     * @param list<string> $lines Raw output lines from `git worktree list --porcelain`
     * @return list<WorktreeEntry>
     */
    public static function fromGitOutput(array $lines): array
    {
        $worktrees = [];
        $pending = ['path' => '', 'branch' => '', 'detached' => false];

        foreach ($lines as $line) {
            if ($line === '') {
                // Flush completed record
                if ($pending['path'] !== '') {
                    $worktrees[] = new self(
                        path: $pending['path'],
                        branch: $pending['branch'],
                        detached: $pending['detached'],
                    );
                    $pending = ['path' => '', 'branch' => '', 'detached' => false];
                }
                continue;
            }

            if (str_starts_with($line, 'worktree ') === true) {
                // Porcelain is verbatim — a path may legitimately end in a
                // space, so substr() without trim().
                $pending['path'] = substr($line, 9);
            } elseif (str_starts_with($line, 'branch ') === true) {
                $branch = trim(substr($line, 7));
                // Strip "refs/heads/" prefix to get short name
                if (str_starts_with($branch, 'refs/heads/') === true) {
                    $branch = substr($branch, 11);
                }
                $pending['branch'] = $branch;
            } elseif ($line === 'detached') {
                // git's own word for "this HEAD points at a sha, not a
                // branch" — surface it (candy-git-082) rather than leaving
                // the entry to look like a nameless branch.
                $pending['branch'] = '';
                $pending['detached'] = true;
            }
            // 'HEAD …' and 'bare' lines: skipped columns (see class docblock).
        }

        // Flush final record (no trailing blank line)
        if ($pending['path'] !== '') {
            $worktrees[] = new self(
                path: $pending['path'],
                branch: $pending['branch'],
                detached: $pending['detached'],
            );
        }

        return $worktrees;
    }
}
