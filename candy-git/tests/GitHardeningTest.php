<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Process;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Trust-boundary and process-hygiene pins for the git shell-out layer.
 *
 * Each pin here corresponds to a class of failure the plain unit suite cannot
 * see, because it is a property of the ARGV we hand proc_open, of the ENV we
 * hand the child, or of how we tear a wedged child down:
 *
 * - core.fsmonitor / diff.external in the cloned repo's .git/config are
 *   repo-controlled EXECUTABLES — cloning an untrusted repo and opening it
 *   must not run anything before the user sees a pane (the headline CRIT).
 * - an inherited raw-mode tty on the child's stdin steals keystrokes.
 * - a child that wedges must die on a BOUNDED TERM->grace->KILL ladder, never
 *   strand the UI thread inside proc_close.
 * - a failed git call must surface git's own explanation even when git put it
 *   on stdout (merge conflicts), not an empty error lane.
 */
final class GitHardeningTest extends TestCase
{
    use RecursiveDirCleanup;

    /** @var list<string> temp paths (files or dirs) removed in tearDown */
    private array $trash = [];

    protected function tearDown(): void
    {
        foreach ($this->trash as $path) {
            if (is_dir($path)) {
                $this->removeDir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->trash = [];
    }

    // ---------------------------------------------------------------- argv

    public function testArgvOverlaysFsmonitorDisableBeforeRepoArgs(): void
    {
        $argv = self::callArgv('/repo/dir', ['status', '--porcelain=v1']);

        // Exact full shape, not just presence: the -c overrides must sit on
        // the command line ahead of the subcommand (git applies -c in order,
        // last-wins, and any later repo config could not override them), and
        // nothing may ever drop them.
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false',
            '-C', '/repo/dir', 'status', '--porcelain=v1',
        ], $argv);
    }

    /** @param list<string> $args */
    private static function callArgv(string $cwd, array $args): array
    {
        $method = new \ReflectionMethod(Git::class, 'argv');
        $method->setAccessible(true);

        return $method->invoke(new Git($cwd), $args);
    }

    // ------------------------------------------------------------ child env

    public function testChildEnvPinsNonInteractiveGit(): void
    {
        putenv('CANDY_GIT_HARDENING_CARRIED=yes');
        try {
            $env = Process::childEnv();

            // Parent environment rides through (PATH etc.)...
            self::assertSame('yes', $env['CANDY_GIT_HARDENING_CARRIED'] ?? null);
            self::assertArrayHasKey('PATH', $env);

            // ...and every interactive surface git could reach is closed.
            self::assertSame('0', $env['GIT_TERMINAL_PROMPT']);
            self::assertSame('cat', $env['GIT_PAGER']);
            self::assertSame('true', $env['GIT_EDITOR']);
            self::assertSame('true', $env['GIT_SEQUENCE_EDITOR']);
            self::assertSame('C', $env['LC_ALL']);
            self::assertSame('C', $env['LANG']);
        } finally {
            putenv('CANDY_GIT_HARDENING_CARRIED');
        }
    }

    public function testChildActuallySeesThePinnedEnv(): void
    {
        // The map is worthless if proc_open never receives it: read the values
        // back from INSIDE the child.
        $r = Process::run([
            'php', '-r',
            'echo getenv("GIT_TERMINAL_PROMPT"),"|",getenv("GIT_PAGER"),"|",getenv("GIT_EDITOR"),"|",getenv("GIT_SEQUENCE_EDITOR");',
        ]);

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertSame('0|cat|true|true', $r['stdout']);
    }

    // ------------------------------------------------------------- stdin

    public function testChildWithoutStdinPayloadSeesImmediateEof(): void
    {
        // Behavior pin: fgets(STDIN) === false means the child got EOF at
        // once — it can never read (or block on) the TUI's raw-mode terminal.
        $r = Process::run(['php', '-r', 'var_dump(fgets(STDIN) === false);']);

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertStringContainsString('bool(true)', $r['stdout']);
    }

    public function testStdinPayloadStillReachesTheChild(): void
    {
        $r = Process::run(
            ['php', '-r', 'echo "got:" . stream_get_contents(STDIN);'],
            'patch-payload',
        );

        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertSame('got:patch-payload', $r['stdout']);
    }

    public function testProcessSourceAttachesDevNullExactlyOnce(): void
    {
        // Structural leg: the EOF behavior above is indistinguishable from
        // "no fd 0 key at all" (which inherits the tty and blocks instead of
        // EOF-ing on some reads). Pin the explicit /dev/null spec entry.
        $src = (string) file_get_contents((new \ReflectionClass(Process::class))->getFileName());

        self::assertSame(1, substr_count($src, "['file', '/dev/null', 'r']"));
        self::assertStringContainsString('self::childEnv()', $src);
    }

    // ------------------------------------------------- TERM->grace->KILL ladder

    public function testTeardownSendsSigtermBeforeSigkill(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(Process::class))->getFileName());

        $term = strpos($src, 'proc_terminate($proc, 15)');
        $kill = strpos($src, 'proc_terminate($proc, 9)');

        self::assertNotFalse($term, 'ladder must start with SIGTERM');
        self::assertNotFalse($kill, 'ladder must escalate to SIGKILL');
        self::assertLessThan((int) $kill, (int) $term, 'TERM must precede KILL');
    }

    public function testDefaultDispositionChildDiesOnSigtermWithinGrace(): void
    {
        // A child that has not trapped SIGTERM dies on the first rung; the
        // wall-clock budget must therefore stop near the timeout, NOT spend
        // the full 2s grace before returning.
        $start = microtime(true);
        $r     = Process::run(['php', '-r', 'while (true) { usleep(10000); }'], null, 0.4);
        $wall  = microtime(true) - $start;

        self::assertTrue($r['timedOut']);
        self::assertLessThan(1.5, $wall, "TERM rung should reap this child; wall was {$wall}s");
    }

    public function testTermIgnoringChildEscalatesToSigkillAfterBoundedGrace(): void
    {
        // Only meaningful when PHP can trap signals in the child.
        $probe = Process::run(['php', '-r', 'exit(function_exists("pcntl_signal") ? 0 : 1);']);
        if ($probe['exit'] !== 0) {
            $this->markTestSkipped('child PHP lacks pcntl for the TERM-ignoring fixture');
        }

        $start = microtime(true);
        $r     = Process::run([
            'php', '-r',
            'pcntl_signal(SIGTERM, function () {}); while (true) { usleep(10000); }',
        ], null, 0.4);
        $wall = microtime(true) - $start;

        self::assertTrue($r['timedOut']);
        // Grace honored (child could not die before ~timeout+grace) and the
        // KILL rung bounded the wait (no unbounded proc_close hang).
        self::assertGreaterThan(
            0.4 + Process::TERMINATE_GRACE_SECONDS - 0.1,
            $wall,
            'ladder killed before the grace window elapsed',
        );
        self::assertLessThan(8.0, $wall, "wedged child escaped the ladder; wall was {$wall}s");
    }

    // ------------------------------------------- fsmonitor integration (real git)

    public function testRepoControlledFsmonitorNeverExecutesOnStatus(): void
    {
        $this->requireGit();

        $repo     = $this->makeRepo();
        $sentinel = $this->cwd() . '/fsmonitor_fired.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $hook     = $this->writeExecutable($repo, 'evil-fsmonitor.sh',
            "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\nexit 1\n");

        exec('git -C ' . escapeshellarg($repo) . ' config core.fsmonitor ' . escapeshellarg($hook));
        file_put_contents($repo . '/file.txt', "line 1\nline 2\nchanged\n");

        // Control: the vulnerability is real on this git build — a plain
        // `git status` in the repo DOES fire the configured hook. If it
        // doesn't (different git semantics), the negative proof below would
        // pass vacuously, so skip instead.
        exec('git -C ' . escapeshellarg($repo) . ' status --porcelain 2>/dev/null');
        if (!file_exists($sentinel)) {
            $this->markTestSkipped('this git build does not fire core.fsmonitor on status');
        }
        @unlink($sentinel);

        // The fix: Git::status() runs through argv() with -c core.fsmonitor=false.
        $git = new Git($repo);
        $rows = $git->status();

        self::assertFalse(file_exists($sentinel), 'repo-controlled core.fsmonitor executed through Git::status()');
        self::assertNotEmpty($rows, 'status must still work with the hook disabled');
    }

    // ------------------------------------------- diff.external integration (real git)

    public function testRepoControlledExternalDiffNeverExecutes(): void
    {
        $this->requireGit();

        $repo     = $this->makeRepo();
        $sentinel = $this->cwd() . '/extdiff_fired.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $hook     = $this->writeExecutable($repo, 'evil-extdiff.sh',
            "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\nexec diff \"\$@\"\n");

        exec('git -C ' . escapeshellarg($repo) . ' config diff.external ' . escapeshellarg($hook));
        file_put_contents($repo . '/file.txt', "line 1\nline 2 modified\nline 3\n");

        exec('git -C ' . escapeshellarg($repo) . ' diff -- file.txt 2>/dev/null');
        if (!file_exists($sentinel)) {
            $this->markTestSkipped('this git build does not fire diff.external');
        }
        @unlink($sentinel);

        $lines = (new Git($repo))->diff('file.txt');

        self::assertFalse(file_exists($sentinel), 'repo-controlled diff.external executed through Git::diff()');
        self::assertNotEmpty($lines, 'the internal diff must still be produced');
        self::assertStringStartsWith('diff --git', $lines[0]);
    }

    // ------------------------------------------- merge-driver integration (real git)

    /**
     * Shared setup for the merge-driver pins: a repo whose git config routes
     * a custom driver at *.txt (local `.git/config` or, when $worktreeScope,
     * `.git/config.worktree`), plus two branches diverging on the same line so
     * any merge is guaranteed to reach the driver decision. The driver script
     * touches $sentinel and exits 1 (git treats that as a failed merge).
     */
    private function makeMergeDriverRepo(string $sentinel, bool $worktreeScope): string
    {
        $repo   = $this->makeRepo();
        $driver = $this->writeExecutable($this->cwd(), 'evil-merge-driver-' . bin2hex(random_bytes(4)) . '.sh',
            "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\nexit 1\n");
        $value  = escapeshellarg($driver . ' %A %O %B %L %P');

        if ($worktreeScope) {
            exec('git -C ' . escapeshellarg($repo) . ' config extensions.worktreeConfig true 2>/dev/null');
            exec('git -C ' . escapeshellarg($repo) . ' config --worktree merge.evil.driver ' . $value . ' 2>/dev/null');
        } else {
            exec('git -C ' . escapeshellarg($repo) . ' config merge.evil.driver ' . $value . ' 2>/dev/null');
        }

        file_put_contents($repo . '/.gitattributes', "*.txt merge=evil\n");
        exec('git -C ' . escapeshellarg($repo) . ' add .gitattributes 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' commit -m attrs 2>/dev/null');

        $base = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --abbrev-ref HEAD'));
        exec('git -C ' . escapeshellarg($repo) . ' checkout -b conflicting 2>/dev/null');
        file_put_contents($repo . '/file.txt', "line 1\nother branch line\nline 3\n");
        exec('git -C ' . escapeshellarg($repo) . ' commit -aqm other 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' checkout ' . escapeshellarg($base) . ' 2>/dev/null');
        file_put_contents($repo . '/file.txt', "line 1\nmaster branch line\nline 3\n");
        exec('git -C ' . escapeshellarg($repo) . ' commit -aqm ours 2>/dev/null');

        return $repo;
    }

    public function testRepoControlledMergeDriverNeverExecutesOnMerge(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/mergedrv_fired.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeMergeDriverRepo($sentinel, false);

        // Control: the vulnerability is real on this git build — a plain
        // `git merge` in the repo DOES fire the configured driver. Without
        // it, the negative proof below would pass vacuously, so skip.
        exec('git -C ' . escapeshellarg($repo) . ' merge conflicting 2>/dev/null');
        if (!file_exists($sentinel)) {
            $this->markTestSkipped('this git build does not fire a custom merge driver on conflict');
        }
        @unlink($sentinel);
        exec('git -C ' . escapeshellarg($repo) . ' merge --abort 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' reset --hard HEAD 2>/dev/null');

        // The fix: Git::merge() enumerates merge.<name>.driver keys and
        // overrides each with `false` (inert, exits non-zero => git reports a
        // plain CONFLICT). Driver never runs; user still sees git's conflict.
        try {
            (new Git($repo))->merge('conflicting');
            self::fail('expected RuntimeException on a conflicting merge');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('CONFLICT', $e->getMessage());
        }

        self::assertFalse(file_exists($sentinel), 'repo-controlled merge driver executed through Git::merge()');
    }

    public function testWorktreeConfigMergeDriverNeverExecutesOnMerge(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/mergedrv_wtd_fired.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeMergeDriverRepo($sentinel, true);

        exec('git -C ' . escapeshellarg($repo) . ' merge conflicting 2>/dev/null');
        if (!file_exists($sentinel)) {
            $this->markTestSkipped('this git build does not honor config.worktree merge drivers');
        }
        @unlink($sentinel);
        exec('git -C ' . escapeshellarg($repo) . ' merge --abort 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' reset --hard HEAD 2>/dev/null');

        try {
            (new Git($repo))->merge('conflicting');
            self::fail('expected RuntimeException on a conflicting merge');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('CONFLICT', $e->getMessage());
        }

        self::assertFalse(
            file_exists($sentinel),
            'merge driver defined only in .git/config.worktree executed through Git::merge() — '
            . 'disarm enumeration must use the merged scope, not --local',
        );
    }

    public function testUnrepresentableMergeDriverKeyRefusesTheMerge(): void
    {
        $this->requireGit();

        $repo = $this->makeRepo();
        // Hand-written so the subsection carries a character outside the set
        // that -c can re-express unambiguously (the CLI itself would accept
        // it — we do not trust parser accidents for a security door).
        $cfg = (string) file_get_contents($repo . '/.git/config');
        file_put_contents($repo . '/.git/config', $cfg . "\n[merge \"evil~key\"]\n\tdriver = /bin/true\n");

        try {
            (new Git($repo))->merge('HEAD');
            self::fail('expected refusal when a merge driver key cannot be disarmed');
        } catch (\RuntimeException $e) {
            self::assertSame(\SugarCraft\Git\Lang::t('git.unsafe_merge_driver'), $e->getMessage());
        }
    }

    public function testMergeCapableArgvCarriesDriverDisarms(): void
    {
        $this->requireGit();

        $repo = $this->makeRepo();
        exec('git -C ' . escapeshellarg($repo) . ' config merge.evil.driver "/bin/true %A %O %B" 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' config merge.MyEvil2.driver "/bin/true" 2>/dev/null');

        $argv = $this->callDisarmedArgv($repo, ['merge', '--no-edit', '--', 'x']);
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $repo,
            '-c', 'merge.evil.driver=false',
            '-c', 'merge.MyEvil2.driver=false',
            'merge', '--no-edit', '--', 'x',
        ], $argv, 'every enumerated driver (case-preserving subsection) must be overridden before the subcommand');

        // Case preserved, deduped; a non-merge-capable verb gets none of it.
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $repo,
            'status', '--porcelain=v1',
        ], $this->callDisarmedArgv($repo, ['status', '--porcelain=v1']));
        // cherry-pick --abort must NOT carry the disarms (teardown-only verb),
        // bare cherry-pick and rebase --continue must.
        self::assertStringNotContainsString('.driver=false', implode("\0", $this->callDisarmedArgv($repo, ['cherry-pick', '--abort'])));
        self::assertStringContainsString('merge.evil.driver=false', implode("\0", $this->callDisarmedArgv($repo, ['cherry-pick', 'abc123'])));
        self::assertStringContainsString('merge.evil.driver=false', implode("\0", $this->callDisarmedArgv($repo, ['rebase', '--continue'])));
        self::assertStringContainsString('merge.evil.driver=false', implode("\0", $this->callDisarmedArgv($repo, ['stash', 'apply'])));
        self::assertStringNotContainsString('.driver=false', implode("\0", $this->callDisarmedArgv($repo, ['rebase', '--abort'])));
    }

    /** @param list<string> $args */
    private function callDisarmedArgv(string $cwd, array $args): array
    {
        $method = new \ReflectionMethod(Git::class, 'argvWithDisarms');
        $method->setAccessible(true);

        return $method->invoke(new Git($cwd), $args);
    }

    // ------------------------------------------- filter channel (real git, round 2 M-1)

    /**
     * Shared setup for the filter pins: a repo whose LOCAL config routes
     * *.txt through `filter.evil.clean` + `filter.evil.smudge` executables
     * (each touches $sentinel and pipes content through), .gitattributes
     * committed so git actually consults the filter. Under $worktreeScope the
     * smudge lane lives only in `.git/config.worktree` (extensions.
     * worktreeConfig) — reachable by git, invisible to --local.
     */
    private function makeFilterDriverRepo(string $sentinel, bool $worktreeScope = false): string
    {
        $repo   = $this->makeRepo();
        $driver = $this->writeExecutable($this->cwd(), 'evil-filter-' . bin2hex(random_bytes(4)) . '.sh',
            "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\nexec cat\n");

        if ($worktreeScope) {
            exec('git -C ' . escapeshellarg($repo) . ' config extensions.worktreeConfig true 2>/dev/null');
            exec('git -C ' . escapeshellarg($repo) . ' config --worktree filter.wt.smudge '
                . escapeshellarg($driver) . ' 2>/dev/null');
        } else {
            exec('git -C ' . escapeshellarg($repo) . ' config filter.evil.clean ' . escapeshellarg($driver) . ' 2>/dev/null');
            exec('git -C ' . escapeshellarg($repo) . ' config filter.evil.smudge ' . escapeshellarg($driver) . ' 2>/dev/null');
        }

        // chr(42): a glued '*.txt ...' literal is glob-shaped and would drift
        // the PathGlob corpus census (GlobDialect law).
        file_put_contents($repo . '/.gitattributes', chr(42) . '.txt filter=' . ($worktreeScope ? 'wt' : 'evil') . "\n");
        exec('git -C ' . escapeshellarg($repo) . ' add .gitattributes 2>/dev/null');
        exec('git -C ' . escapeshellarg($repo) . ' commit -m attrs 2>/dev/null');
        // Setup's own add/commit can touch the sentinel; the pins below measure
        // EXECUTION DURING THE TESTED COMMAND only, so hand over a clean slate.
        @unlink($sentinel);

        return $repo;
    }

    /** Path of the stderr-capturing child fixture (see the file for its contract). */
    private static function filterNoticeChild(): string
    {
        return __DIR__ . '/Support/FilterNoticeChild.php';
    }

    private function callFilterLanes(string $cwd): array
    {
        $method = new \ReflectionMethod(Git::class, 'filterDriverLanes');
        $method->setAccessible(true);

        return $method->invoke(new Git($cwd));
    }

    public function testFilterDriversAreAnnouncedOnceAndStillExecuteInDefaultMode(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/filterdrv_fired.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeFilterDriverRepo($sentinel);
        file_put_contents($repo . '/file.txt', "line 1\nline 2\ndirty\n");

        // Control: the vulnerability R2 measured is real on this build — a
        // plain `git add` fires filter.clean. Without it the pins below would
        // pass vacuously, so skip.
        exec('git -C ' . escapeshellarg($repo) . ' add -A 2>/dev/null');
        if (!file_exists($sentinel)) {
            $this->markTestSkipped('this git build does not fire filter.clean on add');
        }
        @unlink($sentinel);
        exec('git -C ' . escapeshellarg($repo) . ' reset -q 2>/dev/null');

        // The lib path: a child process so STDERR is capturable. Two
        // filter-capable commands on ONE Git instance must produce EXACTLY
        // one notice (per-instance latch), and the drivers still RUN — the
        // posture is detect+announce, never silent, never disarmed (git-lfs
        // rationale; the blanket disarm was rejected by ruling).
        $r = Process::run(['php', $this->filterNoticeChild(), $repo], null, 30.0);

        self::assertSame(0, $r['exit'], 'child failed: ' . $r['stderr']);
        self::assertSame("EXIT-OK\n", $r['stdout']);
        self::assertSame(
            1,
            substr_count($r['stderr'], 'candy-git: notice:'),
            'filter notice must fire exactly once per instance across both filter-capable commands; stderr was: ' . $r['stderr'],
        );
        self::assertStringContainsString('evil.clean', $r['stderr'], 'notice must name the detected lanes');
        self::assertStringContainsString('evil.smudge', $r['stderr']);
        self::assertStringContainsString(Git::STRICT_FILTERS_ENV, $r['stderr'], 'notice must point at the strict-mode opt-in');
        self::assertFileExists($sentinel, 'default mode announces but must still run the drivers (git-lfs-class tooling)');
    }

    public function testZeroFilterDriverReposSeeNoNoticeAndUntouchedArgv(): void
    {
        $this->requireGit();

        $repo = $this->makeRepo();
        self::assertSame([], $this->callFilterLanes($repo));

        // Critical posture: the overwhelmingly common case changes NOTHING.
        $r = Process::run(['php', $this->filterNoticeChild(), $repo, 'zero'], null, 30.0);
        self::assertSame(0, $r['exit'], $r['stderr']);
        self::assertSame('', $r['stderr'], 'a repo without filter drivers must never see a notice');

        // Byte-identity of argv: the guard has no channel into the command.
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $repo,
            'add', '-A',
        ], $this->callDisarmedArgv($repo, ['add', '-A']));
    }

    public function testFilterDriversPresentStillNeverAlterArgv(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/filterdrv_argv.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeFilterDriverRepo($sentinel);

        // The BYTE-IDENTITY pin (with lanes DETECTED the command line stays
        // exactly the plain prelude — no -c filter overrides, ever).
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $repo,
            'add', '-A',
        ], $this->callDisarmedArgv($repo, ['add', '-A']));
        self::assertSame([
            'git', '-c', 'core.quotepath=off', '-c', 'core.fsmonitor=false', '-C', $repo,
            'diff', '--no-color', '--no-ext-diff', '--', 'file.txt',
        ], $this->callDisarmedArgv($repo, ['diff', '--no-color', '--no-ext-diff', '--', 'file.txt']));

        // On a verb that IS merge-capable too, only the merge disarms ride —
        // never a filter override (that is the rejected-by-ruling shape).
        exec('git -C ' . escapeshellarg($repo) . ' config merge.evil.driver "/bin/true %A %O %B" 2>/dev/null');
        $merged = $this->callDisarmedArgv($repo, ['merge', '--no-edit', '--', 'x']);
        self::assertContains('merge.evil.driver=false', $merged);
        self::assertStringNotContainsString('filter.', implode("\0", $merged));
    }

    public function testFilterLanesEnumerateNamedDriversFromMergedScope(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/filterdrv_lanes.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeFilterDriverRepo($sentinel);

        $lanes = $this->callFilterLanes($repo);
        sort($lanes);
        self::assertSame(['evil.clean', 'evil.smudge'], $lanes, 'each named filter lane is name.kind, deduplicated');

        // config.worktree is part of the merged scope git consults — a
        // --local enumeration would miss this lane (the merge pins learned
        // this on git 2.43; same probe, same scope).
        $wtSentinel = $this->cwd() . '/filterdrv_wt.' . bin2hex(random_bytes(4));
        $this->trash[] = $wtSentinel;
        $wtRepo = $this->makeFilterDriverRepo($wtSentinel, true);
        self::assertContains('wt.smudge', $this->callFilterLanes($wtRepo));
    }

    public function testStrictEnvRefusesFilterCapableCommandsAndRunsNothing(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/filterdrv_strict.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeFilterDriverRepo($sentinel);

        putenv(Git::STRICT_FILTERS_ENV . '=1');
        try {
            try {
                (new Git($repo))->stageAll();
                self::fail('expected typed refusal under strict filter mode');
            } catch (\RuntimeException $e) {
                self::assertSame(\SugarCraft\Git\Lang::t('git.unsafe_filter_driver'), $e->getMessage());
            }
            self::assertFalse(file_exists($sentinel), 'strict mode must refuse BEFORE the command (and its filters) run');

            // Strict + zero-driver repo: nothing to refuse — commands proceed
            // untouched (strict must not tax honest repositories).
            (new Git($this->makeRepo()))->stageAll();
        } finally {
            putenv(Git::STRICT_FILTERS_ENV);
        }
    }

    public function testWithStrictFiltersDerivesBothDirections(): void
    {
        $this->requireGit();

        $sentinel = $this->cwd() . '/filterdrv_derive.' . bin2hex(random_bytes(4));
        $this->trash[] = $sentinel;
        $repo = $this->makeFilterDriverRepo($sentinel);

        $base = new Git($repo);
        self::assertFalse($base->strictFilters);

        // Opt IN: the derived instance refuses with the typed message...
        $strict = $base->withStrictFilters();
        self::assertTrue($strict->strictFilters);
        self::assertFalse($base->strictFilters, 'derive must be immutable');
        try {
            $strict->stageAll();
            self::fail('expected typed refusal from withStrictFilters()');
        } catch (\RuntimeException $e) {
            self::assertSame(\SugarCraft\Git\Lang::t('git.unsafe_filter_driver'), $e->getMessage());
        }

        // Opt OUT again (env was strict): explicit false beats the env.
        file_put_contents($repo . '/file.txt', "line 1\nline 2\ndirty\n");
        putenv(Git::STRICT_FILTERS_ENV . '=yes');
        try {
            self::assertTrue((new Git($repo))->strictFilters, 'yes must read as truthy');
            $relaxed = (new Git($repo))->withStrictFilters(false);
            self::assertFalse($relaxed->strictFilters);
            $relaxed->stageAll(); // announces (parent-stderr noise is fine), never throws
            self::assertFileExists($sentinel);
        } finally {
            putenv(Git::STRICT_FILTERS_ENV);
        }
    }

    public function testUnrepresentableFilterKeyCountsAsUnnamedLaneAndRefusesInStrict(): void
    {
        $this->requireGit();

        $repo = $this->makeRepo();
        // Hand-written so the subsection carries a character the lane parser
        // cannot name — the driver still executes at git's side, so the lane
        // exists and must not stay silent (fail closed in strict mode).
        $cfg = (string) file_get_contents($repo . '/.git/config');
        file_put_contents($repo . '/.git/config', $cfg . "\n[filter \"bad name\"]\n\tprocess = /bin/true\n");

        self::assertSame(['<unnamed>'], $this->callFilterLanes($repo));

        putenv(Git::STRICT_FILTERS_ENV . '=1');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(\SugarCraft\Git\Lang::t('git.unsafe_filter_driver'));
            (new Git($repo))->stageAll();
        } finally {
            putenv(Git::STRICT_FILTERS_ENV);
        }
    }

    // ------------------------------------------- A6: honest failure detail (real git)

    public function testMergeConflictFailureCarriesGitsOwnExplanation(): void
    {
        $this->requireGit();

        $repo = $this->makeRepo();
        $base = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --abbrev-ref HEAD'));

        // Diverged same-line commits: merge is guaranteed to conflict.
        exec('git -C ' . escapeshellarg($repo) . ' checkout -b conflicting 2>/dev/null');
        file_put_contents($repo . '/file.txt', "line 1\nother branch line\nline 3\n");
        exec('git -C ' . escapeshellarg($repo) . " commit -aqm other 2>/dev/null");
        exec('git -C ' . escapeshellarg($repo) . ' checkout ' . escapeshellarg($base) . ' 2>/dev/null');
        file_put_contents($repo . '/file.txt', "line 1\nmaster branch line\nline 3\n");
        exec('git -C ' . escapeshellarg($repo) . " commit -aqm ours 2>/dev/null");

        try {
            (new Git($repo))->merge('conflicting');
            self::fail('expected RuntimeException on a conflicting merge');
        } catch (\RuntimeException $e) {
            // git puts the CONFLICT lines on STDOUT while stderr stays empty
            // for a content conflict — the old `trim(stderr)` lane rendered an
            // empty message and the user saw a silent no-op.
            self::assertStringContainsString('CONFLICT', $e->getMessage());
        }
    }

    public function testMergeRunsNonInteractively(): void
    {
        $src = (string) file_get_contents((new \ReflectionClass(Git::class))->getFileName());

        // --no-edit: a merge that needs a message must not wait on an editor
        // (childEnv pins GIT_EDITOR, but the flag is the belt to that brace).
        self::assertStringContainsString("'merge', '--no-edit'", $src);
    }

    public function testErrorDetailFallsBackToStdoutWhenStderrEmpty(): void
    {
        $method = new \ReflectionMethod(Git::class, 'errorDetail');
        $method->setAccessible(true);

        self::assertSame('stderr says no', $method->invoke(null, ['stderr' => " stderr says no \n", 'stdout' => 'ignored']));
        self::assertSame('CONFLICT (content): Merge conflict in f', $method->invoke(null, ['stderr' => '', 'stdout' => "CONFLICT (content): Merge conflict in f\n"]));
        self::assertSame('', $method->invoke(null, ['stderr' => '', 'stdout' => '']));
    }

    // ------------------------------------------------------------------ helpers

    private function requireGit(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
    }

    private function cwd(): string
    {
        return sys_get_temp_dir();
    }

    private function makeRepo(): string
    {
        $dir = sys_get_temp_dir() . '/githarden_' . uniqid();
        $this->trash[] = $dir;

        mkdir($dir);
        exec('git init ' . escapeshellarg($dir) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($dir) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($dir . '/file.txt', "line 1\nline 2\nline 3\n");
        exec('git -C ' . escapeshellarg($dir) . ' add file.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . " commit -m initial 2>/dev/null");

        return $dir;
    }

    private function writeExecutable(string $dir, string $name, string $body): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, $body);
        chmod($path, 0755);

        return $path;
    }
}
