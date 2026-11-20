<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * A real `StreamSelectLoop` that counts how many times a read listener is
 * dispatched, so a test can prove a socket left the loop rather than being
 * woken on it every turn.
 */
final class ReadStreamCountingLoop implements LoopInterface
{
    /** How many read-listener dispatches the loop has made, across every stream. */
    public int $readDispatches = 0;

    private readonly StreamSelectLoop $inner;

    public function __construct()
    {
        $this->inner = new StreamSelectLoop();
    }

    public function addReadStream($stream, $listener): void
    {
        $this->inner->addReadStream($stream, function ($stream) use ($listener): void {
            $this->readDispatches++;
            $listener($stream);
        });
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
