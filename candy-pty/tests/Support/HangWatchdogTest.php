<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Fixture test for the E490 hang watchdog.
 *
 * The watchdog's whole job is to fire on a case that, by construction, this
 * suite cannot otherwise produce on demand -- a test that never returns. So
 * it is exercised here against a STAND-IN victim: a process that really does
 * sleep forever, a heartbeat record built by the real producer
 * ({@see HangWatchdog::heartbeatPayload()}), and the real watchdog process.
 *
 * BOTH polarities are pinned on purpose. A watchdog that fires on everything
 * and a watchdog that fires on nothing both leave a green suite, and only the
 * negative arm can tell them apart:
 *
 *   - positive: a stale heartbeat  -> victim is killed, and the report NAMES
 *               the test plus the forensics E490 had to be gathered by hand;
 *   - negative: a heartbeat kept fresh across several budgets -> victim lives.
 *
 * Skip gates are measured against THIS host rather than copied from a
 * neighbouring test: `proc_open`, `posix_kill` and `/proc/<pid>/fd` all exist
 * on PHP 8.3.6 here, so the two process arms run. The payload-format arm is
 * deliberately UNGATED so something still executes if the gates ever fire.
 */
final class HangWatchdogTest extends TestCase
{
    /** @var list<array{pid: int, proc: resource}> */
    private array $victims = [];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->victims as $victim) {
            // SIGKILL BEFORE proc_close(). proc_close() blocks until the
            // child exits, and the stand-in victim blocks forever by design
            // -- reversing these two lines wedges the tear-down, which is
            // exactly how the first draft of this file hung the suite.
            if (\function_exists('posix_kill')) {
                @\posix_kill($victim['pid'], \SIGKILL);
            }
            if (\is_resource($victim['proc'])) {
                @\proc_close($victim['proc']);
            }
        }
        $this->victims = [];

        // Exact-path deletes only: sibling lanes are running suites that own
        // other files in this directory.
        foreach ($this->paths as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        $this->paths = [];
    }

    private function requireProcessControl(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the watchdog is POSIX-only.');
        }
        foreach (['proc_open', 'posix_kill'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("{$fn}() is unavailable on this host.");
            }
        }
    }

    /**
     * The record the runner writes must be the record the watchdog reads.
     * Ungated: this arm runs even where the process arms cannot.
     */
    public function testHeartbeatPayloadIsATabSeparatedTimestampAndTestId(): void
    {
        $payload = HangWatchdog::heartbeatPayload('Some\\Test::testThing', 1234.5);

        $parts = \explode("\t", $payload);
        $this->assertCount(2, $parts, 'heartbeat must be exactly two TAB-separated fields');
        $this->assertSame('Some\\Test::testThing', $parts[1]);
        $this->assertEqualsWithDelta(1234.5, (float) $parts[0], 0.000001);
        $this->assertStringNotContainsString("\n", $payload, 'a newline would let a test id forge a second record');
    }

    /** A test id containing a TAB must not be able to fake a timestamp field. */
    public function testHeartbeatPayloadKeepsTheTimestampInTheFirstField(): void
    {
        $payload = HangWatchdog::heartbeatPayload("evil\t999999", 42.0);
        $parts = \explode("\t", $payload, 2);

        $this->assertEqualsWithDelta(42.0, (float) $parts[0], 0.000001);
        $this->assertSame("evil\t999999", $parts[1]);
    }

    /**
     * `CANDY_PTY_HANG_BUDGET=0` IS THE DOCUMENTED OPT-OUT, and this pins the
     * decision rather than a call that cannot reach it.
     *
     * WHAT THIS TEST SAID: `assertFalse(HangWatchdog::install(0.0))`, with the
     * message "a zero budget must decline rather than install a watchdog that
     * fires immediately". WHAT IS TRUE NOW: that assertion was satisfied by
     * something else entirely. {@see HangWatchdog::install()} returns false at
     * its FIRST guard when an instance already exists, and bootstrap installs
     * one before any test runs — measured from inside a running suite, the
     * static instance is SET and `install(0.0)` returns false without the
     * budget ever being read. Mutating the budget guard out of `install()`
     * altogether left the whole file green. And the call could not have
     * reached the arming path in either direction: PHPUnit's event facade is
     * sealed by the time a test executes, so a mid-suite `install()` with ANY
     * budget lands in the catch branch. WHY THE OPT-OUT STILL EARNS A TEST:
     * the opt-out is real and load-bearing — it is how someone debugging a
     * genuinely slow test turns the killer off — so it is pinned where it can
     * actually be observed, in {@see HangWatchdog::armsFor()} and in
     * {@see budgetFromEnv()}, and end to end in the arm below this one.
     */
    public function testANonPositiveBudgetDeclinesToArm(): void
    {
        $this->assertFalse(HangWatchdog::armsFor(0.0), 'zero is the documented opt-out');
        $this->assertFalse(HangWatchdog::armsFor(-1.0), 'a negative budget would fire immediately');

        // The positive half. Without it the predicate could be stuck at "never
        // arm", which is a watchdog that is silently absent from every run —
        // indistinguishable, from inside a green suite, from one that works.
        $this->assertTrue(HangWatchdog::armsFor(0.001), 'any positive budget must arm');
        $this->assertTrue(HangWatchdog::armsFor(HangWatchdog::DEFAULT_BUDGET_SEC));
    }

    /**
     * THE OPT-OUT, END TO END, IN A PROCESS WHERE THE DECISION IS REACHABLE.
     *
     * The predicate above can be pinned in-process; whether `install()` still
     * CONSULTS it cannot, because every mid-suite call short-circuits. So this
     * arm loads the real `tests/bootstrap.php` in a child — where no instance
     * exists yet and the event facade is not sealed — and reads back whether a
     * watchdog was armed, under both settings of the environment variable.
     *
     * BOTH POLARITIES, and the second one is the load-bearing half: a bootstrap
     * that armed NOTHING under any setting would satisfy a one-sided "the
     * opt-out works" assertion perfectly, and would mean this suite has been
     * running unwatched.
     */
    public function testTheEnvironmentOptOutDecidesWhetherBootstrapArmsAWatchdog(): void
    {
        $this->requireProcessControl();

        $this->assertSame(
            'NONE',
            $this->armedUnder('0'),
            'CANDY_PTY_HANG_BUDGET=0 is the documented opt-out and bootstrap armed a watchdog '
                . 'anyway — the budget is not being consulted on the way in',
        );

        $this->assertSame(
            'ARMED',
            $this->armedUnder('3600'),
            'bootstrap armed NO watchdog under a positive budget, so this suite has been '
                . 'running with the E490 bound switched off and every green take since says '
                . 'nothing about whether a hang would be caught',
        );
    }

    /**
     * `beat()` IS THE ONLY THING THAT MAKES THE WATCHDOG POINT AT A TEST, and
     * until this arm existed it was covered by nothing at all.
     *
     * MEASURED: emptying {@see HangWatchdog::beat()} to a bare `return;` left
     * the whole of this file green. That is the E490 instrument's own version
     * of the defect it exists to catch — with no heartbeat ever written the
     * watchdog process polls a file that never appears, never fires, and a
     * suite that hangs hangs exactly as it did before. A watchdog that is DEAD
     * and a watchdog that never NEEDS to fire produce identical green runs, so
     * the producer has to be driven by something.
     *
     * It is driven here through the REAL consumer rather than compared against
     * a spelled-out format: the record `beat()` writes is handed to the real
     * `hang-watchdog.php`, allowed to go stale, and the child must fire and
     * name the id `beat()` put there. A fixture that asserted the bytes would
     * keep passing after the two halves stopped agreeing.
     */
    public function testTheHeartbeatBeatWritesIsTheRecordTheWatchdogFiresOn(): void
    {
        $this->requireProcessControl();

        $heartbeat = $this->tempPath('beat');
        $watchdog = $this->watchdogWritingTo($heartbeat);

        $this->assertFileDoesNotExist($heartbeat, 'the fixture must start with no record at all');

        $watchdog->beat('Fixture\\BeatTest::testWrittenByBeat');

        $this->assertFileExists(
            $heartbeat,
            'beat() wrote no heartbeat, so the watchdog process has nothing to watch and can '
                . 'never fire — which is a dead instrument wearing a green suite',
        );
        $this->assertSame(
            [],
            \glob($heartbeat . '.*.tmp') ?: [],
            'beat() left its temporary file behind instead of renaming it into place',
        );

        // Now the real consumer, against the real producer's output. The record
        // is never refreshed, so it goes stale within one budget.
        $victim = $this->spawnVictim();
        $report = $this->runWatchdog($victim, $heartbeat, 1.0, 20.0);

        $this->assertSame(1, $report['exitCode'], 'the watchdog did not fire on beat()\'s record');
        $this->assertStringContainsString(
            'Fixture\\BeatTest::testWrittenByBeat',
            $report['stderr'],
            'the watchdog fired but did not name the test beat() recorded — the producer and '
                . 'the consumer have stopped agreeing on the record',
        );
    }

    /**
     * KNOWN POSITIVE. A heartbeat older than the budget must get the runner
     * killed, with a report that names the offending test.
     */
    public function testAStaleHeartbeatKillsTheRunnerAndNamesTheTest(): void
    {
        $this->requireProcessControl();

        $victim = $this->spawnVictim();
        $heartbeat = $this->tempPath('stale');

        // Built by the REAL producer, backdated well past the budget.
        \file_put_contents(
            $heartbeat,
            HangWatchdog::heartbeatPayload('Fixture\\WedgedTest::testNeverReturns', \microtime(true) - 30.0),
        );

        $report = $this->runWatchdog($victim, $heartbeat, 1.0, 20.0);

        $this->assertSame(1, $report['exitCode'], 'the watchdog must exit 1 after firing');
        $this->assertFalse(
            $this->isAlive($victim),
            'the watchdog must actually kill the runner, not merely report on it',
        );

        $stderr = $report['stderr'];
        $this->assertStringContainsString('HANG WATCHDOG', $stderr);
        $this->assertStringContainsString(
            'Fixture\\WedgedTest::testNeverReturns',
            $stderr,
            'the report must NAME the test — a hang that names nothing is what E490 was',
        );
        // The forensic bundle E490 had to be assembled by hand.
        $this->assertStringContainsString('ps:', $stderr);
        $this->assertStringContainsString('/dev/ptmx', $stderr);
        $this->assertStringContainsString('children:', $stderr);
    }

    /**
     * KNOWN NEGATIVE, and the arm that carries the weight. A watchdog that
     * killed unconditionally would pass the positive test above; only a
     * heartbeat kept fresh across several budget-widths can tell the two
     * apart.
     */
    public function testAFreshHeartbeatLeavesTheRunnerAlone(): void
    {
        $this->requireProcessControl();

        $victim = $this->spawnVictim();
        $heartbeat = $this->tempPath('fresh');
        \file_put_contents($heartbeat, HangWatchdog::heartbeatPayload('Fixture\\HealthyTest::testFast', \microtime(true)));

        $budget = 1.0;
        $proc = $this->startWatchdog($victim, $heartbeat, $budget, $pipes);

        try {
            // Beat for four budget-widths. The stale arm above fires within
            // one poll (0.5s), so this is ample room for a false positive to
            // show itself.
            $deadline = \microtime(true) + ($budget * 4);
            while (\microtime(true) < $deadline) {
                \file_put_contents(
                    $heartbeat,
                    HangWatchdog::heartbeatPayload('Fixture\\HealthyTest::testFast', \microtime(true)),
                );
                \usleep(200_000);
            }

            $this->assertTrue(
                $this->isAlive($victim),
                'a heartbeat refreshed inside the budget must never trip the watchdog',
            );
            $status = \proc_get_status($proc);
            $this->assertTrue($status['running'], 'the watchdog must still be watching, not exited');
        } finally {
            $this->reapWatchdog($proc, $pipes);
        }
    }

    /**
     * The watchdog must retire on its own when the runner disappears, or a
     * killed run would leave a process behind on every take.
     */
    public function testTheWatchdogExitsWhenTheRunnerIsGone(): void
    {
        $this->requireProcessControl();

        $victim = $this->spawnVictim();
        $heartbeat = $this->tempPath('gone');
        \file_put_contents($heartbeat, HangWatchdog::heartbeatPayload('Fixture\\AnyTest::testAny', \microtime(true)));

        $proc = $this->startWatchdog($victim, $heartbeat, 30.0, $pipes);
        try {
            @\posix_kill($victim, \SIGKILL);
            // Reap so the pid cannot linger as a zombie: the watchdog's
            // "is the runner gone?" probe is posix_kill($pid, 0), which
            // still answers yes for an unreaped zombie.
            foreach ($this->victims as $v) {
                if ($v['pid'] === $victim && \is_resource($v['proc'])) {
                    @\proc_close($v['proc']);
                }
            }
            $this->victims = [];

            $exit = $this->awaitExit($proc, 20.0);
            $this->assertSame(0, $exit, 'the watchdog must exit 0 once the runner is gone');
        } finally {
            $this->reapWatchdog($proc, $pipes);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A {@see HangWatchdog} whose heartbeat lands at $path and which has
     * spawned NO process.
     *
     * Built through the real private constructor rather than through
     * `install()`, because `install()` cannot be reached from inside a running
     * suite (an instance already exists and the event facade is sealed) and
     * because spawning a second watchdog against this very runner is the one
     * thing a fixture must not do.
     */
    private function watchdogWritingTo(string $path): HangWatchdog
    {
        $class = new \ReflectionClass(HangWatchdog::class);
        $watchdog = $class->newInstanceWithoutConstructor();

        foreach (['heartbeatPath' => $path, 'stateDir' => \dirname($path)] as $name => $value) {
            $property = $class->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($watchdog, $value);
        }

        return $watchdog;
    }

    /**
     * Load the real `tests/bootstrap.php` in a child with
     * `CANDY_PTY_HANG_BUDGET=$budget` and report whether it armed a watchdog.
     *
     * `PHP_BINARY`, not `php` on `PATH`: a probe that resolves the interpreter
     * differently from the runner it is making a claim about is a probe that
     * can answer about a different PHP.
     */
    private function armedUnder(string $budget): string
    {
        $bootstrap = \dirname(__DIR__) . '/bootstrap.php';
        $code = <<<'CODE'
            require $argv[1];
            $property = new ReflectionProperty(\SugarCraft\Pty\Tests\Support\HangWatchdog::class, 'instance');
            $property->setAccessible(true);
            $watchdog = $property->getValue();
            if ($watchdog !== null) {
                $watchdog->stop();
            }
            echo $watchdog === null ? 'NONE' : 'ARMED';
            CODE;

        $pipes = [];
        $proc = \proc_open(
            [\PHP_BINARY, '-r', $code, $bootstrap],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['CANDY_PTY_HANG_BUDGET' => $budget] + \getenv(),
        );
        $this->assertIsResource($proc, 'could not spawn the bootstrap probe');

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }
        \proc_close($proc);

        $this->assertContains(
            $out,
            ['NONE', 'ARMED'],
            'the bootstrap probe said neither NONE nor ARMED, so this arm is measuring nothing. '
                . 'stderr: ' . $err,
        );

        return $out;
    }


    /**
     * A process that really does block forever, standing in for a wedged
     * test. The `proc_open` handle is KEPT so liveness can be read from
     * `proc_get_status()` rather than from `posix_kill($pid, 0)` -- a killed
     * but unreaped child is a zombie, and `posix_kill(0)` still answers yes
     * for one, which would let a watchdog that fired correctly read as a
     * watchdog that did nothing.
     *
     * @return int the victim's pid
     */
    private function spawnVictim(): int
    {
        $pipes = [];
        $proc = \proc_open(
            [\PHP_BINARY, '-r', 'while (true) { sleep(3600); }'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
        );
        $this->assertIsResource($proc, 'could not spawn the stand-in victim');
        $status = \proc_get_status($proc);
        $pid = (int) $status['pid'];
        $this->assertGreaterThan(0, $pid);
        $this->victims[] = ['pid' => $pid, 'proc' => $proc];

        return $pid;
    }

    private function isAlive(int $pid): bool
    {
        foreach ($this->victims as $victim) {
            if ($victim['pid'] !== $pid || !\is_resource($victim['proc'])) {
                continue;
            }
            $status = @\proc_get_status($victim['proc']);
            return \is_array($status) && ($status['running'] ?? false) === true;
        }
        return false;
    }

    private function tempPath(string $tag): string
    {
        $path = \sys_get_temp_dir()
            . '/candy-pty-hang-watchdog-fixture-' . $tag . '-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
        $this->paths[] = $path;
        return $path;
    }

    /**
     * @param array<int, resource> $pipes
     * @return resource
     */
    private function startWatchdog(int $victimPid, string $heartbeat, float $budget, ?array &$pipes)
    {
        $pipes = [];
        $proc = \proc_open(
            [\PHP_BINARY, __DIR__ . '/hang-watchdog.php', (string) $victimPid, $heartbeat, (string) $budget],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc, 'could not spawn the watchdog under test');
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        return $proc;
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private function awaitExit($proc, float $timeoutSec): ?int
    {
        $deadline = \microtime(true) + $timeoutSec;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($proc);
            if ($status['running'] === false) {
                return (int) $status['exitcode'];
            }
            \usleep(100_000);
        }
        return null;
    }

    /**
     * @return array{exitCode: int|null, stderr: string}
     */
    private function runWatchdog(int $victimPid, string $heartbeat, float $budget, float $timeoutSec): array
    {
        $proc = $this->startWatchdog($victimPid, $heartbeat, $budget, $pipes);
        $stderr = '';
        try {
            $deadline = \microtime(true) + $timeoutSec;
            $exit = null;
            while (\microtime(true) < $deadline) {
                $stderr .= (string) \stream_get_contents($pipes[2]);
                $status = \proc_get_status($proc);
                if ($status['running'] === false) {
                    $exit = (int) $status['exitcode'];
                    break;
                }
                \usleep(100_000);
            }
            $stderr .= (string) \stream_get_contents($pipes[2]);
            return ['exitCode' => $exit, 'stderr' => $stderr];
        } finally {
            $this->reapWatchdog($proc, $pipes);
        }
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private function reapWatchdog($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        if (\is_resource($proc)) {
            $status = @\proc_get_status($proc);
            if (\is_array($status) && ($status['running'] ?? false) === true) {
                @\proc_terminate($proc, \SIGKILL);
            }
            @\proc_close($proc);
        }
    }
}
