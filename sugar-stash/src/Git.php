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
    public function __construct(public readonly string $cwd)
    {}

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
        $proc = proc_open(
            ['git', '-C', $cwd, 'rev-parse', '--absolute-git-dir'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            return false;
        }
        $gitDir = trim(stream_get_contents($pipes[1]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return $exit === 0 && $gitDir !== '';
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
        $this->guardRef($path);
        $this->guardRef($branch);
        $this->run(['worktree', 'add', $path, $branch]);
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

    /** @return list<string> */
    private function run(array $args): array
    {
        $cmd = array_merge(['git', '-C', $this->cwd], $args);
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException(Lang::t('git.spawn_failed'));
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => trim($stderr)]));
        }
        return explode("\n", rtrim($stdout, "\n"));
    }

    /** Like run() but passes $input via stdin and does not capture stdout. */
    private function runPatch(string $path, string $hunk, array $args): void
    {
        $cmd = array_merge(['git', '-C', $this->cwd], $args);
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException(Lang::t('git.spawn_failed'));
        }
        fwrite($pipes[0], $hunk);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => trim($stderr)]));
        }
    }
}
