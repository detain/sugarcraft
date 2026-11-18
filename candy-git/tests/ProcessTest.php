<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\Platform;
use SugarCraft\Git\Process;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    /**
     * Regression: a wedged child must be killed at the deadline, not run to
     * completion. Reverting the stream_select/proc_terminate timeout makes this
     * block for the full `sleep 3` and blow the elapsed-time assertion.
     */
    public function testTimesOutAndKillsSlowCommand(): void
    {
        $start = microtime(true);
        $r = Process::run(['sleep', '3'], null, 0.2);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($r['timedOut'], 'slow command should report timedOut');
        // candy-git-117: the 2.0s ceiling over a 0.2s budget is deliberately
        // generous (kill grace + CI scheduler jitter), pinned documented-not-
        // tightened; the lower bound proves the deadline was actually WAITED
        // on rather than the child dying instantly.
        $this->assertGreaterThanOrEqual(0.15, $elapsed, 'the kill must ride the 0.2s deadline, not beat it');
        $this->assertLessThan(
            2.0,
            $elapsed,
            'the child should be killed near the 0.2s timeout, not allowed to sleep for 3s',
        );
    }

    public function testRunsFastCommandToCompletion(): void
    {
        $r = Process::run(['git', '--version'], null, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertStringContainsString('git', $r['stdout']);
    }

    public function testWritesStdinAndCapturesStdout(): void
    {
        $r = Process::run(['cat'], "payload\n", 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame("payload\n", $r['stdout']);
    }

    public function testReportsNonZeroExitWithoutTimingOut(): void
    {
        $r = Process::run(['sh', '-c', 'exit 7'], null, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(7, $r['exit']);
    }

    /**
     * Regression (audit #6): a 200KB stdin against a child that exits without
     * ever reading it used to be one blocking fwrite past the 64KiB pipe
     * capacity — EPIPE warning at best, wedge-the-thread deadlock at worst.
     * The write side now rides the same select loop, so the refusal is silent
     * and the child's exit code still arrives.
     */
    public function testLargeStdinToChildThatExitsEarlyDoesNotDeadlock(): void
    {
        $start = microtime(true);
        $r     = Process::run(['sh', '-c', 'exit 3'], str_repeat('x', 200_000), 5.0);

        $this->assertFalse($r['timedOut'], 'early-exiting child must not consume the timeout budget');
        $this->assertSame(3, $r['exit']);
        $this->assertLessThan(4.0, microtime(true) - $start);
    }

    /** The incremental writer must deliver every byte in order before closing. */
    public function testLargeStdinRoundTripsByteIdentical(): void
    {
        $payload = str_repeat('y', 200_000);
        $r       = Process::run(['cat'], $payload, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame($payload, $r['stdout']);
    }

    /** `head` reads a slice then exits: the remaining writes must fail silently. */
    public function testLargeStdinToTruncatingChildReportsItsOutput(): void
    {
        $r = Process::run(['head', '-c', '5'], str_repeat('z', 200_000), 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame('zzzzz', $r['stdout']);
    }

    /**
     * A live child that never reads pins the DEADLINE over the write phase:
     * the old pre-loop blocking fwrite ignored $timeout entirely and hung the
     * suite; now the budget expiry must kill the child at ~0.5s.
     */
    public function testWritePhaseStaysWithinTimeoutBudget(): void
    {
        // candy-git-117: no `sh -c 'sleep N; exit 0'` wrapper — the shell dies
        // at the deadline and would orphan the sleep it spawned; the direct
        // argv child IS the sleeper, so the kill reaps everything. (sleep is
        // on Git-bash PATH on Windows runners, same as the old sh arm.)
        $start = microtime(true);
        $r     = Process::run(['sleep', '5'], str_repeat('w', 200_000), 0.5);

        $this->assertTrue($r['timedOut'], 'a full pipe plus idle child must hit the deadline');
        // Generous ceiling, deliberately not tightened (candy-git-117).
        $this->assertLessThan(2.5, microtime(true) - $start);
    }

    /**
     * A27: a runaway child writing more than the capture cap must not be
     * accumulated whole in memory — the head is kept and a visible marker
     * rides at the end so Git-level failures explain the truncation.
     */
    public function testStdoutOverTheCapIsHeadKeptWithOneVisibleNotice(): void
    {
        $r = Process::run(
            // Size spelled out: the bare -r child cannot see the class constant.
            ['php', '-r', 'echo str_repeat("x", 4 * 1024 * 1024 + 65536);'],
            null,
            15.0,
        );

        $this->assertFalse($r['timedOut']);
        $this->assertLessThanOrEqual(
            Process::MAX_CAPTURE + strlen("\n[candy-git: output truncated at 4 MiB]"),
            strlen($r['stdout']),
        );
        $this->assertStringContainsString('[candy-git: output truncated at 4 MiB]', $r['stdout']);
        $this->assertStringStartsWith('xxxx', $r['stdout']);
        $this->assertTrue($r['truncated'], 'the flag must say what the notice says (candy-git-040)');
    }

    public function testStderrOverTheCapCarriesTheSameNotice(): void
    {
        $r = Process::run(
            ['php', '-r', 'fwrite(STDERR, str_repeat("e", 4 * 1024 * 1024 + 65536));'],
            null,
            15.0,
        );

        $this->assertStringContainsString('[candy-git: output truncated at 4 MiB]', $r['stderr']);
        $this->assertLessThanOrEqual(
            Process::MAX_CAPTURE + strlen("\n[candy-git: output truncated at 4 MiB]"),
            strlen($r['stderr']),
        );
        $this->assertTrue($r['truncated']);
    }

    public function testOutputUnderTheCapCarriesNoTruncationNotice(): void
    {
        $r = Process::run(
            ['php', '-r', 'echo str_repeat("s", 100000);'],
            null,
            10.0,
        );

        $this->assertSame(100_000, strlen($r['stdout']));
        $this->assertStringNotContainsString('truncated', $r['stdout']);
        $this->assertFalse($r['truncated']);
    }

    /** The Windows ladder, exercised on any host by injecting the null device. */
    private static function windowsLadder(): Platform
    {
        // The seam under test is the WINDOWS code path, not the device name:
        // inject the host's own null device so the legs open stdin on real
        // Windows runners too (hardcoded /dev/null is unopenable there —
        // every stdin-less polled-ladder leg died at proc_open, F12b).
        return Platform::windows()->withNullDevice(Platform::new()->nullDevice());
    }

    public function testPlatformSeamAnswersBothBranches(): void
    {
        $this->assertFalse(Platform::posix()->isWindows());
        $this->assertSame('/dev/null', Platform::posix()->nullDevice());
        $this->assertTrue(Platform::windows()->isWindows());
        $this->assertSame('NUL', Platform::windows()->nullDevice());
        $this->assertSame(PHP_OS_FAMILY === 'Windows', Platform::new()->isWindows());
        $this->assertSame('x', Platform::posix()->withNullDevice('x')->nullDevice());
    }

    public function testFindExecutableResolvesOnPathAndAnswersNullWhenAbsent(): void
    {
        // The old fixture hardcoded '/bin:/usr/bin' + 'sh' - POSIX host data
        // that was itself the Windows red (ci-wave-3 L4). Ask the seam for the
        // host's own truth instead: a probe file this OS actually considers
        // spawnable, plus the negative legs (absent name, absent dir, and on
        // POSIX the mode-bit law: a 644 file must NOT resolve).
        $windows  = DIRECTORY_SEPARATOR === '\\';
        $platform = $windows ? Platform::windows() : Platform::posix();
        $dir      = sys_get_temp_dir() . '/cgprobe_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $name = 'candygit_probe';
        $file = $dir . DIRECTORY_SEPARATOR . $name . ($windows ? '.cmd' : '');
        try {
            file_put_contents($file, $windows ? "@echo off\r\nexit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
            if (!$windows) {
                chmod($file, 0o755);
            }
            $this->assertNotNull(
                $platform->findExecutable($name, $dir . ($windows ? ';' . $dir : '')),
                'a host-native executable on the search path must resolve',
            );
            $this->assertNull(
                $platform->findExecutable('definitely-not-a-binary-xyz', $dir),
                'an absent name must answer null, not false-negative into a path',
            );
            $this->assertNull(
                $platform->findExecutable($name, $dir . '-nonexistent' . ($windows ? ';' . $dir . '-also-absent' : '')),
                'searching only absent directories must answer null',
            );
            if (!$windows) {
                chmod($file, 0o644);
                clearstatcache();
                $this->assertNull(
                    $platform->findExecutable($name, $dir),
                    'POSIX must not resolve a file without the mode bit',
                );
            }
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * ci-wave-4 door red: CreateProcess refuses a program path carrying a '/'
     * (error 267 - mixed-separator PATH entry from sys_get_temp_dir()
     * concatenation) and refuses batch files outright (error 193 - a .cmd is
     * not a PE image). The seam must hand runPolled a program it can actually
     * launch, so the fold and the cmd.exe /d /c route are pinned with an
     * explicit search path that answers identically on both hosts - on Linux
     * the Windows candidates are real files whose names contain a literal
     * backslash, which is exactly the spelling findExecutable builds.
     */
    public function testResolveSpawnProgramFoldsSeparatorsAndRoutesBatchShimsThroughCmd(): void
    {
        $base    = sys_get_temp_dir() . '/cgrespawn_' . bin2hex(random_bytes(4));
        $exeDir  = $base . '_exe';
        $cmdDir  = $base . '_cmd';
        $batDir  = $base . '_bat';
        mkdir($exeDir);
        mkdir($cmdDir);
        mkdir($batDir);
        // .exe + .cmd twins in one dir pin the PATHEXT family order too:
        // a real Git\cmd install ships git.exe and the fold must not route it
        // through the interpreter.
        file_put_contents($exeDir . '\\git.exe', 'MZ-fake-image');
        file_put_contents($exeDir . '\\git.cmd', "@echo off\r\nexit /b 0\r\n");
        file_put_contents($cmdDir . '\\git.cmd', "@echo off\r\nexit /b 0\r\n");
        file_put_contents($batDir . '\\git.bat', "@echo off\r\nexit /b 0\r\n");
        $fold = static fn (string $p): string => str_replace('/', '\\', $p);
        $files = [$exeDir . '\\git.exe', $exeDir . '\\git.cmd', $cmdDir . '\\git.cmd', $batDir . '\\git.bat'];
        try {
            $windows = Platform::windows();

            $this->assertSame(
                [$fold($exeDir . '\\git.exe'), '--version'],
                $windows->resolveSpawnProgram(['git', '--version'], $exeDir),
                'an .exe resolves, folds to all-backslash, and is NOT routed through cmd.exe',
            );
            $this->assertSame(
                ['cmd.exe', '/d', '/c', $fold($cmdDir . '\\git.cmd'), '--version', '-C', 'x'],
                $windows->resolveSpawnProgram(['git', '--version', '-C', 'x'], $cmdDir),
                'a lone .cmd shim must launch the way cmd.exe would run it, args trailing',
            );
            $this->assertSame(
                ['cmd.exe', '/d', '/c', $fold($batDir . '\\git.bat')],
                $windows->resolveSpawnProgram(['git'], $batDir),
                '.bat rides the same interpreter route as .cmd',
            );
            $this->assertSame(
                ['./git.exe', 'status'],
                $windows->resolveSpawnProgram(['./git.exe', 'status'], $exeDir),
                'a program that already spells a path is handed back untouched (L4 contract)',
            );
            $this->assertSame(
                ['definitely-not-a-binary-xyz', 'a'],
                $windows->resolveSpawnProgram(['definitely-not-a-binary-xyz', 'a'], $exeDir),
                'an unresolvable name stays bare so spawnFailure keeps the honest OS reason',
            );
            $this->assertSame(
                ['git', '--version'],
                Platform::posix()->resolveSpawnProgram(['git', '--version'], $cmdDir),
                'POSIX never rewrites - the seam is Windows-only by contract',
            );
            $this->assertSame(
                ['definitely-not-a-binary-xyz', 'a'],
                $windows->resolveSpawnProgram(['definitely-not-a-binary-xyz', 'a']),
                'the live-env call shape runPolled uses answers unchanged for an absent name on any host',
            );
        } finally {
            // On Linux the candidates are single filenames with a literal
            // backslash (sitting next to the dirs); on Windows they are inside
            // them. Unlink by exact path either way - glob would eat the
            // backslash as an escape character.
            foreach ($files as $f) {
                @unlink($f);
            }
            foreach ([$exeDir, $cmdDir, $batDir] as $d) {
                @rmdir($d);
            }
        }
    }

    public function testPolledLadderCapturesStdoutStderrExitAndStdin(): void
    {
        $r = Process::run(['sh', '-c', 'cat; echo err >&2; exit 3'], "hello\n", 5.0, self::windowsLadder());

        $this->assertSame("hello\n", $r['stdout']);
        $this->assertSame("err\n", $r['stderr']);
        $this->assertSame(3, $r['exit']);
        $this->assertFalse($r['timedOut']);
    }

    public function testPolledLadderGivesNullStdinAnImmediateEof(): void
    {
        $r = Process::run(['sh', '-c', 'cat; echo done'], null, 5.0, self::windowsLadder());

        $this->assertSame("done\n", $r['stdout']);
        $this->assertSame(0, $r['exit']);
    }

    public function testPolledLadderTimesOutThroughTheInjectedTreeKill(): void
    {
        $killed = [];
        $platform = self::windowsLadder()->withKillTree(static function (int $pid) use (&$killed): void {
            $killed[] = $pid;
            // This leg exercises the LADDER honouring the seam, so the closure
            // has to actually stop the child on whatever host runs the test:
            // kill -9 is POSIX-only, and cmd.exe answers it with "The system
            // cannot find the path specified." while the 2.0s terminate grace
            // burns the budget (ci-wave-3 L4 runner red). The real Windows
            // stop path - Platform's default msys group-kill killTree - is
            // survivor-roster territory that needs an interactive box, and is
            // deliberately NOT what this injected stand-in stands in for.
            if (DIRECTORY_SEPARATOR === '\\') {
                exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
            } else {
                exec('kill -9 ' . $pid);
            }
        });

        $start = microtime(true);
        $r = Process::run(['sleep', '3'], null, 0.3, $platform);

        $this->assertTrue($r['timedOut']);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertCount(1, $killed);
        $this->assertGreaterThan(0, $killed[0]);
    }

    public function testPolledLadderCapsOutputWithOneNotice(): void
    {
        $r = Process::run(['sh', '-c', 'head -c 5000000 /dev/zero | tr "\\0" a'], null, 20.0, self::windowsLadder());

        $this->assertStringEndsWith('[candy-git: output truncated at 4 MiB]', $r['stdout']);
        $this->assertSame(1, substr_count($r['stdout'], 'truncated at 4 MiB'));
        $this->assertTrue($r['truncated'], 'the polled ladder reports the same flag (candy-git-040)');
    }

    public function testSpawnFailureCarriesTheReasonNotTheMissingGitHint(): void
    {
        try {
            Process::run(['git'], null, 1.0, Platform::posix()->withNullDevice('/nonexistent/dir/null'));
            $this->fail('expected a spawn failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to spawn', $e->getMessage());
            $this->assertStringNotContainsString('{reason}', $e->getMessage());
            $this->assertStringNotContainsString('install git', $e->getMessage());
        }
    }

    /**
     * candy-git-006: a SIGWINCH (terminal resize) landing while the child is
     * live makes stream_select() return false — the old code treated that as
     * abandon and tore down a perfectly healthy git call. The child raises the
     * signal at its own parent here so the interrupt lands squarely inside the
     * blocking select. After the fix the wait CONTINUES against the absolute
     * deadline and the child answers in full.
     */
    public function testSigwinchDuringDrainDoesNotKillTheChild(): void
    {
        if (!extension_loaded('pcntl') || !defined('SIGWINCH')) {
            $this->markTestSkipped('pcntl (SIGWINCH) required');
        }

        // A no-op handler must be installed for select() to see the EINTR at
        // all: SIG_DFL/SIG_IGN dispositions for WINCH never interrupt it.
        pcntl_signal(SIGWINCH, static function (): void {
        });
        try {
            $r = Process::run(
                ['sh', '-c', 'kill -WINCH $PPID 2>/dev/null; sleep 0.2; echo done'],
                null,
                10.0,
            );
        } finally {
            pcntl_signal(SIGWINCH, SIG_DFL);
        }

        $this->assertFalse($r['timedOut'], 'an ordinary resize must not read as a timeout');
        $this->assertFalse($r['interrupted'], 'a single EINTR must not abandon the drain');
        $this->assertSame("done\n", $r['stdout']);
        $this->assertSame(0, $r['exit']);
    }

    /**
     * candy-git-007: closing the capture pipes is NOT the child exiting. This
     * child detaches its own stdio and then sleeps 4s while our loop sees
     * pipe-EOF immediately — the old code fell straight into a blocking
     * proc_close() that waited out the full sleep OUTSIDE the timeout budget.
     * After the fix the post-drain liveness poll spends the remaining budget,
     * flags timedOut, and runs the bounded kill ladder.
     */
    public function testAliveChildAfterPipeEofIsKilledAtTheDeadline(): void
    {
        // candy-git-117: the second `exec` REPLACES the shell with sleep, so
        // the bounded kill ladder at the deadline reaps the sleeper itself —
        // the old `sh -c 'exec ...; sleep 4'` shape detached its fds in the
        // shell and left a grandchild sleep running past the test.
        $start = microtime(true);
        $r     = Process::run(['sh', '-c', 'exec 1>/dev/null 2>&1; exec sleep 4'], null, 0.5);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($r['timedOut'], 'a child that outlives the budget after pipe-EOF must report timedOut');
        $this->assertFalse($r['interrupted'], 'this is the deadline lane, not a signal storm');
        $this->assertGreaterThanOrEqual(0.4, $elapsed, 'the full budget must be spent before the kill');
        $this->assertLessThan(2.5, $elapsed, 'killed at deadline+grace, never left to finish the 4s sleep');
    }

    /** Healthy children on both ladders report neither marker. */
    public function testNormalLanesCarryNoTimeoutOrInterruptedMarkers(): void
    {
        $posix = Process::run(['sh', '-c', 'echo hi'], null, 5.0);
        $this->assertFalse($posix['timedOut']);
        $this->assertFalse($posix['interrupted']);
        $this->assertSame("hi\n", $posix['stdout']);
        $this->assertFalse($posix['truncated']);

        $polled = Process::run(['sh', '-c', 'echo hi'], null, 5.0, self::windowsLadder());
        $this->assertFalse($polled['timedOut']);
        $this->assertFalse($polled['interrupted']);
        $this->assertSame("hi\n", $polled['stdout']);
        $this->assertFalse($polled['truncated']);
    }

    /**
     * Regression: output of exactly MAX_CAPTURE bytes is complete, not
     * truncated. The old copy set the flag as soon as the retained length
     * reached the cap, even with nothing refused, so an exact-size answer got
     * a false notice and a false truncated marker.
     */
    public function testOutputExactlyAtTheCapIsNotFlaggedTruncated(): void
    {
        $r = Process::run(
            ['php', '-r', 'echo str_repeat("x", 4 * 1024 * 1024);'],
            null,
            15.0,
        );

        $this->assertSame(Process::MAX_CAPTURE, strlen($r['stdout']));
        $this->assertStringNotContainsString('truncated at 4 MiB', $r['stdout']);
        $this->assertFalse($r['truncated']);
    }

    /** A non-positive timeout keeps its meaning: no deadline, so a slow child completes. */
    public function testNonPositiveTimeoutDisablesTheDeadline(): void
    {
        $r = Process::run(['sh', '-c', 'sleep 0.3; echo ok'], null, 0.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame("ok\n", $r['stdout']);
    }

    public function testTheGitRedirectFamilyIsStrippedFromTheChildEnv(): void
    {
        // candy-git-041: `git -C` does not outvote these; a hook-inherited
        // redirect would silently aim every operation at another repository.
        $env = Process::childEnv([
            'GIT_DIR'           => '/evil/.git',
            'GIT_INDEX_FILE'    => '/evil/index',
            'GIT_WORK_TREE'     => '/evil',
            'GIT_COMMON_DIR'    => '/evil/common',
            'GIT_OBJECT_DIRECTORY' => '/evil/objects',
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => '/evil/alt',
            'GIT_NAMESPACE'     => 'evil',
            'GIT_PREFIX'        => 'evil/',
            'GIT_CONFIG_GLOBAL' => '/mine/config',
            'PATH'              => '/usr/bin',
        ]);

        foreach (Process::GIT_REDIRECT_VARS as $name) {
            $this->assertArrayNotHasKey($name, $env, $name . ' must never reach the child');
        }

        $this->assertSame('/mine/config', $env['GIT_CONFIG_GLOBAL'], 'operator config choice survives the strip');
        $this->assertSame('/usr/bin', $env['PATH']);
        $this->assertSame('0', $env['GIT_TERMINAL_PROMPT']);
        $this->assertSame('cat', $env['GIT_PAGER']);
        $this->assertSame('C', $env['LC_ALL']);
    }

    public function testThePinnedOverridesWinOverTheInheritedEnvironment(): void
    {
        $env = Process::childEnv(['GIT_EDITOR' => 'vim', 'LC_ALL' => 'de_DE.UTF-8']);

        $this->assertSame('true', $env['GIT_EDITOR']);
        $this->assertSame('C', $env['LC_ALL']);
    }
}
