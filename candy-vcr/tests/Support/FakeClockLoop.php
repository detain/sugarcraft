<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * A virtual-time React loop for deterministic replay tests (finding 014).
 *
 * Player schedules every cassette event as a loop timer, so the idle-trim
 * clamp is really a property of the SCHEDULED INTERVAL — the old tests
 * only inferred it from wall-clock elapsed time (assertLessThan(5.0,
 * $elapsed)) which both slept through real seconds and stayed green if
 * the clamp degraded to any sub-5s value. Injecting this loop lets a
 * test assert the exact interval armed for each step: a clamped gap
 * shows 0.5 where an unclamped one shows 10.0.
 *
 * Semantics kept deliberately close to StreamSelectLoop: due timers fire
 * in (due, arming) order, futureTicks drain before any timer when both
 * are ready, periodic timers re-arm on their interval, and stop() ends
 * run() at the next check. Stream and signal watchers are registered and
 * removed but NEVER fire — nothing in a replay test should depend on I/O
 * readiness, and if it does, the missing-fire is a loud test failure,
 * which is the point of a fake clock.
 *
 * Every one-shot addTimer() interval is recorded in arming order
 * ({@see oneShotIntervals()}); periodic arms are excluded so the list
 * speaks only about scheduled work. Program never arms one-shot timers
 * itself (its only addTimer seat is the one-shot delay Cmd path, which
 * the echo models in these tests never emit), so what the recorder
 * shows is exactly what Player armed.
 */
final class FakeClockLoop implements LoopInterface
{
    /** Hard ceiling on scheduler firings — a runaway futureTick/periodic chain fails loud. */
    private const MAX_FIRINGS = 1_000_000;

    private float $now = 0.0;
    private bool $stopped = false;
    private int $nextId = 0;

    /** @var array<int, FakeClockTimer> pending one-shot and periodic timers */
    private array $pending = [];

    /** @var list<callable> futureTick queue, drained FIFO */
    private array $ticks = [];

    /** @var list<float> one-shot intervals recorded in arming order */
    private array $armedOneShots = [];

    /** @var array<int, callable> read streams keyed by resource id */
    private array $readStreams = [];

    /** @var array<int, callable> write streams keyed by resource id */
    private array $writeStreams = [];

    public function oneShotIntervals(): array
    {
        return $this->armedOneShots;
    }

    public function virtualNow(): float
    {
        return $this->now;
    }

    /**
     * @param float $interval
     * @param callable $callback
     */
    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new FakeClockTimer((float) $interval, $callback, periodic: false);
        $timer->due = $this->now + $timer->getInterval();
        $this->armedOneShots[] = $timer->getInterval();
        $this->pending[$this->nextId++] = $timer;

        return $timer;
    }

    /**
     * @param float $interval
     * @param callable $callback
     */
    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new FakeClockTimer((float) $interval, $callback, periodic: true);
        $timer->due = $this->now + $timer->getInterval();
        $this->pending[$this->nextId++] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        foreach ($this->pending as $id => $pending) {
            if ($pending === $timer) {
                unset($this->pending[$id]);

                return;
            }
        }
    }

    /**
     * @param callable $listener
     */
    public function futureTick($listener): void
    {
        $this->ticks[] = $listener;
    }

    /**
     * @param resource $stream
     * @param callable $listener
     */
    public function addReadStream($stream, $listener): void
    {
        $this->readStreams[(int) $stream] = $listener;
    }

    /**
     * @param resource $stream
     * @param callable $listener
     */
    public function addWriteStream($stream, $listener): void
    {
        $this->writeStreams[(int) $stream] = $listener;
    }

    /**
     * @param resource $stream
     */
    public function removeReadStream($stream): void
    {
        unset($this->readStreams[(int) $stream]);
    }

    /**
     * @param resource $stream
     */
    public function removeWriteStream($stream): void
    {
        unset($this->writeStreams[(int) $stream]);
    }

    /**
     * @param int $signal
     * @param callable $listener
     */
    public function addSignal($signal, $listener): void
    {
        // Registered nowhere: a fake clock has no signals to deliver.
    }

    /**
     * @param int $signal
     * @param callable $listener
     */
    public function removeSignal($signal, $listener): void
    {
        // Nothing was registered, so there is nothing to remove.
    }

    public function run(): void
    {
        $this->stopped = false;
        $firings = 0;

        while (!$this->isStopped()) {
            if (++$firings > self::MAX_FIRINGS) {
                throw new \RuntimeException(sprintf(
                    'FakeClockLoop scheduler exceeded %d firings without quiescing - runaway tick/periodic chain?',
                    self::MAX_FIRINGS,
                ));
            }

            if ($this->ticks !== []) {
                $listener = array_shift($this->ticks);
                $listener();
                continue;
            }

            $next = $this->earliestPending();
            if ($next === null) {
                return;
            }

            [$id, $timer] = $next;
            $this->now = max($this->now, $timer->due);
            if ($timer->isPeriodic()) {
                $timer->due = $this->now + $timer->getInterval();
            } else {
                unset($this->pending[$id]);
            }
            ($timer->getCallback())($timer);
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Indirection is load-bearing: timer callbacks set the flag behind
     * the scheduler's back, so run() must re-read it through a call,
     * not a narrowed local property type.
     */
    private function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * @return array{int, FakeClockTimer}|null the earliest-due pending timer (arming order breaks ties)
     */
    private function earliestPending(): ?array
    {
        $bestId = null;
        $best = null;
        foreach ($this->pending as $id => $timer) {
            if ($best === null || $timer->due < $best->due) {
                $bestId = $id;
                $best = $timer;
            }
        }

        return $best === null ? null : [$bestId, $best];
    }
}

/**
 * Timer handle for {@see FakeClockLoop}. Not exported — lives beside its loop.
 *
 * @internal
 */
final class FakeClockTimer implements TimerInterface
{
    public float $due = 0.0;

    public function __construct(
        private readonly float $interval,
        private readonly mixed $callback,
        private readonly bool $periodic,
    ) {
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function getCallback(): mixed
    {
        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}
