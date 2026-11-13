<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Timer;

use SugarCraft\Widgets\Lang;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Style\Style;

/**
 * Countdown timer. Construct with a duration; call {@see start()} to begin
 * ticking. {@see update()} consumes {@see TimerTick}s and reschedules the
 * next tick until {@see $remaining} hits zero, at which point a single
 * {@see TimeoutMsg} is dispatched and the timer stops itself.
 *
 * Multiple Timer instances coexist on one event loop because each carries
 * a unique id; ticks for other ids are ignored, and ticks from stopped
 * generations are refused via the epoch (M12).
 */
final class Timer implements Model
{
    /**
     * Monotonically increasing ID counter.
     *
     * IDs are assigned sequentially starting from 1 on first construction.
     * This is intentional — stable, predictable IDs aid debugging when
     * tracing tick/timeout routing in a live application.
     *
     *      */
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly float $remaining,
        public readonly float $interval,
        public readonly bool $running,
        public readonly bool $timedOut,
        ?int $id = null,
        public readonly int $epoch = 0,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    public static function new(float $duration, float $interval = 1.0): self
    {
        self::checkDuration($duration);
        self::checkInterval($interval);
        return new self($duration, $interval, false, false);
    }

    /**
     * One validation ladder for the duration door and the reset() door
     * (sugar-spring's precedent: non-finite throws at ingress). NaN used
     * to pass `$duration < 0.0`, so a NaN timer ran forever and never
     * fired; INF meant the same silent-forever contract (M8).
     */
    private static function checkDuration(float $v): void
    {
        if (!is_finite($v) || $v < 0.0) {
            throw new \InvalidArgumentException(Lang::t('timer.duration_nonneg'));
        }
    }

    private static function checkInterval(float $v): void
    {
        if (!is_finite($v) || $v <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('timer.interval_positive'));
        }
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
        if ($msg instanceof \SugarCraft\Widgets\Stopwatch\StopwatchTick) {
            // Wiring bug, not noise: a Stopwatch tick can never advance a
            // Timer, and silently no-oping hid the crossed feed (013).
            throw new \TypeError('StopwatchTick fed to Timer::update(); ticks are per-widget.');
        }
        if ($msg instanceof TimerTick && $msg->id === $this->id && $msg->epoch === $this->epoch && $this->running) {
            $newRemaining = max(0.0, $this->remaining - $this->interval);
            if ($newRemaining <= 0.0) {
                $next = new self(0.0, $this->interval, false, true, $this->id, $this->epoch);
                return [$next, Cmd::send(new TimeoutMsg($this->id))];
            }
            $next = new self($newRemaining, $this->interval, true, false, $this->id, $this->epoch);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $label = $this->stateLabel();
        $time  = self::format($this->remaining);
        return $label === '' ? $time : $time . ' ' . Style::new()->dim()->render($label);
    }

    /**
     * Plain-text state tag for the non-running states — '' while running,
     * so the running bytes stay byte-identical (hard constraint). Timeout
     * and pause were invisible in the frame: a paused '0:10' and a fresh
     * '0:10', or a timed-out '0:00' and a reset '0:00', rendered the same,
     * leaving AT consumers nothing to diff (W-19/021). Hosts that want to
     * style the tag themselves compose it from this label.
     */
    public function stateLabel(): string
    {
        if ($this->running) {
            return '';
        }
        return $this->timedOut ? Lang::t('timer.timeout_tag') : Lang::t('timer.paused_tag');
    }

    /**
     * Set running=true and schedule the first tick. Idempotent — calling
     * start() while the timer is already running is a no-op so duplicate
     * start events don't spawn parallel tick chains. Each flip into running
     * bumps the epoch, so a tick still in flight from a stopped generation
     * can never count toward the restarted chain (M12).
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function start(): array
    {
        if ($this->running) {
            return [$this, null];
        }
        if ($this->remaining <= 0.0) {
            return [$this, Cmd::send(new TimeoutMsg($this->id))];
        }
        $next = new self($this->remaining, $this->interval, true, false, $this->id, $this->epoch + 1);
        return [$next, $next->tick()];
    }

    public function stop(): self
    {
        return new self($this->remaining, $this->interval, false, $this->timedOut, $this->id, $this->epoch);
    }

    /** Reset to a new duration (or the current one) and stop. */
    public function reset(?float $duration = null): self
    {
        $d = $duration ?? $this->remaining;
        self::checkDuration($d);
        return new self($d, $this->interval, false, false, $this->id, $this->epoch);
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
    public function timedOut(): bool  { return $this->timedOut; }

    /**
     * Stable per-instance ID — used by {@see TimerTick} / {@see TimeoutMsg}
     * routing. Mirrors upstream Bubbles `ID()`.
     */
    public function id(): int { return $this->id; }

    /**
     * Tick cadence in seconds. Mirrors the upstream `Interval` field
     * which Bubbles exposes for inspection by surrounding code (e.g.
     * progress bars syncing animation frames to the tick interval).
     */
    public function interval(): float { return $this->interval; }

    /**
     * Remaining duration in seconds. Mirrors the upstream `Timeout`
     * field. Returns 0 for a timer that has fired its
     * {@see TimeoutMsg}; returns the original duration before
     * {@see start()} is called.
     */
    public function timeout(): float { return $this->remaining; }

    /**
     * Recreate the timer with a new interval. Stopped/running state and
     * the remaining duration are preserved. Mirrors upstream's mutable
     * `Interval` field assignment (which we render immutable here so
     * snapshot-based tests stay deterministic).
     */
    public function withInterval(float $interval): self
    {
        self::checkInterval($interval);
        return new self($this->remaining, $interval, $this->running, $this->timedOut, $this->id, $this->epoch);
    }

    private function tick(): \Closure
    {
        $id = $this->id;
        $epoch = $this->epoch;
        return Cmd::tick($this->interval, static fn(): TimerTick => new TimerTick($id, $epoch));
    }

    /**
     * Format seconds as "H:MM:SS" / "M:SS" / "0:SS" depending on magnitude.
     * Non-finite input and negative durations render the sane zero state
     * ('0:00'), matching the NaN-clamps-to-empty posture the percent ladder
     * holds — a public static, so it is QA-visible surface (M63; the
     * consistent '-0:05' spelling stays the gated alternative).
     */
    public static function format(float $seconds): string
    {
        $total = (int) round(is_finite($seconds) ? max(0.0, $seconds) : 0.0);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
