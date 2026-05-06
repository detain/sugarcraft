<?php

declare(strict_types=1);

namespace CandyCore\Bits\Stopwatch;

use CandyCore\Bits\Timer\Timer;
use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;

/**
 * Count-up stopwatch. Mirror of {@see Timer}: starts at zero, increments
 * elapsed by {@see $interval} every tick. No upper bound. {@see view()}
 * formats elapsed time the same way as Timer.
 */
final class Stopwatch implements Model
{
    private static int $nextId = 0;

    public readonly int $id;

    private function __construct(
        public readonly float $elapsed,
        public readonly float $interval,
        public readonly bool $running,
        ?int $id = null,
    ) {
        $this->id = $id ?? ++self::$nextId;
    }

    public static function new(float $interval = 1.0): self
    {
        if ($interval <= 0.0) {
            throw new \InvalidArgumentException('stopwatch interval must be > 0');
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
        if ($msg instanceof TickMsg && $msg->id === $this->id && $this->running) {
            $next = new self($this->elapsed + $this->interval, $this->interval, true, $this->id);
            return [$next, $next->tick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Timer::format($this->elapsed);
    }

    /**
     * Idempotent — calling start() while the stopwatch is already running
     * is a no-op so duplicate start events don't spawn parallel tick
     * chains and double-count elapsed time.
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function start(): array
    {
        if ($this->running) {
            return [$this, null];
        }
        $next = new self($this->elapsed, $this->interval, true, $this->id);
        return [$next, $next->tick()];
    }

    public function stop(): self
    {
        return new self($this->elapsed, $this->interval, false, $this->id);
    }

    public function reset(): self
    {
        return new self(0.0, $this->interval, false, $this->id);
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
     * Stable per-instance ID — used by {@see TickMsg} to route the
     * matching update events. Mirrors the upstream Bubbles `ID()`.
     */
    public function id(): int { return $this->id; }

    /** Elapsed wall-clock seconds since the last reset / new(). */
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
        if ($interval <= 0.0) {
            throw new \InvalidArgumentException('stopwatch interval must be > 0');
        }
        return new self($this->elapsed, $interval, $this->running, $this->id);
    }

    private function tick(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->interval, static fn(): Msg => new TickMsg($id));
    }
}
