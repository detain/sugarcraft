<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * A loop decorator for pinning the delays a production path asks for. Every
 * addTimer() interval is recorded in {@see $requested} as it was asked for,
 * then the timer is armed at zero on the wrapped loop, so a whole backoff
 * budget runs in a few ticks while the schedule itself stays observable.
 * Everything else is the wrapped loop.
 */
final class RecordedTimerLoop implements LoopInterface
{
    /** @var list<float> every timer interval requested, in request order */
    public array $requested = [];

    public function __construct(private readonly LoopInterface $inner)
    {
    }

    public function addReadStream($stream, $listener): void
    {
        $this->inner->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->inner->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $this->requested[] = (float) $interval;

        return $this->inner->addTimer(0.0, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->inner->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        $this->inner->run();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }
}
