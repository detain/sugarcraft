<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\ExtUvLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;

/**
 * Model that blocks for a while and then arms a tick, at one of the two sites a
 * program can arm one from: inside `update()`, or inside the Cmd `update()`
 * returned. The distinction is the whole point of these tests.
 */
final class TimerAccuracyProbeModel implements Model
{
    use \SugarCraft\Core\SubscriptionCapable;

    public ?float $armedAt = null;
    public ?float $firedAt = null;

    public function __construct(
        private readonly string $site,
        private readonly float $block,
        private readonly float $tick,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $tick = fn (): \Closure => Cmd::tick($this->tick, function (): Msg {
            $this->firedAt = microtime(true);
            return new QuitMsg();
        });

        if ($this->site === 'update') {
            usleep((int) ($this->block * 1_000_000));
            $this->armedAt = microtime(true);
            return [$this, $tick()];
        }

        return [$this, function () use ($tick): Msg {
            usleep((int) ($this->block * 1_000_000));
            $this->armedAt = microtime(true);
            return ($tick())();
        }];
    }

    public function view(): string
    {
        return '';
    }
}

/**
 * Timer accuracy under a libuv-backed loop. See the timer-accuracy notes on
 * {@see Program::run()} for the mechanism and the full measurement table.
 *
 * Both tests need ext-uv to mean anything: without it the autodetected loop is
 * a `StreamSelectLoop`, which refreshes its clock at arm time and is accurate
 * at every site, so neither assertion could ever fail. They skip rather than
 * pass vacuously, so a green run on a box without ext-uv is not mistaken for
 * coverage.
 */
final class ProgramTimerAccuracyTest extends TestCase
{
    private const BLOCK = 0.35;
    private const TICK  = 0.15;

    protected function setUp(): void
    {
        if (!extension_loaded('uv')) {
            self::markTestSkipped('ext-uv is not installed; the stale-clock window does not exist without it');
        }
    }

    /**
     * The invariant that makes blocking work in `update()` harmless: the
     * runtime defers every Cmd through `futureTick()`, and ExtUvLoop drains
     * that queue between `uv_run()` passes — a boundary that refreshes libuv's
     * cached clock. Collapse `scheduleCmd()` into a direct call and the tick
     * inherits a clock stale by the whole `update()` block, firing instantly.
     */
    public function testBlockingInUpdateDoesNotShortenTheTimerTheReturnedCmdArms(): void
    {
        $model = $this->runProbe('update');

        self::assertNotNull($model->firedAt, 'the tick never fired');
        self::assertGreaterThanOrEqual(
            self::TICK * 0.8,
            $model->firedAt - $model->armedAt,
            'the tick fired early — Cmds are no longer deferred across a loop-iteration boundary',
        );
    }

    /**
     * The other half of the same mechanism, pinned as documentation rather than
     * as a wish: a Cmd that blocks and then arms is inside ONE loop iteration,
     * so the timer loses exactly what the Cmd blocked for. This is inherent to
     * libuv, not something candy-core can fix, and it is the reachable defect
     * the docs on Program::run() warn about.
     *
     * If this test fails, the trap has stopped reproducing — libuv or ext-uv
     * changed. Re-measure and update the docs; do not simply delete it.
     */
    public function testBlockingInsideACmdDoesShortenTheTimerThatCmdArms(): void
    {
        $model = $this->runProbe('cmd');

        self::assertNotNull($model->firedAt, 'the tick never fired');
        self::assertLessThan(
            self::TICK * 0.5,
            $model->firedAt - $model->armedAt,
            'the tick waited its full delay — libuv no longer computes deadlines against a stale cached clock',
        );
    }

    /**
     * The claim that has been documented wrongly three times, now pinned so it
     * cannot be re-broken silently: arming a timer BEFORE the loop's first
     * `run()` is not a safe harbour.
     *
     * It only looks like one in the degenerate case where the armed timer is the
     * loop's earliest deadline — `UV::RUN_ONCE` sizes the poll against the same
     * stale clock the deadline was built from, so the two errors cancel. Give
     * the loop one handle due sooner (a `Program` always has a framerate tick)
     * and the poll returns early, the post-poll clock refresh reveals the true
     * time, and the arm loses the whole pre-run idle.
     *
     * Both halves are asserted, because it is the CONTRAST that is the
     * mechanism. If the first half starts failing, ext-uv or libuv changed the
     * cancellation; re-measure and update the notes on {@see Program::run()}.
     */
    public function testATimerArmedBeforeRunIsShortenedAsSoonAsTheLoopHasAnEarlierHandle(): void
    {
        $idle  = 0.4;
        $delay = 0.5;

        // Degenerate: the armed timer is the only deadline, so it waits in full.
        $alone = $this->measurePreRunArm($idle, $delay, false);
        self::assertGreaterThanOrEqual(
            $delay * 0.8,
            $alone,
            'the degenerate pre-run arm fired early — the RUN_ONCE cancellation no longer holds',
        );

        // Realistic: one periodic handle due sooner is all it takes.
        $withTick = $this->measurePreRunArm($idle, $delay, true);
        self::assertLessThan(
            $delay * 0.5,
            $withTick,
            'the pre-run arm survived an earlier handle — libuv no longer computes deadlines '
            . 'against a stale cached clock, so the docs on Program::run() need re-measuring',
        );
    }

    /**
     * Idle for `$idle` on a never-run loop, arm a `$delay` timer, then run.
     * Returns arm -> fire in seconds.
     */
    private function measurePreRunArm(float $idle, float $delay, bool $withPeriodicHandle): float
    {
        $loop = new ExtUvLoop();

        if ($withPeriodicHandle) {
            // Stands in for Program's framerate tick.
            $loop->addPeriodicTimer(1 / 60, static function (): void {});
        }

        usleep((int) ($idle * 1_000_000));

        $fired = null;
        $armed = microtime(true);
        $loop->addTimer($delay, static function () use ($loop, &$fired): void {
            $fired = microtime(true);
            $loop->stop();
        });
        $loop->run();

        self::assertNotNull($fired, 'the pre-run timer never fired at all');

        return $fired - $armed;
    }

    private function runProbe(string $site): TimerAccuracyProbeModel
    {
        $model = new TimerAccuracyProbeModel($site, self::BLOCK, self::TICK);

        [$in, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($peer, 'a');
        $out = fopen('php://temp', 'w+');

        // The bail-out is reliable because the loop is constructed HERE, micro-
        // seconds before the arm: libuv's cached clock cannot have gone stale in
        // that window, so the 10s deadline really is 10s away.
        //
        // It is NOT reliable because "pre-run arms are safe" — they are not. A
        // Program owns a stdin read watcher and a framerate tick, and any handle
        // due sooner than the armed timer exposes the stale clock in full (see
        // the timer-accuracy notes on Program::run()). So keep the construction
        // inside this method: hoist it to a property or share one loop across
        // probes and the accumulated idle silently turns this 10s net into a 0s
        // one, and the failure surfaces as "the tick never fired" — pointing at
        // the wrong thing entirely.
        $loop = new ExtUvLoop();
        $loop->addTimer(10.0, static fn () => $loop->stop());

        (new Program($model, new ProgramOptions(
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            windowSize: ['cols' => 80, 'rows' => 24],
        )))->run();

        fclose($peer);
        fclose($out);

        return $model;
    }
}
