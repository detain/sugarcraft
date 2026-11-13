<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Support;

use React\EventLoop\TimerInterface;

/**
 * Timer token handed out by {@see FakeTickLoop}. `cancelled` is public on
 * purpose: the fake's tests read it directly to assert cancel-on-settle —
 * the very residue that the shared-loop flake class leaves behind.
 */
final class FakeTimer implements TimerInterface
{
    public bool $cancelled = false;

    /**
     * @param callable(TimerInterface):void $callback
     */
    public function __construct(
        private readonly float $interval,
        private $callback,
    ) {}

    public function getInterval()
    {
        return $this->interval;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function isPeriodic()
    {
        return false;
    }
}
