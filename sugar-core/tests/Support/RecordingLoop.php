<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * A StreamSelectLoop that counts the periodic timers armed on it, so a test
 * can assert a program keeps no polling timer when nothing needs one.
 */
final class RecordingLoop implements LoopInterface
{
    public int $periodicArms = 0;

    private readonly StreamSelectLoop $inner;

    public function __construct()
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
        $this->periodicArms++;
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
