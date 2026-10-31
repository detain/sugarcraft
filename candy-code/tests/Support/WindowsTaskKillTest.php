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
    protected function tearDown(): void
    {
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
        self::assertTrue($this->driveTreeKillRunner(['/bin/true']));
        self::assertFalse($this->driveTreeKillRunner(['/bin/false']));
    }

    public function testTheMissingBinaryLegReturnsFalseQuicklyThroughTheRealTreePath(): void
    {
        // tree()'s un-seamed default runner on THIS host: 'taskkill' is a
        // Windows binary that does not exist here (asserted precondition, so
        // a weird box fails loudly instead of silently changing the case),
        // and the answer must be false WITHOUT hanging — the child's exec
        // failure is status 127, drain sees immediate EOF, proc_close reaps.
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
        $verdict = $this->driveTreeKillRunner(['/bin/sleep', '2']);
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

        $process = proc_open(ProcessContainment::spawnSpec(['/bin/sleep', '5']), [
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

        $process = proc_open(ProcessContainment::spawnSpec(['/bin/sleep', '5']), [
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
            self::assertSame($dir . '/git.exe', ProcessContainment::locateOnPath('git'));
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
