<?php

declare(strict_types=1);

namespace SugarCraft\Git;

use SugarCraft\Git\Lang;

/**
 * Thin wrapper around `git` invocations. Everything that mutates a
 * repo shells out — no libgit2 binding, no in-PHP plumbing-command
 * reimplementation. The wrapper is split out so tests can swap in a
 * fixture-backed `Git` (or its parent interface, {@see GitDriver})
 * without touching the runtime.
 *
 * Mirrors jesseduffield/lazygit pkg/commands — the shell-out wrapper and porcelain parsing. */
final class Git implements GitDriver
{
    /** Wall-clock ceiling (seconds) for a single git invocation before it is killed. */
    public const DEFAULT_TIMEOUT = 30.0;

    /**
     * Environment switch for strict filter mode: a truthy value (1, true, yes)
     * makes every filter-capable command REFUSE to run when the repository
     * defines any `filter.<name>.clean|smudge|process` driver, instead of
     * executing-and-announcing it. Read at construction; the constructor
     * argument overrides it per instance.
     */
    public const STRICT_FILTERS_ENV = 'CANDY_GIT_STRICT_FILTERS';

    /**
     * One-shot stderr notice when filter drivers are detected (announce mode).
     * English by design, like Process::CAPTURE_NOTICE — it is a trust-boundary
     * warning about THIS library's behavior, not UI chrome; the strict-mode
     * refusal (lang key git.unsafe_filter_driver) IS localized.
     */
    public const FILTER_NOTICE_FORMAT = "candy-git: notice: this repository defines git filter drivers (%s) that execute whenever files move between the worktree and the index. candy-git runs them, because tools like git-lfs depend on their filters working; see the \"Trust boundary\" section of candy-git's README.md, or set %s=1 to refuse such commands instead.\n";

    /** Hard mode: refuse (typed) instead of announce (sticky) filter-capable commands. */
    public readonly bool $strictFilters;

    /** @var bool one-shot latch for FILTER_NOTICE_FORMAT (per instance) */
    private bool $filterNoticeEmitted = false;

    public function __construct(
        public readonly string $cwd,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        ?bool $strictFilters = null,
    ) {
        $this->strictFilters = $strictFilters ?? self::strictFiltersFromEnv();
    }

    /** Immutable derive: the same instance in strict (or relaxed) filter mode. */
    public function withStrictFilters(bool $strict = true): self
    {
        return new self($this->cwd, $this->timeout, $strict);
    }

    private static function strictFiltersFromEnv(): bool
    {
        $raw = getenv(self::STRICT_FILTERS_ENV);
        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes'], true);
    }

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
        $r = Process::run(
            array_merge(self::gitBase($cwd), ['rev-parse', '--absolute-git-dir']),
            null,
            self::DEFAULT_TIMEOUT,
        );
        return $r['exit'] === 0 && trim($r['stdout']) !== '';
    }

    /**
     * Bare repos answer `rev-parse --is-bare-repository` with `true`. They
     * have no work tree, so every status-pane verb (stage, discard, stash)
     * is meaningless here — the launcher refuses one instead of booting a
     * TUI whose whole first pane would error.
     */
    public static function isBareRepository(string $cwd): bool
    {
        $r = Process::run(
            array_merge(self::gitBase($cwd), ['rev-parse', '--is-bare-repository']),
            null,
            self::DEFAULT_TIMEOUT,
        );

        return $r['exit'] === 0 && trim($r['stdout']) === 'true';
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

    /**
     * Unified diff lines for one file.
     *
     * `--no-ext-diff` is deliberate: `diff.external` is ANOTHER repo config
     * key naming an executable (and difftool classes reach the same door via
     * config) — the same clone-time code-execution surface as core.fsmonitor.
     * The viewer only ever wants git's own unified text, so externals are
     * switched off per invocation instead of inherited from the repo.
     *
     * `$cached` shows the STAGED side (`git diff --cached`): a file whose
     * worktree copy matches the index has an empty plain diff, and the
     * staged change would otherwise be invisible.
     *
     * @return list<string>
     */
    public function diff(string $path, bool $cached = false): array
    {
        $args = ['diff', '--no-color', '--no-ext-diff'];
        if ($cached) {
            $args[] = '--cached';
        }
        return $this->run([...$args, '--', $path]);
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
        // Same option-injection door as every other ref-taking method: an
        // unguarded leading dash reaches `git branch -d -sneaky` and git
        // parses it as flags (audit A9).
        $this->guardRef($name);
        $this->run(['branch', '-d', $name]);
    }

    /**
     * Re-point a branch name at a recorded commit (undo of a deletion).
     *
     * `git branch <name> <sha>` creates the ref without moving HEAD —
     * unlike createBranch's `checkout -b`, restoring a branch must never
     * switch the user's working tree (audit A2).
     */
    public function restoreBranch(string $name, string $sha): void
    {
        $this->guardRef($name);
        $this->guardRef($sha);
        $this->run(['branch', $name, $sha]);
    }

    public function merge(string $branch): void
    {
        $this->guardRef($branch);
        // --no-edit: a merge that needs a commit message must never open
        // $EDITOR inside the TUI (the editor would inherit our terminal).
        $this->run(['merge', '--no-edit', '--', $branch]);
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
        return $this->sequenceState() === 'rebase';
    }

    /**
     * Which sequencer (if any) is stopped mid-flight waiting for attention.
     *
     * Rebase, cherry-pick, and merge conflicts all leave state files in the
     * git directory and all share the same continue/abort need; the [c]/[a]
     * menu must know which operation it is resuming (audit A5 — cherryPick-
     * Continue/Abort had zero callers and conflicted cherry-picks wedged).
     *
     * @return string 'rebase' | 'cherry' | 'merge' | ''
     */
    public function sequenceState(): string
    {
        $out = $this->run(['rev-parse', '--absolute-git-dir']);
        $gitDir = $out[0] ?? '';
        if ($gitDir === '' || !is_dir($gitDir)) {
            return '';
        }
        if (is_dir($gitDir . '/rebase-merge') || is_dir($gitDir . '/rebase-apply')) {
            return 'rebase';
        }
        if (is_file($gitDir . '/CHERRY_PICK_HEAD')) {
            return 'cherry';
        }
        if (is_file($gitDir . '/MERGE_HEAD')) {
            return 'merge';
        }
        return '';
    }

    /**
     * Reject a ref/branch/path that begins with `-` to prevent option injection.
     *
     * RuntimeException, not InvalidArgumentException: every caller in the App
     * funnels git failures through one `catch (\RuntimeException)` lane, and a
     * guard refusal is exactly such a failure — a sibling that threw another
     * Exception type would crash the TUI instead of showing an error row
     * (audit K4). The type now matches what {@see self::run()} throws.
     *
     * @throws \RuntimeException
     */
    private function guardRef(string $ref): void
    {
        if (str_starts_with($ref, '-')) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => "ref cannot start with '-': {$ref}"]));
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
     * @throws \RuntimeException (see {@see self::guardRef()} on the type)
     */
    private function guardWorktreePath(string $path): string
    {
        $this->guardRef($path);
        if ($path === '') {
            throw new \RuntimeException(Lang::t('git.unsafe_path', ['path' => $path]));
        }
        foreach (preg_split('#[\\\\/]#', $path) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException(Lang::t('git.unsafe_path', ['path' => $path]));
            }
        }
        $isAbsolute = str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path); // Windows drive-letter root
        if ($isAbsolute) {
            return $path;
        }
        $base = realpath($this->cwd);
        if ($base === false) {
            throw new \RuntimeException(Lang::t('git.unsafe_path', ['path' => $path]));
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
     * filename fails (audit candy-git #3). Paths containing `"` or `\`
     * stay quoted regardless — see {@see self::unquotePath()}.
     *
     * `-c core.fsmonitor=false` is a TRUST-BOUNDARY override, ordered on the
     * command line so it wins over any repository config: `core.fsmonitor`
     * names an EXECUTABLE that lives in the cloned repo's `.git/config`, and
     * git invokes it on the very first `status`. Cloning an untrusted repo
     * and opening it here would otherwise be instant arbitrary code execution
     * before the user ever sees a pane. Our `-c` overlays run after config
     * files, so no fsmonitor value the repo controls can take effect.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private function argv(array $args): array
    {
        return array_merge(self::gitBase($this->cwd), $args);
    }

    /**
     * The one and only git-spawn funnel: program name, the trust prelude, and
     * the working-directory selector. EVERY `git` invocation in this class is
     * assembled from here (directly, or via {@see self::argv()}), which is
     * what lets {@see GitSpawnPreludeGuardTest} pin structurally that no
     * spawn path can silently grow an unprefluded sibling.
     *
     * @return list<string>
     */
    private static function gitBase(string $cwd): array
    {
        return ['git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $cwd];
    }

    /**
     * Commands whose machinery can invoke a MERGE DRIVER: `merge.<name>.driver`
     * is a repo-configurable key naming an executable, reached by a matching
     * `.gitattributes` entry (`*.txt merge=evil`) — the same class of
     * clone-time code execution as core.fsmonitor, but fired by the merge
     * machinery rather than by `status`. The verbs listed here are every
     * command this class runs that performs a 3-way merge: `git merge`,
     * `git rebase --continue/--skip`, `git cherry-pick` (bare or `--continue`;
     * `--abort` tears state down without merging), and `git stash apply/pop`
     * (stash application merges into the worktree).
     */
    private const MERGE_CAPABLE_DISPATCH = [
        'merge'           => null,   // any argument shape
        'rebase'          => ['--continue', '--skip'],
        'cherry-pick'     => ['__not_abort__'],
        'stash'           => ['apply', 'pop'],
    ];

    /**
     * Commands whose machinery can invoke a CLEAN or SMUDGE FILTER:
     * `filter.<name>.clean|smudge|process` is a repo-configurable key naming
     * an executable, reached by a matching `.gitattributes` entry
     * (`*.txt filter=lfs`) — the same class of attacker-controlled-repo code
     * execution as the merge drivers above, but fired by the checkin/checkout
     * machinery: stage, commit, branch-switching checkout, reset, restore,
     * stash apply, worktree add (measured on git 2.43; abort/teardown shapes
     * never merge, so they are excluded like in MERGE_CAPABLE_DISPATCH).
     *
     * Unlike merge drivers these channels are NOT disarmed: git-lfs and every
     * other legitimate filter user depends on its drivers WORKING, so a
     * blanket `-c filter.X.clean=false` would be a worse product defect than
     * the disclosed trust gap. The posture here is DETECT + ANNOUNCE (sticky,
     * one-shot notice per instance) and REFUSE only when the caller opts into
     * strict filter mode. See README "Trust boundary".
     *
     * Entries are deliberately head-level where a flag makes the verb inert
     * (e.g. `diff --cached`, `reset --soft`, `restore --staged` measure as
     * non-firing on git 2.43): the conservative over-detection is the safe
     * side — announce mode only adds a notice line, strict mode never
     * executes repo filters at all.
     */
    private const FILTER_CAPABLE_DISPATCH = [
        'add'         => null,
        'checkout'    => null,
        'commit'      => null,
        'diff'        => null,
        'restore'     => null,
        'reset'       => null,
        'merge'       => null,
        'cherry-pick' => ['__not_abort__'],
        'rebase'      => ['__not_abort__'],
        'stash'       => ['apply', 'pop'],
        'worktree'    => ['add'],
    ];

    /**
     * Assemble argv for run()/runPatch(): the fixed prelude PLUS, for the
     * merge-capable verbs, per-invocation overrides that neutralize every
     * `merge.<name>.driver` git would consult for this repository (see
     * {@see self::mergeDriverDisarms()}). Filter-capable verbs first pass
     * through {@see self::guardFilterChannel()}, which never changes the
     * argv — it only refuses (strict mode) or announces (default mode).
     *
     * @param list<string> $args
     * @return list<string>
     */
    private function argvWithDisarms(array $args): array
    {
        if (self::isFilterCapable($args)) {
            $this->guardFilterChannel();
        }
        if (!self::isMergeCapable($args)) {
            return $this->argv($args);
        }

        return array_merge(self::gitBase($this->cwd), $this->mergeDriverDisarms(), $args);
    }

    /** @param list<string> $args */
    private static function isMergeCapable(array $args): bool
    {
        return self::dispatchMatches(self::MERGE_CAPABLE_DISPATCH, $args);
    }

    /** @param list<string> $args */
    private static function isFilterCapable(array $args): bool
    {
        return self::dispatchMatches(self::FILTER_CAPABLE_DISPATCH, $args);
    }

    /**
     * Shared tail-matcher for the two dispatch tables: null tails mean the
     * verb matches in any argument shape, `['__not_abort__']` matches every
     * shape except the explicit abort teardown, and a literal list matches
     * the verb's second token.
     *
     * @param array<string, list<string>|null> $table
     * @param list<string> $args
     */
    private static function dispatchMatches(array $table, array $args): bool
    {
        $head = $args[0] ?? '';
        if (!array_key_exists($head, $table)) {
            return false;
        }
        $tails = $table[$head];
        if ($tails === null) {
            return true;
        }
        $next = $args[1] ?? '';
        if ($tails === ['__not_abort__']) {
            return $next !== '--abort';
        }

        return in_array($next, $tails, true);
    }

    /**
     * Detect the repository's filter drivers on every filter-capable command
     * and apply the posture: in strict filter mode the command is REFUSED
     * (typed refusal) when at least one driver exists; in default mode a
     * one-shot stderr notice names the drivers, and the command proceeds —
     * the drivers still run (git-lfs-class tooling must keep working).
     *
     * This method NEVER mutates the argv that argvWithDisarms() returns: with
     * zero drivers defined (the common case) nothing at all changes — no
     * extra flags, no notice. The enumeration is a fresh read-only spawn on
     * EVERY filter-capable invocation, never memoized: a repo that mutates
     * its own config between two commands must not be able to run under a
     * stale detection result (symmetric with the merge-disarm posture).
     */
    private function guardFilterChannel(): void
    {
        $lanes = $this->filterDriverLanes();
        if ($lanes === []) {
            return;
        }
        if ($this->strictFilters) {
            throw new \RuntimeException(Lang::t('git.unsafe_filter_driver'));
        }
        if ($this->filterNoticeEmitted) {
            return;
        }
        $this->filterNoticeEmitted = true;
        \fwrite(STDERR, \sprintf(self::FILTER_NOTICE_FORMAT, self::summarizeLanes($lanes), self::STRICT_FILTERS_ENV));
    }

    /**
     * Enumerate the filter lanes THIS REPOSITORY defines — `filter.<name>.
     * clean|smudge|process` keys visible to git for this cwd, minus the
     * user's own baseline.
     *
     * The merged config scope is what git would actually consult (repo
     * `.git/config`, `config.worktree`, and files included from either —
     * `--local` alone is blind to include.path, the same lesson the
     * merge-driver enumeration learned on git 2.43). But merged scope also
     * carries `filter.lfs.*` from the USER's global/system gitconfig, and
     * nagging (or refusing) for the operator's own git-lfs would be exactly
     * the git-lfs-class breakage the disarm ruling rejected. So: lanes whose
     * key AND value byte-match the global or system chain are the user's own
     * trusted baseline and drop out; anything else — a new key, a repo-side
     * OVERRIDE of a global key (shadow attack), or an include reachable only
     * through the repo — counts as a lane. A repo re-defining a driver with
     * the byte-identical global value runs the user's own binary: nothing new
     * executes, nothing to announce.
     *
     * An unnamed key shape (subsection the parser cannot name) lands as the
     * '<unnamed>' lane: its driver still executes at git's side, so it must
     * not stay silent. @return list<string>
     */
    private function filterDriverLanes(): array
    {
        $merged = $this->filterConfigProbe();
        if ($merged === []) {
            return [];
        }

        $baseline = [];
        foreach ([
            $this->filterConfigProbe('--global'),
            $this->filterConfigProbe('--system'),
        ] as $scopeLines) {
            foreach ($scopeLines as $line) {
                $baseline[$line] = true;
            }
        }

        $lanes = [];
        foreach ($merged as $line) {
            if (isset($baseline[$line])) {
                continue; // the operator's own driver, byte-identical
            }
            if (preg_match('/^filter\.([A-Za-z0-9._-]+)\.(clean|smudge|process)(?= |$)/i', $line, $m) === 1) {
                $lanes[$m[1] . '.' . strtolower($m[2])] = true;
                continue;
            }
            if (preg_match('/^filter\./i', $line) === 1
                && preg_match('/\.(clean|smudge|process)(?= |$)/i', $line) === 1) {
                // A key git printed that we cannot cleanly name (subsection
                // carrying spaces/odd bytes, or a key-shaped value
                // continuation). Its driver still EXECUTES at git's side, so
                // it counts: announce says '<unnamed>', strict refuses —
                // fail closed rather than stay silent about a lane we saw.
                $lanes['<unnamed>'] = true;
            }
        }

        return array_keys($lanes);
    }

    /**
     * One read-only `git config --get-regexp` for the filter lanes, optionally
     * restricted to a scope flag (--global/--system) for the trusted
     * baseline. Timeout fails closed (strict mode must not wave an unknown
     * repo through); exit 1 means no lanes; other non-zero (128-class,
     * corrupt config) yields [] and lets the real git command surface its own
     * more specific error.
     *
     * @return list<string> matched "key value" lines
     */
    private function filterConfigProbe(string $scope = ''): array
    {
        $probe = Process::run(array_merge(
            self::gitBase($this->cwd),
            ['config'],
            $scope === '' ? [] : [$scope],
            ['--get-regexp', '^filter\\..*\\.(clean|smudge|process)$'],
        ), null, $this->timeout);
        if ($probe['timedOut']) {
            throw new \RuntimeException(Lang::t('git.timeout', ['seconds' => $this->timeout]));
        }
        if ($probe['exit'] !== 0) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", rtrim($probe['stdout'], "\n")),
            static fn(string $line): bool => $line !== '',
        ));
    }

    /**
     * Display form for the notice: at most 5 lanes, then a "+N more" tail.
     *
     * @param list<string> $lanes
     */
    private static function summarizeLanes(array $lanes): string
    {
        $shown = array_slice($lanes, 0, 5);
        $rest  = count($lanes) - count($shown);
        if ($rest > 0) {
            $shown[] = '+' . $rest . ' more';
        }

        return implode(', ', $shown);
    }

    /**
     * Enumerate and disarm the merge drivers reachable from this repository.
     *
     * `git config --get-regexp` (merged scope, one read-only spawn) lists
     * exactly what the merge machinery would consult — including keys from
     * `.git/config` includes and from `config.worktree` under
     * `extensions.worktreeConfig`, both of which `--local` is blind to while
     * merges honor them (measured on git 2.43). Each name is overridden with
     * `-c merge.<name>.driver=false`: the driver then resolves to the inert
     * shell builtin `false`, which exits non-zero, which git reports as a
     * plain CONFLICT — fail loud, lose nothing, execute nothing the repo
     * wrote. Empty-string overrides are rejected because git emits a scary
     * `cannot run :` line for them; `true` is rejected because it would
     * silently keep OUR version of every matched path (silent data loss).
     *
     * The enumeration runs on EVERY merge-capable invocation, never memoized:
     * a repo that mutates its own config between two app commands must not be
     * able to merge under the previous, narrower disarm set.
     *
     * Keys whose subsection name carries characters that cannot be safely
     * re-expressed through `-c` (spaces and other exotica) cannot be
     * enumerated-then-overridden at all; on git 2.43 they are also
     * unreachable from `.gitattributes`, but we do not merge on the strength
     * of a parser accident — the merge is refused instead (fail closed).
     *
     * @return list<string> pairs of `-c` / `merge.<name>.driver=false`
     */
    private function mergeDriverDisarms(): array
    {
        $probe = Process::run(
            array_merge(self::gitBase($this->cwd), ['config', '--get-regexp', '^merge\\..*\\.driver$']),
            null,
            $this->timeout,
        );
        if ($probe['timedOut']) {
            // Cannot verify which drivers exist => cannot allow one to run.
            throw new \RuntimeException(Lang::t('git.timeout', ['seconds' => $this->timeout]));
        }
        if ($probe['exit'] === 1) {
            return []; // no merge.<name>.driver defined anywhere — nothing to disarm
        }
        if ($probe['exit'] !== 0) {
            // 128 and friends: unreadable/corrupt config. The merge command
            // itself will hit the same wall with a more specific message —
            // returning [] lets git's own error surface instead of ours.
            return [];
        }

        $names = [];
        foreach (explode("\n", rtrim($probe['stdout'], "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^merge\.([A-Za-z0-9._-]+)\.driver(?= |$)/i', $line, $m) === 1) {
                $names[$m[1]] = true;
                continue;
            }
            if (preg_match('/^merge\./i', $line) === 1 && stripos($line, '.driver') !== false) {
                // A key git printed that we cannot safely re-express via -c
                // (subsection carrying spaces/odd bytes, or a multi-line value
                // continuation that looks key-shaped). Cannot enumerate =>
                // cannot guarantee disarmed => refuse.
                throw new \RuntimeException(Lang::t('git.unsafe_merge_driver'));
            }
        }

        $overrides = [];
        foreach (array_keys($names) as $name) {
            $overrides[] = '-c';
            $overrides[] = 'merge.' . $name . '.driver=false';
        }

        return $overrides;
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
        $r = Process::run($this->argvWithDisarms($args), null, $this->timeout);
        $this->assertNotTimedOut($r);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => self::errorDetail($r)]));
        }
        return explode("\n", rtrim($r['stdout'], "\n"));
    }

    /** Like run() but passes $input via stdin and does not capture stdout. */
    private function runPatch(string $path, string $hunk, array $args): void
    {
        $r = Process::run($this->argvWithDisarms($args), $hunk, $this->timeout);
        $this->assertNotTimedOut($r);
        if ($r['exit'] !== 0) {
            throw new \RuntimeException(Lang::t('git.error', ['stderr' => self::errorDetail($r)]));
        }
    }

    /**
     * Human detail for a failed git invocation. git puts the explanatory
     * output of a conflicted merge (and other "stopped, not crashed"
     * failures) on STDOUT while stderr stays empty — showing only stderr
     * rendered the error lane as the bare `git: ` prefix with nothing after
     * it, so the detail falls back to stdout.
     */
    private static function errorDetail(array $r): string
    {
        $stderr = trim($r['stderr']);
        return $stderr !== '' ? $stderr : trim($r['stdout']);
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
