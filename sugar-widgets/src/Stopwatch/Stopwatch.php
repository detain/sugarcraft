<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Stopwatch;

use SugarCraft\Widgets\Lang;
use SugarCraft\Widgets\Timer\Timer;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Style\Style;

/**
 * Count-up stopwatch. Mirror of {@see Timer}: starts at zero, increments
 * elapsed by {@see $interval} every tick. No upper bound. {@see view()}
 * formats elapsed time the same way as Timer. Ticks carry the owning id and
 * the generation epoch; update() refuses ticks addressed to other widgets or
 * to stopped generations (M12).
 */
final class Stopwatch implements Model
{
    /**
     * Monotonically increasing ID counter.
     *
     * IDs are assigned sequentially starting from 1 on first construction.
     * This is intentional — stable, predictable IDs aid debugging when
     * tracing tick routing in a live application.
     */
    private static int $nextId = 0;

    /**
     * Testing-only clock seam (W-37): when set, every wall-clock read
     * goes through this closure instead of `microtime(true)`, so suites
     * script irregular cadence deterministically. Never set in production.
     */
    private static ?\Closure $clock = null;

    public static function setClock(?\Closure $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): float
    {
        $clock = self::$clock;
        return $clock !== null ? (float) $clock() : \microtime(true);
    }

    public readonly int $id;

    /**
     * @param float $accumulated  seconds frozen into the base reading
     * @param float|null $resumedAt  wall-clock instant the current running
     *        segment started (null whenever not running)
     */
    private function __construct(
        public readonly float $accumulated,
        public readonly float $interval,
        public readonly bool $running,
        ?int $id = null,
        public readonly int $epoch = 0,
        public readonly ?float $resumedAt = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    public static function new(float $interval = 1.0): self
    {
        if (!is_finite($interval) || $interval <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('stopwatch.interval_positive'));
        }
        return new self(0.0, $interval, false);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof \SugarCraft\Widgets\Timer\TimerTick) {
            // Wiring bug, not noise: see the matching guard in Timer (013).
            throw new \TypeError('TimerTick fed to Stopwatch::update(); ticks are per-widget.');
        }
        if ($msg instanceof StopwatchTick && $msg->id === $this->id && $msg->epoch === $this->epoch && $this->running) {
            // Repaint driver only (W-37): fold the real seconds since the
            // last re-baseline into the frozen base, then re-baseline.
            $now  = self::now();
            $next = $this->copy(accumulated: $this->elapsed(), resumedAt: $now, resumedAtSet: true);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $label = $this->stateLabel();
        $time  = Timer::format($this->elapsed());
        return $label === '' ? $time : $time . ' ' . Style::new()->dim()->render($label);
    }

    /**
     * Plain-text state tag — '' while running (byte-identical running
     * frames), 'not started' at zero, 'paused' once elapsed time is held
     * (W-19/021 four-state matrix; see {@see Timer::stateLabel()}).
     */
    public function stateLabel(): string
    {
        if ($this->running) {
            return '';
        }
        return $this->elapsed() <= 0.0
            ? Lang::t('stopwatch.not_started_tag')
            : Lang::t('stopwatch.paused_tag');
    }

    /**
     * Idempotent — calling start() while the stopwatch is already running
     * is a no-op so duplicate start events don't spawn parallel tick
     * chains and double-count elapsed time. Each flip into running bumps
     * the epoch, so a tick still in flight from a stopped generation can
     * never count toward the restarted chain (M12).
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function start(): array
    {
        if ($this->running) {
            return [$this, null];
        }
        $next = $this->copy(running: true, epoch: $this->epoch + 1, resumedAt: self::now(), resumedAtSet: true);
        return [$next, $next->tick()];
    }

    public function stop(): self
    {
        // Freeze the running segment into the base on the way out.
        return $this->copy(accumulated: $this->elapsed(), running: false);
    }

    public function reset(): self
    {
        return $this->copy(accumulated: 0.0, running: false);
    }

    /**
     * Flip the running state. When running → stop; when stopped →
     * start (returning the start cmd).
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function toggle(): array
    {
        return $this->running ? [$this->stop(), null] : $this->start();
    }

    public function isRunning(): bool { return $this->running; }

    /**
     * Stable per-instance ID — used by {@see StopwatchTick} to route the
     * matching update events. Mirrors charmbracelet/bubbles `ID()`.
     */
    public function id(): int { return $this->id; }

    /**
     * Elapsed seconds measured against the WALL CLOCK (W-37, replaces the
     * old tick-count accumulator): while running, the frozen base plus the
     * real time since {@see $resumedAt}; frozen otherwise. Ticks are pure
     * repaint drivers — a stalled loop keeps accruing real seconds while
     * running, and start/stop transitions are the only thing that moves
     * the base. Mirrors charmbracelet/bubbles stopwatch, which adds real
     * message-time deltas.
     */
    public function elapsed(): float
    {
        if (!$this->running || $this->resumedAt === null) {
            return $this->accumulated;
        }
        return $this->accumulated + (self::now() - $this->resumedAt);
    }

    /**
     * Tick cadence in seconds. Mirrors charmbracelet/bubbles `Interval` field
     * which Bubbles exposes for inspection by surrounding code.
     */
    public function interval(): float { return $this->interval; }

    /**
     * Recreate the stopwatch with a new interval. Elapsed time and
     * running state are preserved. Mirrors charmbracelet/bubbles' mutable
     * `Interval` field assignment (rendered immutable here so
     * snapshot-based tests stay deterministic).
     */
    public function withInterval(float $interval): self
    {
        if (!is_finite($interval) || $interval <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('stopwatch.interval_positive'));
        }
        return $this->copy(interval: $interval);
    }

    /**
     * The construction funnel (W-32): every copy of an existing state
     * routes through here so `new self(` lives in the ctor, the new()
     * factory, and this one method only.
     */
    private function copy(
        ?float $accumulated = null,
        ?float $interval = null,
        ?bool $running = null,
        ?int $epoch = null,
        ?float $resumedAt = null,
        bool $resumedAtSet = false,
    ): self {
        return new self(
            $accumulated ?? $this->accumulated,
            $interval    ?? $this->interval,
            $running     ?? $this->running,
            $this->id,
            $epoch       ?? $this->epoch,
            $resumedAtSet ? $resumedAt : $this->resumedAt,
        );
    }

    private function tick(): \Closure
    {
        $id = $this->id;
        $epoch = $this->epoch;
        return Cmd::tick($this->interval, static fn(): StopwatchTick => new StopwatchTick($id, $epoch));
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
