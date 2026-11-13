<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Deterministic stand-in for a React event loop: every armed one-shot timer
 * is captured, never scheduled against wall-clock. Tests `tickDue()` to fire
 * what is due — exactly as the real loop would at a deadline — with zero
 * microseconds of sleeping (SQL-P12/adminpages-014: the deadline legs must
 * not depend on a host clock, ext-uv or a loaded machine).
 *
 * Parameter shapes mirror LoopInterface's untyped signature; only returns
 * are narrowed. Streams/periodics/signals are inert — the timeout legs
 * touched here arm one-shot timers only.
 */
final class FakeTickLoop implements LoopInterface
{
    /** @var list<FakeTimer> */
    private array $timers = [];

    public function addTimer($interval, $callback): FakeTimer
    {
        $timer = new FakeTimer((float) $interval, $callback);
        $this->timers[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        // No production consumer of the deadline seams periodic — inert.
        return new FakeTimer((float) $interval, $callback);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        if ($timer instanceof FakeTimer) {
            $timer->cancelled = true;
        }
    }

    public function futureTick($listener): void
    {
    }

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    /**
     * Fire every armed, un-cancelled timer once (one-shot semantics), in
     * ascending interval order like a deadline sweep.
     *
     * @return int How many callbacks ran
     */
    public function tickDue(): int
    {
        $due = $this->timers;
        $this->timers = [];
        usort($due, static fn (FakeTimer $a, FakeTimer $b): int => $a->getInterval() <=> $b->getInterval());

        $fired = 0;
        foreach ($due as $timer) {
            if ($timer->cancelled) {
                continue;
            }
            $timer->cancelled = true;
            ($timer->getCallback())($timer);
            $fired++;
        }

        return $fired;
    }

    /** Timers still armed (uncancelled) — the residue guard's measure. */
    public function liveTimerCount(): int
    {
        $live = 0;
        foreach ($this->timers as $timer) {
            if (!$timer->cancelled) {
                $live++;
            }
        }

        return $live;
    }
}
