<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * A `LoopInterface` whose STREAMS are real and whose CLOCK is scaled.
 *
 * `stream_select()` runs for real against the real socket pair
 * {@see EngineBackend::completeAsync()} creates, so frames arrive from a real
 * forked child in real order. Only `addTimer()`/`addPeriodicTimer()` are
 * re-based: a timer's deadline is compared against
 * `real elapsed x VIRTUAL_SECONDS_PER_REAL_SECOND`, so the 120-second idle
 * ceiling the code arms unchanged is crossed in 240 ms of wall time.
 *
 * Two properties this deliberately keeps:
 *
 *   - `run()` has its own REAL ceiling, so a regression that stops the promise
 *     settling fails the test rather than hanging the suite;
 *   - `addSignal()` THROWS rather than no-opping. A silently dropped signal
 *     handler would be a behaviour this loop quietly removed from the code
 *     under test; nothing in `completeAsync()` registers one today, and if that
 *     changes the test must go red rather than lie.

 *
 * ## Where this class came from
 *
 * E497 — lifted out of `tests/Backend/ReasoningProgressTest.php`, where it sat
 * at top level in the SHARED `SugarCraft\Crush\Tests\Backend` namespace under
 * a name generic enough that the next lane to write one would collide with it
 * — a fatal at autoload time, in a file neither lane had touched. Nothing
 * collided; the hazard was that nothing had YET.
 *
 * A namespace of its own, rather than a longer name: renaming would have to be
 * done again by the next person who wants the obvious name, whereas a namespace
 * makes `ScaledClockLoop` and someone else's `ScaledClockLoop` different classes by
 * construction.
 */
final class ScaledClockLoop implements LoopInterface
{
    /**
     * Virtual seconds per real second. 500 puts a 120-second ceiling 240 ms of
     * wall time away, which is far enough from a 20 ms inter-chunk gap
     * (~10 virtual seconds) that a slow host cannot turn a surviving turn into
     * a killed one, and near enough that a `usleep()`-bounded silence is a
     * guaranteed crossing.
     */
    public const VIRTUAL_SECONDS_PER_REAL_SECOND = 500.0;

    /** Wall-clock backstop so a never-settling promise fails instead of hanging. */
    private const REAL_CEILING_SECONDS = 25.0;

    private const SELECT_MICROS = 200;

    private float $startNs;

    private \SplObjectStorage $timers;

    /** @var array<int, array{0: resource, 1: callable}> */
    private array $readStreams = [];

    /** @var array<int, array{0: resource, 1: callable}> */
    private array $writeStreams = [];

    /** @var list<callable> */
    private array $ticks = [];

    private bool $stopped = false;

    private bool $realCeiling = false;

    private float $highWater = 0.0;

    public function __construct()
    {
        $this->startNs = (float) hrtime(true);
        $this->timers = new \SplObjectStorage();
    }

    /** Virtual seconds since this loop was constructed. */
    public function now(): float
    {
        $seconds = ((float) hrtime(true) - $this->startNs) / 1e9 * self::VIRTUAL_SECONDS_PER_REAL_SECOND;
        if ($seconds > $this->highWater) {
            $this->highWater = $seconds;
        }

        return $seconds;
    }

    public function highWaterVirtualSeconds(): float
    {
        return $this->highWater;
    }

    public function hitRealCeiling(): bool
    {
        return $this->realCeiling;
    }

    public function addReadStream($stream, $listener): void
    {
        $this->readStreams[(int) $stream] = [$stream, $listener];
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->writeStreams[(int) $stream] = [$stream, $listener];
    }

    public function removeReadStream($stream): void
    {
        unset($this->readStreams[(int) $stream]);
    }

    public function removeWriteStream($stream): void
    {
        unset($this->writeStreams[(int) $stream]);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->timers->attach($timer, $this->now() + (float) $interval);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->timers->attach($timer, $this->now() + (float) $interval);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers->detach($timer);
    }

    public function futureTick($listener): void
    {
        $this->ticks[] = $listener;
    }

    public function addSignal($signal, $listener): void
    {
        throw new \LogicException(
            'ScaledClockLoop has no signal support and will not pretend to: the code under test registered '
            . 'signal ' . $signal . ', which this loop would silently drop.',
        );
    }

    public function removeSignal($signal, $listener): void
    {
        throw new \LogicException('ScaledClockLoop has no signal support; see addSignal().');
    }

    public function run(): void
    {
        $this->stopped = false;
        $realDeadline = microtime(true) + self::REAL_CEILING_SECONDS;

        while (!$this->stopped) {
            foreach ($this->drainTicks() as $tick) {
                $tick();
                if ($this->stopped) {
                    return;
                }
            }

            $this->fireDueTimers();
            if ($this->stopped) {
                return;
            }

            if (microtime(true) >= $realDeadline) {
                $this->realCeiling = true;

                return;
            }

            $hasStreams = $this->readStreams !== [] || $this->writeStreams !== [];
            if (!$hasStreams && $this->timers->count() === 0 && $this->ticks === []) {
                return;
            }

            if ($hasStreams) {
                $this->pollStreams();
            } else {
                usleep(self::SELECT_MICROS);
            }

            $this->now();
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return list<callable> */
    private function drainTicks(): array
    {
        $ticks = $this->ticks;
        $this->ticks = [];

        return $ticks;
    }

    private function pollStreams(): void
    {
        $read = array_column($this->readStreams, 0);
        $write = array_column($this->writeStreams, 0);
        $except = [];

        if (@stream_select($read, $write, $except, 0, self::SELECT_MICROS) < 1) {
            return;
        }

        foreach ($read as $stream) {
            $key = (int) $stream;
            if (isset($this->readStreams[$key])) {
                ($this->readStreams[$key][1])($stream, $this);
            }
            if ($this->stopped) {
                return;
            }
        }
        foreach ($write as $stream) {
            $key = (int) $stream;
            if (isset($this->writeStreams[$key])) {
                ($this->writeStreams[$key][1])($stream, $this);
            }
            if ($this->stopped) {
                return;
            }
        }
    }

    private function fireDueTimers(): void
    {
        $now = $this->now();

        foreach (iterator_to_array($this->timers, false) as $timer) {
            if (!$this->timers->contains($timer)) {
                continue;
            }
            if ((float) $this->timers[$timer] > $now) {
                continue;
            }
            if ($timer->isPeriodic()) {
                $this->timers[$timer] = $now + $timer->getInterval();
            } else {
                $this->timers->detach($timer);
            }

            ($timer->getCallback())($timer);

            if ($this->stopped) {
                return;
            }
        }
    }
}
