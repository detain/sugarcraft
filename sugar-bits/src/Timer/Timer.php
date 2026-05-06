<?php

declare(strict_types=1);

namespace CandyCore\Bits\Timer;

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;

/**
 * Countdown timer. Construct with a duration; call {@see start()} to begin
 * ticking. {@see update()} consumes {@see TickMsg}s and reschedules the
 * next tick until {@see $remaining} hits zero, at which point a single
 * {@see TimeoutMsg} is dispatched and the timer stops itself.
 *
 * Multiple Timer instances coexist on one event loop because each carries
 * a unique id; ticks for other ids are ignored.
 */
final class Timer implements Model
{
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly float $remaining,
        public readonly float $interval,
        public readonly bool $running,
        public readonly bool $timedOut,
        ?int $id = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    public static function new(float $duration, float $interval = 1.0): self
    {
        if ($duration < 0.0) {
            throw new \InvalidArgumentException('timer duration must be >= 0');
        }
        if ($interval <= 0.0) {
            throw new \InvalidArgumentException('timer interval must be > 0');
        }
        return new self($duration, $interval, false, false);
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
        if ($msg instanceof TickMsg && $msg->id === $this->id && $this->running) {
            $newRemaining = max(0.0, $this->remaining - $this->interval);
            if ($newRemaining <= 0.0) {
                $next = new self(0.0, $this->interval, false, true, $this->id);
                return [$next, Cmd::send(new TimeoutMsg($this->id))];
            }
            $next = new self($newRemaining, $this->interval, true, false, $this->id);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return self::format($this->remaining);
    }

    /**
     * Set running=true and schedule the first tick. Idempotent — calling
     * start() while the timer is already running is a no-op so duplicate
     * start events don't spawn parallel tick chains.
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
        $next = new self($this->remaining, $this->interval, true, false, $this->id);
        return [$next, $next->tick()];
    }

    public function stop(): self
    {
        return new self($this->remaining, $this->interval, false, $this->timedOut, $this->id);
    }

    /** Reset to a new duration (or the current one) and stop. */
    public function reset(?float $duration = null): self
    {
        $d = $duration ?? $this->remaining;
        return new self($d, $this->interval, false, false, $this->id);
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
     * Stable per-instance ID — used by {@see TickMsg} / {@see TimeoutMsg}
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
        if ($interval <= 0.0) {
            throw new \InvalidArgumentException('timer interval must be > 0');
        }
        return new self($this->remaining, $interval, $this->running, $this->timedOut, $this->id);
    }

    private function tick(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->interval, static fn(): Msg => new TickMsg($id));
    }

    /** Format seconds as "H:MM:SS" / "M:SS" / "0:SS" depending on magnitude. */
    public static function format(float $seconds): string
    {
        $total = (int) round($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }
}
