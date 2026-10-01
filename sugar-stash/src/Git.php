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
            if (str_starts_with($line, '##') === TRUE) {
                $rows[] = ['branch_summary' => trim(substr($line, 2))];
                continue;
            }
            $index = $line[0] ?? ' ';
            $work  = $line[1] ?? ' ';
            $field = substr($line, 3);
            $row = [
                'index_status' => $index,
                'work_status'  => $work,
                'path'         => self::unquotePath($field),
            ];
            // Porcelain v1 renders renamed/copied entries (R/C in either
            // status column) as "ORIG_PATH -> PATH". Splitting on the arrow
            // keeps PATH as the working path — a bare substr($line, 3) leaves
            // the whole "old -> new" string as the path and corrupts every
            // downstream stage/discard/diff on the file. The split is
            // quote-aware and both halves are C-unescaped so unicode and
            // quote-bearing names survive the round-trip (audit #3).
            if ($index === 'R' || $index === 'C' || $work === 'R' || $work === 'C') {
                $parts = self::splitRename($field);
                if ($parts !== null) {
                    $row['orig_path'] = self::unquotePath($parts[0]);
                    $row['path']      = self::unquotePath($parts[1]);
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
        // NO `--` separator: everything after it is parsed as a PATHSPEC, and a
        // branch name is never a path — `checkout -- main` fails "pathspec
        // 'main' did not match" so branch switching (Space on the branches
        // pane) could never succeed. guardRef already refuses leading-dash refs.
        $this->run(['checkout', $branch]);
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

    /**
     * Assemble the full argv for every `git` shell-out.
     *
     * `-c core.quotepath=off` is repo-wide on purpose: without it git
     * C-style-quotes every path containing non-ASCII bytes
     * (`"caf\303\251.txt"`), the app feeds that escaped literal back into
     * stage/discard/diff as an argument, and every operation on a unicode
     * filename fails (audit sugar-stash #3). Paths containing `"` or `\`
     * stay quoted regardless — see {@see self::unquotePath()}.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private function argv(array $args): array
    {
        return array_merge(['git', '-c', 'core.quotepath=off', '-C', $this->cwd], $args);
    }

    /**
     * Decode a C-style quoted porcelain path (`"we\"ird.txt"`) to raw bytes.
     *
     * Git quotes any path containing `"`, `\`, or control characters even
     * with core.quotepath=off, so the parser must undo the quoting before
     * the path is shown, and before it is handed back as an argument.
     */
    private static function unquotePath(string $path): string
    {
        if (strlen($path) < 2 || !str_starts_with($path, '"') || !str_ends_with($path, '"')) {
            return $path;
        }
        $inner = substr($path, 1, -1);
        $decoded = preg_replace_callback(
            '/\\\\([0-7]{1,3}|.)/s',
            static fn(array $m): string => match (true) {
                ctype_digit($m[1]) => chr((int) octdec($m[1])),
                $m[1] === 'n' => "\n",
                $m[1] === 't' => "\t",
                $m[1] === 'r' => "\r",
                $m[1] === 'a' => "\a",
                $m[1] === 'b' => "\b",
                $m[1] === 'f' => "\f",
                $m[1] === 'v' => "\v",
                default => $m[1], // \" \\ \any-other -> the literal char
            },
            $inner,
        );
        // preg only fails on a malformed pattern (impossible here); the
        // fail-closed fallback still sheds the wrapper quotes.
        return $decoded ?? $inner;
    }

    /**
     * Split a porcelain rename field `ORIG -> NEW` at the first ` -> `
     * sitting OUTSIDE a quoted section (a quoted name may itself contain
     * the arrow, e.g. `"we\"ird -> old" -> new.txt`).
     *
     * @return array{0: string, 1: string}|null null when no unquoted arrow exists
     */
    private static function splitRename(string $field): ?array
    {
        $inQuote = false;
        $length = strlen($field);
        for ($i = 0; $i < $length; $i++) {
            $char = $field[$i];
            if ($inQuote) {
                if ($char === '\\') {
                    $i++; // escaped char rides inside the quote
                } elseif ($char === '"') {
                    $inQuote = false;
                }
                continue;
            }
            if ($char === '"') {
                $inQuote = true;
            } elseif (substr($field, $i, 4) === ' -> ') {
                return [substr($field, 0, $i), substr($field, $i + 4)];
            }
        }
        return null;
    }

    /** @return list<string> */
    private function run(array $args): array
    {
        $r = Process::run($this->argv($args), null, $this->timeout);
        $this->assertNotTimedOut($r);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => trim($r['stderr'])]));
        }
        return explode("\n", rtrim($r['stdout'], "\n"));
    }

    /** Like run() but passes $input via stdin and does not capture stdout. */
    private function runPatch(string $path, string $hunk, array $args): void
    {
        $r = Process::run($this->argv($args), $hunk, $this->timeout);
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
        if ($result['timedOut'] === TRUE) {
            throw new \RuntimeException(Lang::t('git.timeout', ['seconds' => (string) $this->timeout]));
        }
    }
}
