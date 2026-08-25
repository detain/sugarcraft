<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Parent-side half of the candy-pty hang watchdog (E490).
 *
 * WHY THIS EXISTS. Round 55's merged-floor take had this suite stall
 * indefinitely at roughly test 363 of 608: `State: S`, `wchan: do_select`,
 * two `/dev/ptmx` descriptors open, 0.1% CPU for nine minutes, and it had
 * to be killed by pid. It has not reproduced since (1 hang in ~76 takes).
 *
 * The root cause is still open, but the SHAPE of the problem is not, and it
 * is structural rather than incidental: several of this suite's loops have
 * no deadline of their own. `PosixPump::pump()` is a `while (true)` whose
 * only exits are "child exited", "stdin EOF plus grace expired" and "stdout
 * EPIPE"; `MultiPump::run()` is `while (!allDone())` and, because the parent
 * holds the slave fd open, a session's master never EOFs -- so a child that
 * is never observed to exit means that loop never terminates. A test built
 * on either can only ever HANG; it can never FAIL. And a hang is invisible:
 * locally it reads as a slow suite, in CI as a job timeout naming nothing.
 *
 * So this bounds the wait at the harness level, which is the one place that
 * covers every such loop at once including the ones nobody has found yet.
 *
 * WHAT IT ACTUALLY CONVERTS THE HANG INTO, stated precisely because the first
 * draft of this paragraph overstated it and a mechanism written down with
 * confidence is how the next reader stops checking. It does NOT produce a
 * PHPUnit failure: the runner is SIGKILLed, so PHPUnit emits nothing at all
 * and the process exits 137 with no summary line. Measured -- a mutation that
 * made the watchdog fire unconditionally ended the run at `rc=137` with no
 * `Tests:` line anywhere in the output. What it converts "the suite stopped
 * and we do not know where" into is a BOUNDED run that exits non-zero, plus a
 * report on fd 2 naming the test and carrying the forensic bundle E490 had to
 * be assembled by hand. That is the whole gain, and it is enough: a job that
 * fails at 137 with a name in the log is diagnosable, and a job that hangs
 * until the CI timeout is not.
 *
 * HOW. Registered from `tests/bootstrap.php`, which PHPUnit loads before it
 * seals the event facade (`TextUI\Application` loads the bootstrap script
 * well before `EventFacade::instance()->seal()`), so this needs no
 * `<extensions>` entry in `phpunit.xml`. On every `Test\Prepared` the
 * current test id and start time are written to a heartbeat file with an
 * atomic rename; a watchdog PROCESS polls that file and kills the runner if
 * one test overruns the budget.
 *
 * It is an out-of-process design on purpose -- see the header of
 * `hang-watchdog.php` for the two measured reasons an in-process
 * `pcntl_alarm()` would not survive this suite.
 *
 * Opt out with `CANDY_PTY_HANG_BUDGET=0`; override the per-test budget in
 * seconds with any other positive value.
 *
 * @see hang-watchdog.php                                — the watchdog process
 * @see \SugarCraft\Pty\Tests\Support\HangWatchdogTest   — known-positive AND
 *                                                         known-negative fixture
 */
final class HangWatchdog
{
    /**
     * Per-test budget in seconds. The slowest legitimate test in this suite
     * is `ControllingTerminalTest::testCtrlCKillsChildThroughPty`, which
     * asserts its own elapsed time is under 8.5s; the budget is set well
     * clear of that so a slow CI box widens the margin rather than eating
     * it. It is a HANG detector, not a performance budget.
     */
    public const DEFAULT_BUDGET_SEC = 90.0;

    private static ?self $instance = null;

    /** @var resource|null */
    private $process = null;

    private function __construct(
        private readonly string $heartbeatPath,
        private readonly string $stateDir,
    ) {
    }

    /**
     * Install the watchdog and register the event subscribers. Idempotent;
     * a second call is a no-op so a stray bootstrap re-entry cannot leave
     * two watchdogs racing to kill the same pid.
     *
     * Silently does nothing (returns false) when the platform cannot support
     * it -- non-POSIX, no ext-posix, no `proc_open` -- because a missing
     * watchdog must never be the reason a suite fails to start.
     */
    public static function install(?float $budgetSec = null): bool
    {
        if (self::$instance !== null) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_kill')
            || !\function_exists('proc_open')
        ) {
            return false;
        }

        $budgetSec ??= self::budgetFromEnv();
        if (!self::armsFor($budgetSec)) {
            return false;
        }

        $dir = \sys_get_temp_dir() . '/candy-pty-hang-watchdog-' . \getmypid();
        if (!@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            return false;
        }

        // THE DIRECTORY MAY ALREADY EXIST, AND ITS CONTENTS ARE THEN A TRAP.
        // The name carries only a pid, and `stop()` -- the only thing that
        // removes it -- cannot run when the runner is SIGKILLed, which is
        // precisely what this watchdog does to it. So a fired watchdog leaves
        // its own state behind, and the next process to be given that pid
        // inherits a heartbeat describing a test that finished long ago.
        // Measured: with such a file seeded, the runner is SIGKILLed during
        // bootstrap, before a single test executes, and the forensic dump
        // names a test that is not running. That failure is rc 137 with no
        // `Tests:` line -- indistinguishable from the hang this exists to
        // diagnose. Clearing it here is the deterministic half of the fix;
        // `armedAt` below is the half that does not depend on this succeeding.
        @\unlink($dir . '/heartbeat');
        foreach ((array) @\glob($dir . '/heartbeat.*.tmp') as $stale) {
            @\unlink((string) $stale);
        }

        $self = new self($dir . '/heartbeat', $dir);
        if (!$self->spawn($budgetSec)) {
            @\rmdir($dir);
            return false;
        }

        self::$instance = $self;

        try {
            $facade = Facade::instance();
            $facade->registerSubscriber(new HangWatchdogPreparedSubscriber($self));
            $facade->registerSubscriber(new HangWatchdogFinishedSubscriber($self));
        } catch (\Throwable) {
            // Facade already sealed (bootstrap invoked from somewhere
            // unexpected). The watchdog would then never see a heartbeat and
            // would never fire, so tear it straight back down rather than
            // leave a process that can only produce a false positive.
            $self->stop();
            self::$instance = null;
            return false;
        }

        // Belt and braces: the subscriber teardown covers a normal run, this
        // covers `exit()` from a fatal or from PHPUnit's own error paths.
        \register_shutdown_function(static function () use ($self): void {
            $self->stop();
        });

        return true;
    }

    /**
     * Whether $budgetSec is a budget this watchdog will arm for.
     *
     * A NAMED PREDICATE RATHER THAN AN INLINE COMPARISON, and the reason is a
     * measurement rather than taste. The test that used to cover the opt-out
     * asserted `install(0.0) === false` from inside a running suite -- where
     * {@see install()} returns false at its FIRST guard, because bootstrap has
     * already installed an instance, and never looks at the budget at all.
     * Measured: with the budget guard deleted outright that assertion still
     * passed. (It cannot reach the arming path either way: PHPUnit's event
     * facade is sealed by the time any test runs, so a mid-suite `install()`
     * with ANY budget lands in the catch branch.) The decision has to be
     * reachable on its own to be checkable at all.
     *
     * Non-positive means "do not arm": `CANDY_PTY_HANG_BUDGET=0` is the
     * documented opt-out, and a negative budget would otherwise fire the
     * instant the first test started.
     */
    public static function armsFor(float $budgetSec): bool
    {
        return $budgetSec > 0.0;
    }

    /** The per-test budget, honouring `CANDY_PTY_HANG_BUDGET`. */
    public static function budgetFromEnv(): float
    {
        $raw = \getenv('CANDY_PTY_HANG_BUDGET');
        if (!\is_string($raw) || !\is_numeric($raw)) {
            return self::DEFAULT_BUDGET_SEC;
        }
        return (float) $raw;
    }

    /**
     * Record that $testId is now in flight. Written with an atomic rename so
     * the watchdog can never observe a half-written record and mistake a
     * torn timestamp for an overrun.
     */
    public function beat(string $testId): void
    {
        $tmp = $this->heartbeatPath . '.' . \getmypid() . '.tmp';
        if (@\file_put_contents($tmp, self::heartbeatPayload($testId, \microtime(true))) === false) {
            return;
        }
        @\rename($tmp, $this->heartbeatPath);
    }

    /**
     * The on-disk heartbeat record: start time, a TAB, then the test id.
     *
     * Factored out of {@see beat()} so the fixture test can build a record
     * with THIS producer and hand it to the real watchdog process as its
     * consumer. A fixture that spells the format out for itself would keep
     * passing after the producer changed shape, which is the whole class of
     * defect this file exists to avoid.
     */
    public static function heartbeatPayload(string $testId, float $startedAt): string
    {
        return \sprintf("%.6f\t%s", $startedAt, $testId);
    }

    /** Tear the watchdog down. Idempotent. */
    public function stop(): void
    {
        if (\is_resource($this->process)) {
            $status = @\proc_get_status($this->process);
            if (\is_array($status) && ($status['running'] ?? false) === true && ($status['pid'] ?? 0) > 0) {
                @\posix_kill((int) $status['pid'], \SIGKILL);
            }
            @\proc_close($this->process);
        }
        $this->process = null;

        @\unlink($this->heartbeatPath);
        foreach ((array) @\glob($this->heartbeatPath . '.*.tmp') as $stale) {
            @\unlink((string) $stale);
        }
        @\rmdir($this->stateDir);
    }

    /**
     * Spawn the watchdog process.
     *
     * `PHP_BINARY` rather than `php` on `PATH`: a harness that resolves the
     * interpreter differently from the runner it is watching is a harness
     * that can silently watch nothing (round 44 lost a child-process census
     * to exactly that).
     *
     * Descriptors: 0 and 1 are given `/dev/null` so the watchdog can never
     * interleave with PHPUnit's own progress output, while 2 is deliberately
     * INHERITED -- the forensic dump has to reach whoever is reading the
     * failing run, and a hang is precisely the case where nobody is going to
     * go looking for a side-channel file.
     */
    private function spawn(float $budgetSec): bool
    {
        // ARMED-AT IS PASSED DOWN so the child can refuse a record that predates
        // it. Taken HERE, in the parent, and not in the child: the child's own
        // start time is useless for this, because the parent may well write the
        // first test's heartbeat before the child has run its first line, and a
        // watchdog that ignores the first test is a watchdog that misses the
        // hang the suite starts with. Every legitimate heartbeat is written
        // after this instant; every stale one predates it.
        $armedAt = \microtime(true);
        $script = __DIR__ . '/hang-watchdog.php';
        if (!\is_file($script)) {
            return false;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            // 2 intentionally omitted => inherited. See doc-comment.
        ];
        $pipes = [];
        $proc = @\proc_open(
            [
                \PHP_BINARY,
                $script,
                (string) \getmypid(),
                $this->heartbeatPath,
                (string) $budgetSec,
                \sprintf('%.6f', $armedAt),
            ],
            $descriptors,
            $pipes,
        );
        if (!\is_resource($proc)) {
            return false;
        }
        $this->process = $proc;
        return true;
    }
}

/**
 * @internal Bridges `Test\Prepared` to {@see HangWatchdog::beat()}.
 */
final class HangWatchdogPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private readonly HangWatchdog $watchdog)
    {
    }

    public function notify(Prepared $event): void
    {
        $this->watchdog->beat($event->test()->id());
    }
}

/**
 * @internal Bridges the end of the run to {@see HangWatchdog::stop()}.
 */
final class HangWatchdogFinishedSubscriber implements ExecutionFinishedSubscriber
{
    public function __construct(private readonly HangWatchdog $watchdog)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $this->watchdog->stop();
    }
}
