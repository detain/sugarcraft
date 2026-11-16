<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use PHPUnit\Event\Facade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * Parent-side half of the sugar-core hang watchdog (CI-wave-4 lane 3).
 *
 * WHY THIS EXISTS. The macOS · PHP 8.3 · sugar-core CI job has hung to the
 * 30-minute cap three consecutive runs (job 133516230168; earlier runs
 * 43896202199 and 43540253425 per /tmp/opencode/ciwatch-1116/TRIAGE4.md;
 * each cancelled at exactly 35:00 wall inside step 8
 * "Run tests"), and the test name is UNOBTAINABLE from CI: the log blobs of
 * cancelled macOS jobs return BlobNotFound within minutes of cancellation,
 * verified twice in /tmp/opencode/ciwatch-1116/TRIAGE4.md. The suite therefore
 * fails blind — Linux completes in ~99s, Windows in ~60s, so this is a hang,
 * not slowness. The leading hypothesis is the sugar-pty arm64 TIOCSWINSZ FFI
 * failure mode (the hold-out recorded at scripts/affected-libs.php:35-53)
 * reached through sugar-core's own PosixBackend consumer leg, whose
 * descriptor-pair tests are the same posix_openpt + grantpt + ioctl chain that
 * forced that hold-out.
 *
 * The root cause is a Darwin-box investigation this lane cannot run. The SHAPE
 * of the problem is the same one sugar-pty's E490 watchdog documents: waits
 * whose only exits are "the child answered" can only ever HANG, never FAIL,
 * and a hang is invisible — locally as a slow suite, in CI as a job timeout
 * naming nothing. Bounding the wait at the harness level is the one control
 * that covers every such leg at once, including the ones nobody has found yet,
 * and it converts the blind 35-minute cancellation into a bounded run that
 * exits non-zero within ~2 minutes naming the offending test — a diagnosable
 * failure.
 *
 * WHAT IT ACTUALLY CONVERTS THE HANG INTO, stated precisely, carried over from
 * the sugar-pty original (E490) because the first draft of THAT paragraph
 * overstated it. It does NOT produce a PHPUnit failure: the runner is
 * SIGKILLed, so PHPUnit emits nothing at all and the process exits 137 with no
 * summary line. What it converts "the suite stopped and we do not know where"
 * into is a BOUNDED run that exits non-zero, plus a report on fd 2 naming the
 * test and carrying the forensic bundle. A job that fails at 137 with a name in
 * the log is diagnosable; a job that hangs until the CI timeout is not.
 *
 * HOW. Registered from `tests/bootstrap.php`, which PHPUnit loads before it
 * seals the event facade, so this needs no `<extensions>` entry in
 * `phpunit.xml`. On every `Test\Prepared` the current test id and start time
 * are written to a heartbeat file with an atomic rename; a watchdog PROCESS
 * polls that file and kills the runner if one test overruns the budget.
 *
 * It is an out-of-process design on purpose, for the two measured reasons
 * recorded on `hang-watchdog.php`: a wedged FFI call is not guaranteed to run
 * userland signal handlers promptly, and this suite's own signal bookkeeping
 * (Program restores dispositions via pcntl_async_signals on its run paths)
 * would be free to disarm an in-process alarm.
 *
 * This is a port of the sugar-pty instrument (sugar-pty/tests/Support/
 * HangWatchdog.php), kept structurally faithful on purpose: that file has been
 * through the mutation-proving cycle below, and its inherited-state trap,
 * armedAt filter and graceful platform no-ops are each measured, not styled.
 *
 * The one place the port DIVERGES (CI-wave-5 5B): signal delivery goes through
 * {@see HangKill} instead of calling posix_kill() directly. The original's hard
 * requirement on ext-posix is what kept this instrument from ever arming on the
 * macOS runners it was ported for; /bin/kill covers every POSIX runner where
 * the extension is missing.
 *
 * Opt out with `SUGAR_CORE_HANG_BUDGET=0`; override the per-test budget in
 * seconds with any other positive value.
 *
 * @see hang-watchdog.php   — the watchdog process (Darwin-aware forensics)
 * @see \SugarCraft\Pty\Tests\Support\HangWatchdog — the E490 original
 */
final class HangWatchdog
{
    /**
     * Per-test budget in seconds: 120.
     *
     * MEASURED basis (this host, full suite, --log-junit, /tmp/opencode/
     * ci4-core/junit-baseline.xml): the slowest legitimate test is
     * WorkerPoolTest::testWriteDeadlineRejectsADeafPipe at 11.63s, then
     * ProgramExecCaptureTest::testWriteAllResumesShortWritesAtTheFwriteOffset
     * at 10.55s. The largest budget any test asserts against ITSELF is
     * SuspendResumeTest's G1 stop ladder — 30s total (raise 12s + kill-pid 9s
     * + kill-group 9s, see 78fb89c42), and a green run of it cannot exceed
     * that budget plus overhead. The macOS runners have historically run these
     * PTY/stty-heavy legs at a low single-digit multiple of Linux speed; 120s
     * is 4x the suite's largest internal budget and ~10x its slowest measured
     * test, so a slow CI box widens the margin rather than eating it. It is a
     * HANG detector, not a performance budget.
     *
     * Against the failure side: 120s is under 7% of the 30-minute CI cap, so
     * a hung Darwin leg now reports its name in about two minutes instead of
     * cancelling silently at thirty-five.
     */
    public const DEFAULT_BUDGET_SEC = 120.0;

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
     * it — Windows, no `proc_open`, or no way to deliver a signal at all —
     * because a missing watchdog must never be the reason a suite fails to
     * start. That no-op is what keeps the Windows runners' behavior identical
     * pre- and post-port.
     *
     * THE GATE USED TO DEMAND ext-posix (CI-wave-5 5B). That demand is why the
     * instrument never armed where it was needed: the macOS runners ship no
     * ext-posix (not in the default brew PHP, not in ci.yml:30's
     * PHP_EXTENSIONS), so the three blind 35:00 cancellations it was ported
     * to bound ran with the watchdog switched off by its own pre-flight.
     * Signal delivery now goes through {@see HangKill}, which falls back to
     * /bin/kill on any POSIX box; only Windows and a POSIX box with neither
     * ext-posix nor /bin/kill still refuse to arm.
     */
    public static function install(?float $budgetSec = null): bool
    {
        if (self::$instance !== null) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('proc_open')
            || !HangKill::supported()
        ) {
            return false;
        }

        $budgetSec ??= self::budgetFromEnv();
        if (!self::armsFor($budgetSec)) {
            return false;
        }

        $dir = \sys_get_temp_dir() . '/sugar-core-hang-watchdog-' . \getmypid();
        if (!@\mkdir($dir, 0o700, true) && !\is_dir($dir)) {
            return false;
        }

        // THE DIRECTORY MAY ALREADY EXIST, AND ITS CONTENTS ARE THEN A TRAP.
        // The name carries only a pid, and `stop()` — the only thing that
        // removes it — cannot run when the runner is SIGKILLed, which is
        // precisely what this watchdog does to it. So a fired watchdog leaves
        // its own state behind, and the next process to be given that pid
        // inherits a heartbeat describing a test that finished long ago. The
        // sugar-pty original measured the consequence: the runner is SIGKILLed
        // during bootstrap, before a single test executes, and the forensic
        // dump names a test that is not running — rc 137 with no `Tests:`
        // line, indistinguishable from the hang this exists to diagnose.
        // Clearing it here is the deterministic half of the fix; `armedAt`
        // below is the half that does not depend on this succeeding.
        self::clearInheritedState($dir);

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
     * A named predicate rather than an inline comparison, carrying the
     * sugar-pty lesson: the decision must be reachable on its own to be
     * checkable at all — a mid-suite `install()` cannot exercise the budget
     * path (the first guard and the sealed facade short-circuit it), so the
     * arming decision is asserted here, at the place it is actually made.
     *
     * Non-positive means "do not arm": `SUGAR_CORE_HANG_BUDGET=0` is the
     * documented opt-out, and a negative budget would otherwise fire the
     * instant the first test started.
     */
    public static function armsFor(float $budgetSec): bool
    {
        return $budgetSec > 0.0;
    }

    /** The per-test budget, honouring `SUGAR_CORE_HANG_BUDGET`. */
    public static function budgetFromEnv(): float
    {
        $raw = \getenv('SUGAR_CORE_HANG_BUDGET');
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
     * Factored out of {@see beat()} so a probe can build a record with THIS
     * producer and hand it to the real watchdog process as its consumer — a
     * fixture that spells the format out for itself would keep passing after
     * the producer changed shape, which is the whole class of defect this
     * file exists to avoid.
     */
    public static function heartbeatPayload(string $testId, float $startedAt): string
    {
        return \sprintf("%.6f\t%s", $startedAt, $testId);
    }

    /**
     * Remove any heartbeat left in $dir by a previous occupant of this pid.
     *
     * Kept a separate method (sugar-pty law): an unpinnable defence is one
     * that gets deleted by the next reader as dead code.
     *
     * @internal exposed for probes; not part of the harness API
     */
    public static function clearInheritedState(string $dir): void
    {
        @\unlink($dir . '/heartbeat');
        foreach ((array) @\glob($dir . '/heartbeat.*.tmp') as $stale) {
            @\unlink((string) $stale);
        }
    }

    /** Tear the watchdog down. Idempotent. */
    public function stop(): void
    {
        if (\is_resource($this->process)) {
            $status = @\proc_get_status($this->process);
            if (\is_array($status) && ($status['running'] ?? false) === true && ($status['pid'] ?? 0) > 0) {
                HangKill::kill((int) $status['pid']);
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
     * that can silently watch nothing.
     *
     * Descriptors: 0 and 1 are given `/dev/null` so the watchdog can never
     * interleave with PHPUnit's own progress output, while 2 is deliberately
     * INHERITED — the forensic dump has to reach whoever is reading the
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
