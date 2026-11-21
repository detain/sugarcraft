<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * A StreamSelectLoop that records what a caller arms on it, so a test can
 * assert that a wait is signal-driven (an addSignal() call and no periodic
 * timer) rather than taking it on faith.
 *
 * With $refuseSignals it answers addSignal() the way StreamSelectLoop does on
 * a host without ext-pcntl, which is how the fallback poll is reached on a
 * host that has it.
 */
final class RecordingLoop implements LoopInterface
{
    private readonly StreamSelectLoop $inner;

    /** @var list<float> intervals passed to addPeriodicTimer() */
    public array $periodicTimers = [];

    /** @var list<int> signals passed to addSignal() and accepted */
    public array $signalsAdded = [];

    /** @var list<int> signals passed to removeSignal() */
    public array $signalsRemoved = [];

    public function __construct(private readonly bool $refuseSignals = false)
    {
        $this->inner = new StreamSelectLoop();
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
        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $this->periodicTimers[] = (float) $interval;

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
        if ($this->refuseSignals) {
            throw new \BadMethodCallException('Event loop feature "signals" isn\'t supported by this test loop');
        }
        $this->inner->addSignal($signal, $listener);
        $this->signalsAdded[] = (int) $signal;
    }

    public function removeSignal($signal, $listener): void
    {
        $this->signalsRemoved[] = (int) $signal;
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
