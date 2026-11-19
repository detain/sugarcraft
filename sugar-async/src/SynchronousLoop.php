<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * A blocking {@see LoopInterface} for callers that run on a plain synchronous
 * thread and still want {@see Poll::until()} as their wait primitive.
 *
 * Nothing fires on its own. {@see addTimer()} only records the timer; the
 * owner drives it with {@see run()}, which sleeps out the armed interval on
 * the calling thread through the injected sleeper and then invokes the
 * callback. Each pause is therefore the blocking cadence a hand-written poll
 * loop would have slept, and a test injects a sleeper that records instead of
 * pausing. The callback runs after the sleep, never at arm time, so a poll
 * chain unwinds iteratively instead of nesting one stack frame per attempt.
 *
 * Only the surface {@see Poll::until()} uses is implemented, and it arms at
 * most one timer at a time, because a poll chain never holds two. Every other
 * method refuses with a {@see \LogicException} naming it: a silent no-op on
 * streams or ticks would hang or misfire the caller instead of failing loudly.
 */
final class SynchronousLoop implements LoopInterface
{
    /** @var callable(float): void */
    private $sleeper;

    private ?TimerInterface $pending = null;
    private bool $stopped = false;

    /**
     * @param (callable(float): void)|null $sleeper  Receives each interval in seconds; null pauses with usleep()
     */
    public static function new(?callable $sleeper = null): self
    {
        return new self($sleeper);
    }

    /** @param (callable(float): void)|null $sleeper */
    private function __construct(?callable $sleeper)
    {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep(max(1, (int) round($seconds * 1_000_000)));
        };
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        if ($this->pending !== null) {
            throw new \LogicException('SynchronousLoop holds one pending timer at a time; cancel or run it before arming another');
        }

        $timer = new Timer((float) $interval, $callback, false);
        $this->pending = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        if ($this->pending === $timer) {
            $this->pending = null;
        }
    }

    /**
     * Drains the pending timer: sleeps its interval, then fires it. A callback
     * that arms the next timer keeps the drain going until nothing is pending
     * or {@see stop()} is called.
     */
    public function run(): void
    {
        while ($this->pending !== null && !$this->stopped) {
            $timer = $this->pending;
            $this->pending = null;
            ($this->sleeper)((float) $timer->getInterval());
            ($timer->getCallback())();
        }

        $this->stopped = false;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function addReadStream($stream, $listener)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function addWriteStream($stream, $listener)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function removeReadStream($stream)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function removeWriteStream($stream)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function futureTick($listener)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function addSignal($signal, $listener)
    {
        throw self::unsupported(__FUNCTION__);
    }

    public function removeSignal($signal, $listener)
    {
        throw self::unsupported(__FUNCTION__);
    }

    private static function unsupported(string $method): \LogicException
    {
        return new \LogicException('SynchronousLoop does not support ' . $method . '(); it only carries the timers Poll::until() arms');
    }
}
