<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Support;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\EventLoop\Timer\Timer;

/**
 * The shared loop with two fault seams, for the S-O04 pins: a periodic timer
 * can be RECORDED instead of armed (so a test calls a 15 s tick's callback
 * itself, on its own schedule), and `addTimer()` can be made to throw (so a
 * code path that sets a timer up synchronously faults on demand). Everything
 * else is `Loop::get()`.
 */
final class FaultableLoop implements LoopInterface
{
    /** @var list<array{0: float, 1: callable}> every periodic timer recorded while {@see $recordPeriodic} was set */
    public array $periodic = [];

    public bool $recordPeriodic = false;

    public ?\Throwable $addTimerFault = null;

    public function periodicCallback(float $interval): callable
    {
        foreach ($this->periodic as [$at, $callback]) {
            if ($at === $interval) {
                return $callback;
            }
        }

        throw new \LogicException('no periodic timer was recorded at ' . $interval . ' s');
    }

    public function addReadStream($stream, $listener): void
    {
        Loop::get()->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        Loop::get()->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        Loop::get()->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        Loop::get()->removeWriteStream($stream);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        if ($this->addTimerFault !== null) {
            throw $this->addTimerFault;
        }

        return Loop::get()->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        if ($this->recordPeriodic) {
            $this->periodic[] = [(float) $interval, $callback];

            return new Timer($interval, $callback, true);
        }

        return Loop::get()->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        foreach ($this->periodic as $at => [, $callback]) {
            if ($timer->getCallback() === $callback) {
                unset($this->periodic[$at]);

                return;
            }
        }
        Loop::get()->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        Loop::get()->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        Loop::get()->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        Loop::get()->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        Loop::get()->run();
    }

    public function stop(): void
    {
        Loop::get()->stop();
    }
}
