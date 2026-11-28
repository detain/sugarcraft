<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Code\Support\WindowsTaskKill;

/**
 * C-TUI-6.a: the Windows tree-kill and the containment Windows arms that
 * route into it, EXEC-tested as data on this host.
 *
 * The argv table, the pid refusal edge and the runner routing are pure
 * arithmetic and run everywhere. The DEFAULT runner — spawn through
 * spawnSpec, drain both pipes under a declared bound, answer from the exit
 * status — is OS-independent machinery too: it is exercised here against
 * binaries that exist on Linux (`/bin/true`, `/bin/false`, `/bin/sleep`),
 * which proves the drain terminates, the bound fires, and the child is
 * reaped on every leg WITHOUT ever pretending a Windows taskkill ran. Only
 * the argv WORDS are the Windows fact; the code around them is this
 * package's own child discipline, tested as such.
 *
 * The ProcessContainment kill paths (terminate / terminatePid / killTree /
 * killTreeAsync / locateOnPath) are pinned HERE because their Windows arms
 * are pure routing into these seams: injecting the family and the runner
 * EXECs the arm selection on this box, and every leg asserts the argv the
 * tree kill would carry, never a claimed Windows execution.
 */
final class WindowsTaskKillTest extends TestCase
{
    protected function setUp(): void
    {
        // The MSYS step is the host's call (Windows + a located Git bash);
        // pinned off here so every routing pin below means the same thing on
        // a Linux box and a Windows one. The MSYS legs opt in explicitly.
        WindowsTaskKill::setMsysBashForTesting('');
    }

    protected function tearDown(): void
    {
        WindowsTaskKill::setMsysBashForTesting(null);
        WindowsTaskKill::setRunnerForTesting(null);
        WindowsTaskKill::setChildBoundForTesting(null);
        WindowsShell::setOsFamilyForTesting(null);
        WindowsShell::setEnvSeamForTesting(null);
    }

    public function testTheArgvIsTheForceTreeKillOfExactlyThisPid(): void
    {
        self::assertSame(['taskkill', '/T', '/F', '/PID', '4242'], WindowsTaskKill::treeArgv(4242));
        self::assertSame(['taskkill', '/T', '/F', '/PID', '1'], WindowsTaskKill::treeArgv(1));
        // '/T' is the tree walk (the ONE thing proc_terminate cannot do) and
        // '/F' the force close — pinned as ORDERED positions, not as a bag
        // of flags, because a re-spelled argv is a different command.
        $argv = WindowsTaskKill::treeArgv(7);
        self::assertSame('taskkill', $argv[0], 'the killer binary itself, never a shell string');
        self::assertContains('/T', $argv);
        self::assertContains('/F', $argv);
    }

    /**
     * Git-for-Windows bash backgrounds through fork emulation, and the fork
     * stub exits, so `sleep 40 &` ends up with no living Windows parent and
     * taskkill's walk never reaches it (measured, Windows 11: both sleeps of
     * `sleep 40 & sleep 40 & wait` survived a 2 s Bash timeout). The MSYS
     * group kill goes first, and once it confirms, nothing else runs.
     */
    public function testTheMsysGroupKillRunsFirstAndAConfirmedOneEndsTheKill(): void
    {
        $calls = [];
        WindowsTaskKill::setMsysBashForTesting('C:\\Git\\usr\\bin\\bash.exe');
        WindowsTaskKill::setRunnerForTesting(static function (array $argv) use (&$calls): bool {
            $calls[] = $argv;

            return true;
        });

        self::assertTrue(WindowsTaskKill::tree(4040));
        self::assertSame(
            [WindowsTaskKill::msysGroupKillArgv(4040, 'C:\\Git\\usr\\bin\\bash.exe')],
            $calls,
            'a confirmed MSYS kill already ran the taskkill walk itself; no second kill goes out',
        );
    }

    public function testAPidThatIsNoMsysProcessFallsThroughToTheTaskkillWalk(): void
    {
        $calls = [];
        WindowsTaskKill::setMsysBashForTesting('C:\\Git\\usr\\bin\\bash.exe');
        WindowsTaskKill::setRunnerForTesting(static function (array $argv) use (&$calls): bool {
            $calls[] = $argv;

            // The MSYS script exits 1 when no /proc entry carries the pid.
            return $argv[0] === 'taskkill';
        });

        self::assertTrue(WindowsTaskKill::tree(5050));
        self::assertSame(
            [WindowsTaskKill::msysGroupKillArgv(5050, 'C:\\Git\\usr\\bin\\bash.exe'), WindowsTaskKill::treeArgv(5050)],
            $calls,
        );
    }

    public function testTheMsysArgvCarriesThePidAsAPositionalNeverInTheScript(): void
    {
        $argv = WindowsTaskKill::msysGroupKillArgv(6060, '/usr/bin/bash');

        self::assertSame('/usr/bin/bash', $argv[0]);
        self::assertSame('-c', $argv[1]);
        self::assertSame(['msys-group-kill', '6060'], \array_slice($argv, 3));
        self::assertStringNotContainsString('6060', $argv[2], 'the script is constant; the pid rides $1');
        // Group recorded BEFORE anything dies, taskkill walk before the group
        // SIGKILL: the /proc entry vanishes with bash, natives hang off it.
        $script = $argv[2];
        self::assertLessThan(\strpos($script, 'taskkill'), \strpos($script, '/pgid'));
        self::assertLessThan(\strpos($script, 'kill -9'), \strpos($script, 'taskkill'));
        self::assertStringContainsString('kill -9 -- "-$g"', $script);
    }

    /**
     * The PowerShell child query is a cold powershell.exe and WMI start that
     * the killTreeAsync() Windows arm waits on synchronously, so a native pid
     * whose image is not bash.exe must exit 1 without ever starting it. EXEC
     * under this host's bash with stub `ps`/`timeout`/`powershell.exe` on
     * PATH: /proc carries no winpid here, so the lookup always misses, which
     * is exactly the no-MSYS-process-at-the-pid leg.
     */
    public function testANativePidThatIsNotBashNeverStartsThePowershellQuery(): void
    {
        $run = $this->runMsysScriptWithStubs(7070);

        self::assertSame(1, $run['exit'], 'no MSYS process at or under the pid: the step reports a miss');
        self::assertSame('-W', $run['psArgs'], 'the image check is one MSYS ps -W listing');
        self::assertNull($run['timeoutArgs'], 'a native kill must not start the bounded query at all');
        self::assertFalse($run['powershellRan'], 'a native non-bash pid must never start powershell.exe');
    }

    /**
     * When the pid IS a native bash.exe (Git's bin\bash.exe launcher), the
     * query runs, and it runs under MSYS timeout(1): on Windows PHP's
     * proc_terminate() at CHILD_BOUND_SECONDS reaches only the bash, so the
     * powershell.exe grandchild needs a bound of its own, shorter than the
     * bash's, or a timed-out kill leaves it running.
     */
    public function testTheLauncherPidRunsThePowershellQueryUnderItsOwnShorterBound(): void
    {
        $run = $this->runMsysScriptWithStubs(7071);

        self::assertSame(1, $run['exit'], 'the stub child 9999 is no MSYS process here, so the step still misses');
        self::assertTrue($run['powershellRan'], 'a native bash.exe pid is the launcher case the query exists for');
        self::assertSame(
            '-s KILL ' . WindowsTaskKill::CHILD_QUERY_BOUND_SECONDS . ' powershell.exe',
            $run['timeoutArgs'],
            'powershell.exe is started only through timeout -s KILL at the declared query bound',
        );
        self::assertLessThan(
            WindowsTaskKill::CHILD_BOUND_SECONDS,
            (float) WindowsTaskKill::CHILD_QUERY_BOUND_SECONDS,
            'the grandchild bound must fire before PHP judges the bash wedged',
        );
    }

    /**
     * Run the real MSYS group-kill script for $pid under this host's bash
     * with stubs first on PATH, and report what each stub saw.
     *
     * @return array{exit: int, psArgs: ?string, timeoutArgs: ?string, powershellRan: bool}
     */
    private function runMsysScriptWithStubs(int $pid): array
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\is_executable('/bin/bash')) {
            self::markTestSkipped('Drives the MSYS script under a POSIX bash with stub binaries on PATH.');
        }

        $dir = \sys_get_temp_dir() . '/wtk-msys-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700);
        $stubs = [
            'ps' => "#!/bin/sh\necho \"\$*\" > \"\$MARK/ps.args\"\ncat <<'EOF'\n"
                . "      PID    PPID    PGID     WINPID   TTY         UID    STIME COMMAND\n"
                . "     7070       0       0       7070    ?              0 10:00:00 C:\\Windows\\System32\\notepad.exe\r\n"
                . "     7071       0       0       7071    ?              0 10:00:00 C:\\Program Files\\Git\\bin\\bash.exe\r\n"
                . "EOF\n",
            'timeout' => "#!/bin/sh\necho \"\$1 \$2 \$3 \$4\" > \"\$MARK/timeout.args\"\nshift 3\nexec \"\$@\"\n",
            'powershell.exe' => "#!/bin/sh\necho ran > \"\$MARK/powershell.ran\"\nprintf '9999\\r\\n'\n",
        ];
        try {
            foreach ($stubs as $name => $body) {
                \file_put_contents("$dir/$name", $body);
                \chmod("$dir/$name", 0700);
            }
            $env = ['PATH' => $dir . ':' . (\getenv('PATH') ?: '/usr/bin:/bin'), 'MARK' => $dir];
            $process = \proc_open(
                WindowsTaskKill::msysGroupKillArgv($pid, '/bin/bash'),
                // stderr is drained to EOF and dropped: on Linux no /proc
                // entry has a winpid, and `read < "$d/winpid" 2>/dev/null`
                // reports the failed open before its own 2>/dev/null applies,
                // so it carries one line per process here. stdout goes to a
                // file so draining one stream can never block the other.
                [0 => ['pipe', 'r'], 1 => ['file', "$dir/stdout", 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            self::assertIsResource($process);
            \fclose($pipes[0]);
            \stream_get_contents($pipes[2]);
            \fclose($pipes[2]);
            $exit = \proc_close($process);

            $read = static fn (string $file): ?string => \is_file("$dir/$file") ? \trim((string) \file_get_contents("$dir/$file")) : null;

            return [
                'exit' => $exit,
                'psArgs' => $read('ps.args'),
                'timeoutArgs' => $read('timeout.args'),
                'powershellRan' => \is_file("$dir/powershell.ran"),
            ];
        } finally {
            foreach (\glob("$dir/*") ?: [] as $file) {
                \unlink($file);
            }
            \rmdir($dir);
        }
    }

    public function testANonPositivePidIsRefusedBeforeTheRunnerIsEvenTouched(): void
    {
        // kill(0) means "signal everything the caller may" — vast, and never
        // what a teardown meant. The refusal must precede the runner so a
        // forged or drifted 0 cannot reach ANY execution path.
        $touched = 0;
        WindowsTaskKill::setRunnerForTesting(
            static function (array $argv) use (&$touched): bool {
                $touched++;

                return true;
            }
        );

        self::assertFalse(WindowsTaskKill::tree(0));
        self::assertFalse(WindowsTaskKill::tree(-1));
        self::assertSame(0, $touched, 'the refusal is at the door, not inside the runner');
    }

    public function testTheRunnerSeesTheExactArgvAndItsAnswerPassesThrough(): void
    {
        $seen = null;
        WindowsTaskKill::setRunnerForTesting(
            static function (array $argv) use (&$seen): bool {
                $seen = $argv;

                return true;
            }
        );
        self::assertTrue(WindowsTaskKill::tree(4321));
        self::assertSame(WindowsTaskKill::treeArgv(4321), $seen);

        WindowsTaskKill::setRunnerForTesting(
            static fn (array $argv): bool => false
        );
        self::assertFalse(WindowsTaskKill::tree(4322), 'a reported-failed tree kill must not read as killed');
    }

    public function testTheDefaultRunnerAnswersFromTheChildExitStatus(): void
    {
        // EXEC as data: a successful child is true, a failed one is false,
        // BOTH drained to EOF and reaped — the exit-status contract taskkill
        // would ride on Windows is tested here with real local binaries.
        // The PHP binary stands in for /bin/true and /bin/false so the leg
        // runs on a Windows host too.
        self::assertTrue($this->driveTreeKillRunner([\PHP_BINARY, '-r', 'exit(0);']));
        self::assertFalse($this->driveTreeKillRunner([\PHP_BINARY, '-r', 'exit(1);']));
    }

    public function testTheMissingBinaryLegReturnsFalseQuicklyThroughTheRealTreePath(): void
    {
        // tree()'s un-seamed default runner on THIS host: 'taskkill' is a
        // Windows binary that does not exist here (asserted precondition, so
        // a weird box fails loudly instead of silently changing the case),
        // and the answer must be false WITHOUT hanging — the child's exec
        // failure is status 127, drain sees immediate EOF, proc_close reaps.
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Every Windows host carries taskkill; this leg pins the binary-missing path of a POSIX host.');
        }
        self::assertSame('', ProcessContainment::locateOnPath('taskkill'), 'this box must not carry a taskkill');

        $started = \hrtime(true);
        self::assertFalse(WindowsTaskKill::tree(4242));
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;
        self::assertLessThan(WindowsTaskKill::childBoundSeconds(), $elapsedSeconds, 'an instant exec failure must not cost the bound');
    }

    public function testAWedgedChildIsTerminatedAtTheDeclaredBoundNotWaitedOut(): void
    {
        // E646's declared-bound shape: the killer child is BOUNDED, and this
        // pins that a child which never closes its pipes is stopped at the
        // bound (tick scale via the test seam) and judged failed — the
        // teardown can never be extended past the window by the killer
        // itself. A two-second sleep stands in for a wedged taskkill: the
        // 0.4 s bound fires long before it, and the short residue is a
        // deliberate suite-hygiene trade over leaving a 30 s orphan to make
        // a point the bound constant already proves.
        WindowsTaskKill::setChildBoundForTesting(0.4);

        $started = \hrtime(true);
        $verdict = $this->driveTreeKillRunner([\PHP_BINARY, '-r', 'sleep(2);']);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertFalse($verdict, 'a wedged killer is a failed kill, never an assumed one');
        self::assertGreaterThanOrEqual(0.3, $elapsedSeconds, 'the bound must actually be waited to, not skipped');
        self::assertLessThan(1.8, $elapsedSeconds, 'the bound must FIRE, not be waited out by the stand-in');
    }

    public function testTheSpawnIsRoutedThroughSpawnSpecAndReapedInTheSameFunction(): void
    {
        // Structure pins for the child-lifetime guards: spawnSpec FIRST (the
        // Windows host answers it with "no wrapper", so the argv rides the
        // array form), ONE proc_open, and proc_close in the same function on
        // the drain's heels — the short-lived classification the scanner
        // looks for, kept honest by bytes rather than by an assertion count.
        $source = (string) file_get_contents((new \ReflectionClass(WindowsTaskKill::class))->getFileName());
        $bodyStart = strpos($source, 'private static function runTreeKill');
        self::assertIsInt($bodyStart, 'the runner moved out of its method — re-derive this slice');
        $body = substr($source, $bodyStart);

        $spawnAt = strpos($body, 'spawnSpec(');
        $openAt = strpos($body, 'proc_open(');
        $closeAt = strpos($body, 'proc_close(');
        self::assertIsInt($spawnAt);
        self::assertIsInt($openAt);
        self::assertIsInt($closeAt);
        self::assertLessThan($openAt, $spawnAt, 'the spec must come from spawnSpec BEFORE it is opened');
        self::assertLessThan($closeAt, $openAt);
        self::assertSame(1, substr_count($body, 'proc_open('), 'ONE spawn site, name-classifiable by the guards');

        // And the Windows fact itself: cmd.exe never appears as a QUOTED
        // literal (the only spelling that could reach an argv or a spawn —
        // the prose above names it precisely to forbid that).
        self::assertStringNotContainsString("'cmd.exe'", $source, 'the no-cmd.exe rule is a code fact, not a comment');
        self::assertStringNotContainsString('"cmd.exe"', $source);
    }

    public function testTerminateRoutesALiveHandleThroughTheTreeKillByRealPid(): void
    {
        // The terminate() Windows arm EXECs on this box: the child is a real
        // process, proc_get_status yields its REAL pid, and the injected
        // runner must see exactly the tree-kill argv for THAT pid. The arm
        // answering true on a faked-true runner proves routing; the child is
        // then reaped here because the fake, by design, killed nothing.
        $seenPid = null;
        $this->armWindowsKill(
            static function (array $argv) use (&$seenPid): bool {
                $seenPid = (int) ($argv[4] ?? 0);

                return true;
            }
        );

        $process = proc_open(ProcessContainment::spawnSpec([\PHP_BINARY, '-r', 'sleep(5);']), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $realPid = (int) proc_get_status($process)['pid'];

        try {
            self::assertTrue(ProcessContainment::terminate($process));
            self::assertSame($realPid, $seenPid, 'terminate must tree-kill the handle ITSELF leads, by its real pid');
        } finally {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }

    public function testTerminateFallsToProcTerminateOnlyWhenTheTreeKillCannotBeCarried(): void
    {
        // Runner answers FALSE — the arm must NOT claim success; proc_-
        // terminate then reaches the direct child, which for a five-second
        // sleep is observably a stop: status flips from running shortly
        // after. (The Windows host's own claim that only proc_terminate
        // remains is exactly what this leg pins the SHAPE of.)
        $this->armWindowsKill(static fn (array $argv): bool => false);

        $process = proc_open(ProcessContainment::spawnSpec([\PHP_BINARY, '-r', 'sleep(5);']), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            self::assertTrue(ProcessContainment::terminate($process));
            // proc_terminate succeeded on the fallback leg — the direct
            // child is stopped even though the fake tree kill failed; the
            // return value belongs to proc_terminate, which is the honest
            // last-resort semantics this arm documents.
            $deadline = \hrtime(true) + 2_000_000_000;
            while (\proc_get_status($process)['running'] ?? false) {
                if (\hrtime(true) >= $deadline) {
                    self::fail('the proc_terminate fallback leg never stopped the direct child');
                }
                \usleep(10_000);
            }
        } finally {
            proc_close($process);
        }
    }

    public function testTerminatePidAnswersThroughTheTreeKillOnWindows(): void
    {
        $this->armWindowsKill(static fn (array $argv): bool => $argv[4] === '777');

        self::assertTrue(ProcessContainment::terminatePid(777, 15));
        self::assertFalse(ProcessContainment::terminatePid(778, 9));
        self::assertFalse(ProcessContainment::terminatePid(0), 'the pid-at-the-door refusal rides the Windows arm too');
        self::assertFalse(ProcessContainment::terminatePid(-3));
    }

    public function testKillTreeOnWindowsCarriesOneForceKillAndSkipsThePosixLadder(): void
    {
        $calls = [];
        $this->armWindowsKill(
            static function (array $argv) use (&$calls): bool {
                $calls[] = $argv;

                return true;
            }
        );

        // The grace parameter is NOT waited on Windows: taskkill /T /F is
        // already the terminal force, so exactly ONE argv goes out whether
        // a grace was offered or not, and the call returns immediately —
        // pinned by the count, the shapes, and the elapsed window below.
        $started = \hrtime(true);
        ProcessContainment::killTree(811);
        ProcessContainment::killTree(812, 30.0);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertCount(2, $calls);
        self::assertSame(WindowsTaskKill::treeArgv(811), $calls[0]);
        self::assertSame(WindowsTaskKill::treeArgv(812), $calls[1]);
        self::assertLessThan(2.0, $elapsedSeconds, 'the grace must not be spent waiting on a host whose kill is already maximal');

        // Refusal edges, runner untouched:
        $before = count($calls);
        ProcessContainment::killTree(0);
        ProcessContainment::killTree(-5);
        ProcessContainment::killTree((int) getmypid());
        self::assertCount($before, $calls, 'pid 0, negatives and the caller itself never reach the killer');
    }

    public function testKillTreeAsyncResolvesAtOnceThroughTheSameWindowsArm(): void
    {
        $calls = [];
        $this->armWindowsKill(
            static function (array $argv) use (&$calls): bool {
                $calls[] = $argv;

                return true;
            }
        );

        $settled = false;
        $value = 'unset';
        ProcessContainment::killTreeAsync(909, null, 30.0)->then(
            static function ($v) use (&$settled, &$value): void {
                $settled = true;
                $value = $v;
            }
        );

        // The promise resolves SYNCHRONOUSLY on Windows: there is no grace
        // to spend and no ladder to walk, so awaiting is never part of the
        // contract — and the kill itself already went out.
        self::assertTrue($settled, 'the Windows arm resolves at once');
        self::assertNull($value);
        self::assertSame([WindowsTaskKill::treeArgv(909)], $calls);

        $refused = false;
        ProcessContainment::killTreeAsync(0)->then(static function () use (&$refused): void {
            $refused = true;
        });
        self::assertTrue($refused, 'a refused pid still resolves, silently');
        self::assertSame([WindowsTaskKill::treeArgv(909)], $calls, 'without a second kill going out');
    }

    public function testLocateOnPathDelegatesEntirelyToTheWindowsLadder(): void
    {
        // The fix at the resolver root: on an injected Windows family, PATH
        // `;`-splitting and PATHEXT come from WindowsShell::locate — a
        // `.exe` is found where the POSIX colon-split/is_executable ladder
        // could only answer ''.
        $dir = (string) sys_get_temp_dir() . '/w6a-pcp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        file_put_contents($dir . '/git.exe', 'fixture');
        WindowsShell::setOsFamilyForTesting('Windows');
        WindowsShell::setEnvSeamForTesting(
            static fn (string $name): ?string => $name === 'PATH' ? $dir : ($name === 'PATHEXT' ? '.exe' : null)
        );

        try {
            self::assertSame($dir . \DIRECTORY_SEPARATOR . 'git.exe', ProcessContainment::locateOnPath('git'));
            self::assertSame('', ProcessContainment::locateOnPath('nothere'));
        } finally {
            @unlink($dir . '/git.exe');
            @rmdir($dir);
        }
    }

    /**
     * Install the fake-Windows posture: family 'Windows' plus a taskkill
     * runner that records argv and answers per the given predicate.
     */
    private function armWindowsKill(\Closure $runner): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');
        WindowsTaskKill::setRunnerForTesting($runner);
    }

    /**
     * Drive the PRIVATE default runner directly with $argv standing in for
     * taskkill, through the same spawnSpec → drain → reap path production
     * takes when no seam is installed.
     *
     * @param non-empty-list<string> $argv
     */
    private function driveTreeKillRunner(array $argv): bool
    {
        $method = new \ReflectionMethod(WindowsTaskKill::class, 'runTreeKill');

        return (bool) $method->invoke(null, $argv);
    }
}
