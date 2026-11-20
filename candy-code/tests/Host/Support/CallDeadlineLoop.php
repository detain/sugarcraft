<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Support;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Host\InProcessHostServices;

/**
 * The shared loop, except that the door's per-call deadline ({@see InProcessHostServices::CALL_TIMEOUT_SECONDS})
 * is recorded instead of armed. A test fires the deadlines itself with {@see fireDeadlines()}, so the
 * 20-second timeout path runs without waiting 20 seconds. Every other timer, and every stream, is `Loop::get()`.
 */
final class CallDeadlineLoop implements LoopInterface
{
    /** @var array<int, callable> spl_object_id of a deadline still armed => its callback */
    private array $armed = [];

    /** @var array<int, TimerInterface> every deadline handed out, kept so its id is never reused */
    private array $issued = [];

    /** Fires every deadline still armed, as if its 20 seconds had passed; returns how many fired. */
    public function fireDeadlines(): int
    {
        $due = $this->armed;
        $this->armed = [];
        foreach ($due as $callback) {
            $callback();
        }

        return \count($due);
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
        if ((float) $interval !== InProcessHostServices::CALL_TIMEOUT_SECONDS) {
            return Loop::get()->addTimer($interval, $callback);
        }
        $timer = new Timer((float) $interval, $callback, false);
        $id = \spl_object_id($timer);
        $this->issued[$id] = $timer;
        $this->armed[$id] = $callback;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return Loop::get()->addPeriodicTimer($interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $id = \spl_object_id($timer);
        if (isset($this->issued[$id])) {
            unset($this->armed[$id]);

            return;
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
