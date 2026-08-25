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

    /** @var list<string> fixture state directories, removed in tearDown() */
    private array $dirs = [];

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
            foreach ((array) @\glob($path . '.*.tmp') as $stale) {
                @\unlink((string) $stale);
            }
        }
        $this->paths = [];

        // Exact paths again: each of these was created by tempPath() in THIS
        // process and is named with this pid plus six random bytes.
        foreach ($this->dirs as $dir) {
            @\rmdir($dir);
        }
        $this->dirs = [];
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
            'bootstrap armed NO watchdog under a positive budget, so bootstrap does not arm '
                . 'one in a plain child process',
        );
    }

    /**
     * AND THIS RUN, SPECIFICALLY, IS WATCHED.
     *
     * The end-to-end arm above spawns a plain `php -r` child, where PHPUnit's
     * event facade is NOT sealed. Facade-seal timing is precisely the thing
     * that decides whether `install()` gets to register its subscribers -- it
     * catches a seal and tears the watchdog straight back down -- so a probe
     * running outside PHPUnit cannot speak for the process it is a claim about.
     * The old failure text said "this suite has been running with the E490
     * bound switched off", which is a statement about THIS process that no
     * assertion in the file made.
     *
     * One line closes it: the instance the real bootstrap installed is either
     * here or it is not.
     */
    public function testThisRunIsItselfBeingWatched(): void
    {
        if (\getenv('CANDY_PTY_HANG_BUDGET') === '0') {
            self::markTestSkipped('the watchdog is switched off for this run by CANDY_PTY_HANG_BUDGET=0');
        }

        $property = new \ReflectionProperty(HangWatchdog::class, 'instance');
        $property->setAccessible(true);

        $this->assertNotNull(
            $property->getValue(),
            'no watchdog is installed in THIS process, so this suite is running with the E490 '
                . 'bound switched off and every green take says nothing about whether a hang '
                . 'would be caught. The child-process probe above cannot see this: it runs '
                . 'outside PHPUnit, where the event facade is not sealed and install() takes a '
                . 'different path.',
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
     * A HEARTBEAT FROM A PREVIOUS RUN MUST NOT KILL THIS ONE.
     *
     * The state directory is named for a pid and nothing else, and the one
     * thing that removes it -- `stop()` -- is precisely what cannot run when
     * this watchdog SIGKILLs its runner. So a watchdog that fires leaves its
     * own heartbeat behind, and the next process handed that pid inherits a
     * record that is arbitrarily old and therefore fires instantly.
     *
     * Measured before the fix: with such a file seeded, the runner died during
     * bootstrap with rc 137 before a single test executed, and the forensic
     * dump named a test that was not running. That is rc 137 with no `Tests:`
     * line -- the exact signature of the hang this watchdog exists to
     * diagnose, which makes it the worst possible false positive.
     */
    public function testAHeartbeatFromBeforeTheWatchdogWasArmedIsIgnored(): void
    {
        $this->requireProcessControl();

        $victim = $this->spawnVictim();
        $heartbeat = $this->tempPath('predates');

        // Backdated far past the budget - the shape that fires immediately.
        \file_put_contents(
            $heartbeat,
            HangWatchdog::heartbeatPayload('Ghost\\PreviousRun::testFromAnotherProcess', \microtime(true) - 999.0),
        );

        $budget = 1.0;
        $proc = $this->startWatchdog($victim, $heartbeat, $budget, $pipes, \microtime(true));

        try {
            // The stale arm fires within one poll (0.5s); four budget-widths
            // is ample room for a false positive to show itself.
            \usleep((int) ($budget * 4 * 1_000_000));

            $this->assertTrue(
                $this->isAlive($victim),
                'a heartbeat written BEFORE the watchdog was armed killed the runner. That '
                    . 'record belongs to a previous occupant of this pid, and acting on it '
                    . 'produces an rc 137 with no summary - indistinguishable from the hang '
                    . 'this watchdog reports on, except that it names a test that is not running',
            );
            $status = \proc_get_status($proc);
            $this->assertTrue($status['running'], 'the watchdog must still be watching, not exited');
        } finally {
            $this->reapWatchdog($proc, $pipes);
        }
    }

    /**
     * THE OTHER HALF OF THE F4 FIX, PINNED ON ITS OWN.
     *
     * `install()` clears inherited state before spawning, and the child ignores
     * records that predate its arming. Either alone keeps the end-to-end probe
     * green -- measured: deleting the clearing code changes no test outcome
     * while the child-side filter stands, and deleting the filter changes none
     * while the clearing stands; deleting BOTH reds the probe. That is the
     * correct behaviour for two independent defences and a bad place to leave
     * the coverage, because a defence no mutation can kill reads as dead code
     * to the next person tidying up. So the clearing is asserted directly.
     *
     * The negative arm matters as much as the positive: this must remove the
     * inherited heartbeat and NOT the directory, which install() has just
     * created and is about to use.
     */
    public function testClearingInheritedStateRemovesTheHeartbeatAndKeepsTheDirectory(): void
    {
        $heartbeat = $this->tempPath('inherited');
        $dir = \dirname($heartbeat);
        $tmp = $heartbeat . '.999999.tmp';

        \file_put_contents(
            $heartbeat,
            HangWatchdog::heartbeatPayload('Ghost\\PreviousRun::testFromAnotherProcess', \microtime(true) - 999.0),
        );
        \file_put_contents($tmp, 'torn write from a previous run');
        $this->paths[] = $tmp;

        $this->assertFileExists($heartbeat, 'the fixture must start WITH inherited state');

        HangWatchdog::clearInheritedState($dir);

        $this->assertFileDoesNotExist(
            $heartbeat,
            'an inherited heartbeat survived install()\'s clearing, so a run given a recycled '
                . 'pid starts with a record that fires instantly and SIGKILLs it before any '
                . 'test executes',
        );
        $this->assertFileDoesNotExist(
            $tmp,
            'a torn .tmp from a previous run survived the clearing',
        );
        $this->assertDirectoryExists(
            $dir,
            'the clearing removed the state DIRECTORY, which install() has just created and is '
                . 'about to write this run\'s own heartbeat into',
        );
    }

    /**
     * AND THE FILTER MUST NOT DISARM THE REAL CASE.
     *
     * Rule 15's positive component for the test above: "ignores an old record"
     * and "ignores every record" are the same green. A record written AFTER
     * arming, and then allowed to age past the budget, must still kill -- so
     * this runs the same `armedAt` argument through the same script and
     * asserts the opposite outcome.
     */
    public function testAHeartbeatWrittenAfterArmingStillKillsWhenItOverruns(): void
    {
        $this->requireProcessControl();

        $victim = $this->spawnVictim();
        $heartbeat = $this->tempPath('afterarming');

        $armedAt = \microtime(true);
        // Written after arming, and already past a 1s budget.
        \file_put_contents(
            $heartbeat,
            HangWatchdog::heartbeatPayload('Fixture\\WedgedTest::testNeverReturns', $armedAt + 0.001),
        );

        $report = $this->runWatchdog($victim, $heartbeat, 1.0, 20.0, $armedAt);

        $this->assertSame(1, $report['exitCode'], 'the watchdog must exit 1 after firing');
        $this->assertFalse(
            $this->isAlive($victim),
            'a record written after arming was ignored, so the armedAt filter has disarmed the '
                . 'watchdog outright rather than only rejecting inherited state',
        );
        $this->assertStringContainsString('Fixture\\WedgedTest::testNeverReturns', $report['stderr']);
    }

    /**
     * END TO END, THROUGH THE REAL `install()`.
     *
     * The two tests above drive the watchdog script directly. This one seeds a
     * stale heartbeat at the exact path `install()` will choose -- the child
     * does it itself, so the pid is its own -- and then loads the real
     * `tests/bootstrap.php`. Before the fix this child was SIGKILLed during
     * bootstrap and exited 137.
     *
     * It covers the OTHER half of the fix as well: `install()` unlinking
     * inherited state before spawning. Either half alone is enough to keep
     * this green, which is deliberate -- they are independent defences, and
     * the mutation table records that each one is separately load-bearing.
     */
    public function testBootstrapSurvivesAStaleHeartbeatLeftAtItsOwnStatePath(): void
    {
        $this->requireProcessControl();

        $bootstrap = \dirname(__DIR__) . '/bootstrap.php';
        $this->assertFileExists($bootstrap, 'the real bootstrap is what this test is about');

        // No backslash escapes anywhere in this snippet: a `\\n` or a `\\t`
        // written here is interpreted when this file is read, not when the
        // child runs, and it breaks the heredoc's own indentation.
        $code = <<<'PHP'
            $dir = sys_get_temp_dir() . '/candy-pty-hang-watchdog-' . getmypid();
            @mkdir($dir, 0700, true);
            file_put_contents(
                $dir . '/heartbeat',
                sprintf('%.6f' . chr(9) . '%s', microtime(true) - 999.0, 'Ghost\PreviousRun::testFromAnotherProcess')
            );
            require __BOOTSTRAP__;
            usleep(2500000);
            echo 'SURVIVED';
            @unlink($dir . '/heartbeat');
            foreach ((array) @glob($dir . '/heartbeat.*.tmp') as $t) { @unlink($t); }
            @rmdir($dir);
            PHP;

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = \proc_open(
            // str_replace, NOT sprintf: the snippet contains its own `%.6f`
            // and `%s`, which sprintf would consume as conversions.
            [\PHP_BINARY, '-r', \str_replace('__BOOTSTRAP__', \var_export($bootstrap, true), $code)],
            $descriptors,
            $pipes,
        );
        $this->assertIsResource($proc, 'could not spawn the bootstrap probe');

        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        $this->assertSame(
            0,
            $exit,
            'loading the real bootstrap with an inherited heartbeat at its own state path did '
                . 'not exit cleanly. Exit 137 here is the watchdog SIGKILLing the runner before '
                . "any test ran. stderr was:\n" . $stderr,
        );
        $this->assertStringContainsString(
            'SURVIVED',
            $stdout,
            'the probe did not reach the end of its own script',
        );
        $this->assertStringNotContainsString(
            'HANG WATCHDOG',
            $stderr,
            'the watchdog fired on a record it inherited rather than one this run wrote',
        );
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
        // A DIRECTORY PER FIXTURE, and the heartbeat inside it. These paths are
        // handed to watchdogWritingTo(), which derives `stateDir` from
        // `dirname()` -- so a heartbeat sitting directly in the system temp
        // directory would make `stateDir` the system temp directory itself, and
        // `stop()` ends with `@rmdir($this->stateDir)` and a glob-unlink. Today
        // nothing calls `stop()` on a fixture-built watchdog, so this is a trap
        // rather than a live bug; it is a trap worth removing while three lanes
        // are running suites that own other files in there.
        $dir = \sys_get_temp_dir()
            . '/candy-pty-hang-watchdog-fixture-' . $tag . '-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
        @\mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        $path = $dir . '/heartbeat';
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @param array<int, resource> $pipes
     * @return resource
     */
    private function startWatchdog(
        int $victimPid,
        string $heartbeat,
        float $budget,
        ?array &$pipes,
        ?float $armedAt = null,
    ) {
        $argv = [\PHP_BINARY, __DIR__ . '/hang-watchdog.php', (string) $victimPid, $heartbeat, (string) $budget];
        if ($armedAt !== null) {
            $argv[] = \sprintf('%.6f', $armedAt);
        }

        $pipes = [];
        $proc = \proc_open(
            $argv,
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
    private function runWatchdog(
        int $victimPid,
        string $heartbeat,
        float $budget,
        float $timeoutSec,
        ?float $armedAt = null,
    ): array {
        $proc = $this->startWatchdog($victimPid, $heartbeat, $budget, $pipes, $armedAt);
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
