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
     *
     *      */
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly float $elapsed,
        public readonly float $interval,
        public readonly bool $running,
        ?int $id = null,
        public readonly int $epoch = 0,
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
            $next = new self($this->elapsed + $this->interval, $this->interval, true, $this->id, $this->epoch);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $label = $this->stateLabel();
        $time  = Timer::format($this->elapsed);
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
        return $this->elapsed <= 0.0
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
        $next = new self($this->elapsed, $this->interval, true, $this->id, $this->epoch + 1);
        return [$next, $next->tick()];
    }

    public function stop(): self
    {
        return new self($this->elapsed, $this->interval, false, $this->id, $this->epoch);
    }

    public function reset(): self
    {
        return new self(0.0, $this->interval, false, $this->id, $this->epoch);
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
     * matching update events. Mirrors the upstream Bubbles `ID()`.
     */
    public function id(): int { return $this->id; }

    /**
     * Elapsed seconds as a tick-count accumulator (ticks consumed ×
     * {@see $interval}) — NOT wall-clock: it advances only while update()
     * consumes StopwatchTicks, so a stalled loop or a stopped chain freezes it
     * regardless of real time passed (M14).
     */
    public function elapsed(): float { return $this->elapsed; }

    /**
     * Tick cadence in seconds. Mirrors the upstream `Interval` field
     * which Bubbles exposes for inspection by surrounding code.
     */
    public function interval(): float { return $this->interval; }

    /**
     * Recreate the stopwatch with a new interval. Elapsed time and
     * running state are preserved. Mirrors upstream's mutable
     * `Interval` field assignment (rendered immutable here so
     * snapshot-based tests stay deterministic).
     */
    public function withInterval(float $interval): self
    {
        if (!is_finite($interval) || $interval <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('stopwatch.interval_positive'));
        }
        return new self($this->elapsed, $interval, $this->running, $this->id, $this->epoch);
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
