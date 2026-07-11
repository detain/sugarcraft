<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Stash\Lang;

/**
 * Thin wrapper around `git` invocations. Everything that mutates a
 * repo shells out — no libgit2 binding, no in-PHP plumbing-command
 * reimplementation. The wrapper is split out so tests can swap in a
 * fixture-backed `Git` (or its parent interface, {@see GitDriver})
 * without touching the runtime.
 */
final class Git implements GitDriver
{
    /** Wall-clock ceiling (seconds) for a single git invocation before it is killed. */
    public const DEFAULT_TIMEOUT = 30.0;

    public function __construct(
        public readonly string $cwd,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    public function status(): array
    {
        $out = $this->run(['status', '--porcelain=v1', '-b']);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            if (str_starts_with($line, '##')) {
                $rows[] = ['branch_summary' => trim(substr($line, 2))];
                continue;
            }
            $index = $line[0] ?? ' ';
            $work  = $line[1] ?? ' ';
            $field = substr($line, 3);
            $row = [
                'index_status' => $index,
                'work_status'  => $work,
                'path'         => $field,
            ];
            // Porcelain v1 renders renamed/copied entries (R/C in either
            // status column) as "ORIG_PATH -> PATH". Splitting on the literal
            // " -> " keeps PATH as the working path — a bare substr($line, 3)
            // leaves the whole "old -> new" string as the path and corrupts
            // every downstream stage/discard/diff on the file.
            if ($index === 'R' || $index === 'C' || $work === 'R' || $work === 'C') {
                $parts = explode(' -> ', $field, 2);
                if (count($parts) === 2) {
                    $row['orig_path'] = $parts[0];
                    $row['path']      = $parts[1];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Probe whether $cwd sits inside a git working tree, including a LINKED
     * worktree where `.git` is a FILE (a `gitdir:` pointer), not a directory.
     * `is_dir("$cwd/.git")` wrongly rejects linked worktrees; asking git to
     * resolve `--absolute-git-dir` succeeds in both layouts, so a zero exit
     * with a non-empty git dir is the authoritative "yes, this is a repo".
     */
    public static function isRepository(string $cwd): bool
    {
        if ($cwd === '' || !is_dir($cwd)) {
            return false;
        }
        $r = Process::run(['git', '-C', $cwd, 'rev-parse', '--absolute-git-dir'], null, self::DEFAULT_TIMEOUT);
        return $r['exit'] === 0 && trim($r['stdout']) !== '';
    }

    public function branches(): array
    {
        $out = $this->run([
            'for-each-ref', '--format=%(HEAD) %(refname:short)\t%(objectname:short)',
            'refs/heads',
        ]);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            $head    = str_starts_with($line, '*');
            $payload = ltrim(substr($line, 2));
            [$name, $sha] = array_pad(explode("\t", $payload, 2), 2, '');
            $rows[] = ['name' => $name, 'sha' => $sha, 'current' => $head];
        }
        return $rows;
    }

    public function log(int $limit = 25): array
    {
        $out = $this->run([
            'log', '--pretty=format:%h%x09%s%x09%an%x09%ar', "-n{$limit}",
        ]);
        $rows = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            [$sha, $subject, $author, $ago] = array_pad(explode("\t", $line, 4), 4, '');
            $rows[] = compact('sha', 'subject', 'author', 'ago');
        }
        return $rows;
    }

    public function stage(string $path): void
    {
        $this->run(['add', '--', $path]);
    }

    public function unstage(string $path): void
    {
        $this->run(['restore', '--staged', '--', $path]);
    }

    public function checkout(string $branch): void
    {
        $this->guardRef($branch);
        $this->run(['checkout', '--', $branch]);
    }

    public function commit(string $message): void
    {
        $this->run(['commit', '-m', $message]);
    }

    public function stageAll(): void
    {
        $this->run(['add', '-A']);
    }

    public function unstageAll(): void
    {
        $this->run(['reset', '--']);
    }

    public function unstagePatch(string $path, string $hunk): void
    {
        $this->runPatch($path, $hunk, ['apply', '--cached', '--reverse', '-']);
    }

    /** @return list<string> */
    public function diff(string $path): array
    {
        return $this->run(['diff', '--no-color', '--', $path]);
    }

    public function discard(string $path): void
    {
        $this->run(['restore', '--worktree', '--', $path]);
    }

    public function amend(): void
    {
        $this->run(['commit', '--amend', '--no-edit']);
    }

    public function stagePatch(string $path, string $hunk): void
    {
        $this->runPatch($path, $hunk, ['apply', '--cached', '--unidiff-zero', '-']);
    }

    public function createBranch(string $name): void
    {
        $this->guardRef($name);
        $this->run(['checkout', '-b', $name]);
    }

    public function deleteBranch(string $name): void
    {
        $this->run(['branch', '-d', $name]);
    }

    public function merge(string $branch): void
    {
        $this->guardRef($branch);
        $this->run(['merge', '--', $branch]);
    }

    public function rebaseContinue(): void
    {
        $this->run(['rebase', '--continue']);
    }

    public function rebaseAbort(): void
    {
        $this->run(['rebase', '--abort']);
    }

    public function rebaseSkip(): void
    {
        $this->run(['rebase', '--skip']);
    }

    public function reset(): void
    {
        $this->run(['reset', '--soft', 'HEAD~1']);
    }

    /** @return list<StashEntry> */
    public function stashList(): array
    {
        $out = $this->run(['stash', 'list']);
        return StashManager::fromGitOutput($out);
    }

    public function stashApply(string $stashRef): void
    {
        $this->guardRef($stashRef);
        $this->run(['stash', 'apply', $stashRef]);
    }

    public function stashDrop(string $stashRef): void
    {
        $this->guardRef($stashRef);
        $this->run(['stash', 'drop', $stashRef]);
    }

    public function cherryPick(string $commit): void
    {
        $this->guardRef($commit);
        $this->run(['cherry-pick', $commit]);
    }

    public function cherryPickContinue(): void
    {
        $this->run(['cherry-pick', '--continue']);
    }

    public function cherryPickAbort(): void
    {
        $this->run(['cherry-pick', '--abort']);
    }

    /** @return list<WorktreeEntry> */
    public function worktreeList(): array
    {
        $out = $this->run(['worktree', 'list', '--porcelain']);
        return Worktrees::fromGitOutput($out);
    }

    public function worktreeAdd(string $path, string $branch): void
    {
        $resolved = $this->guardWorktreePath($path);
        $this->guardRef($branch);
        $this->run(['worktree', 'add', $resolved, $branch]);
    }

    public function worktreeRemove(string $path): void
    {
        $this->guardRef($path);
        $this->run(['worktree', 'remove', $path]);
    }

    public function rebaseInProgress(): bool
    {
        $out = $this->run(['rev-parse', '--absolute-git-dir']);
        $gitDir = $out[0] ?? '';
        if ($gitDir === '' || !is_dir($gitDir)) {
            return false;
        }
        return is_dir($gitDir . '/rebase-merge') || is_dir($gitDir . '/rebase-apply');
    }

    /**
     * Reject a ref/branch/path that begins with `-` to prevent option injection.
     *
     * @throws \InvalidArgumentException
     */
    private function guardRef(string $ref): void
    {
        if (str_starts_with($ref, '-')) {
            throw new \InvalidArgumentException(Lang::t('git.error', ['stderr' => "ref cannot start with '-': {$ref}"]));
        }
    }

    /**
     * Validate + canonicalize a worktree destination path.
     *
     * The worktree path is attacker-influenceable — it is collected keystroke
     * by keystroke in the TUI — so the leading-dash option-injection guard
     * alone is not enough. Reject any `..` traversal segment outright, then
     * anchor a relative path to the realpath'd repository root so a
     * CWD-relative or `foo/../../etc`-style value cannot plant a worktree
     * outside the intended tree. Returns the absolute path handed to git.
     *
     * @throws \InvalidArgumentException
     */
    private function guardWorktreePath(string $path): string
    {
        $this->guardRef($path);
        if ($path === '') {
            throw new \InvalidArgumentException(Lang::t('git.unsafe_path', ['path' => $path]));
        }
        foreach (preg_split('#[\\\\/]#', $path) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException(Lang::t('git.unsafe_path', ['path' => $path]));
            }
        }
        $isAbsolute = str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path); // Windows drive-letter root
        if ($isAbsolute) {
            return $path;
        }
        $base = realpath($this->cwd);
        if ($base === false) {
            throw new \InvalidArgumentException(Lang::t('git.unsafe_path', ['path' => $path]));
        }
        return $base . '/' . $path;
    }

    /** @return list<string> */
    private function run(array $args): array
    {
        $r = Process::run(array_merge(['git', '-C', $this->cwd], $args), null, $this->timeout);
        $this->assertNotTimedOut($r);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => trim($r['stderr'])]));
        }
        return explode("\n", rtrim($r['stdout'], "\n"));
    }

    /** Like run() but passes $input via stdin and does not capture stdout. */
    private function runPatch(string $path, string $hunk, array $args): void
    {
        $r = Process::run(array_merge(['git', '-C', $this->cwd], $args), $hunk, $this->timeout);
        $this->assertNotTimedOut($r);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => trim($r['stderr'])]));
        }
    }

    /**
     * @param array{stdout: string, stderr: string, exit: int, timedOut: bool} $result
     * @throws \RuntimeException
     */
    private function assertNotTimedOut(array $result): void
    {
        if ($result['timedOut']) {
            throw new \RuntimeException(Lang::t('git.timeout', ['seconds' => (string) $this->timeout]));
        }
    }
}
