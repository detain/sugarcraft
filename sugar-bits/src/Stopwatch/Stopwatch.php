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
     * @return array{0:self, 1:\Closure}
     */
    public function start(): array
    {
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

    public function isRunning(): bool { return $this->running; }

    private function tick(): \Closure
    {
        $id = $this->id;
        return Cmd::tick($this->interval, static fn(): Msg => new TickMsg($id));
    }
}
