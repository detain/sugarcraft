<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * What is still armed on the SHARED ReactPHP loop.
 *
 * WHY THIS EXISTS, and it is a measurement rather than a worry. Almost every
 * loop-driving test in this suite builds its own `new StreamSelectLoop()` --
 * deliberately, for isolation, as `tests/bootstrap.php` says. `PtyPoolReact-
 * LoopTest` is the one file that drives the shared `Loop::` facade, and it was
 * leaving a 5-second safety-cap timer armed on it. The cost was not slowness:
 * the NEXT `Loop::run()` in the suite returned because that orphan called
 * `Loop::stop()`, not because its own work had finished. Measured on PHP 8.3.6,
 * `testDrainInsideLoopAfterMixedAcquireRelease` takes 0.002s run alone and
 * 4.797s run after its two neighbours -- the 5.0s cap less the ~0.2s the
 * neighbour had already spent.
 *
 * THE E490 CONNECTION, stated as a candidate and not as a conclusion. A leaked
 * one-shot cap is self-limiting: it fires once and is gone. A leaked PERIODIC
 * is not, and neither is the state after the cap has been consumed -- a later
 * `Loop::run()` waiting on a stream that never becomes readable, with no timer
 * left in the queue to end it, blocks in `stream_select()` with no timeout at
 * all. That is `wchan: do_select`, which is what the one observed E490 hang was
 * sitting in. It is consistent; it is not proof, because the hang has not been
 * reproduced.
 *
 * A GUARD MUST GO RED ON WHAT IT CANNOT READ, so this throws rather than
 * returning a comfortable zero when the loop is not the class it knows how to
 * inspect. A residue report that silently answers "nothing armed" for an
 * unrecognised loop is indistinguishable from a clean one, and it is the
 * assertion of an ABSENCE that would rest on it.
 *
 * WHAT THIS COVERED BEFORE, and why that was not enough. It counted timers,
 * read streams and write streams -- three of the FOUR things that keep
 * `StreamSelectLoop::run()` from returning. The rule above was applied to an
 * unknown loop CLASS and not to a known loop's unread properties, which is the
 * same hole one level down: `run()` also continues while `signals` is
 * non-empty, and while `futureTickQueue` is non-empty. Measured on PHP 8.3.6:
 * with a signal added to the shared loop this census answered
 * `{timers:0, readStreams:0, writeStreams:0}` -- a comfortable zero, of exactly
 * the kind the paragraph above refuses to give.
 *
 * AND THE SIGNAL BRANCH IS THE ONE THAT MATTERS MOST, which is what makes this
 * more than completeness. Read `run()`: of the conditions that keep it alive,
 * the `readStreams || writeStreams || !signals->isEmpty()` branch is the one
 * that sets `$timeout = null` -- `stream_select()` with no timeout at all. That
 * is the `wchan: do_select` state named above as the E490 candidate. A leaked
 * signal handler on the shared loop reaches it with no stream involved.
 * `futureTickQueue` sets `$timeout = 0` instead, so it spins rather than
 * blocking; it is residue that holds the loop either way and is counted too.
 */
final class SharedLoopResidue
{
    /**
     * @return array{timers:int, readStreams:int, writeStreams:int, signals:int, futureTicks:int}
     */
    public static function census(): array
    {
        $loop = Loop::get();
        if (!$loop instanceof StreamSelectLoop) {
            throw new \RuntimeException(
                'the shared loop is a ' . $loop::class . ', which this census cannot inspect. '
                . 'It must be taught that loop rather than left to answer zero for it: an '
                . 'unreadable loop reported as empty is exactly a clean one, and the callers '
                . 'here assert an absence.',
            );
        }

        return [
            'timers' => self::countTimers($loop),
            'readStreams' => \count(self::read($loop, 'readStreams')),
            'writeStreams' => \count(self::read($loop, 'writeStreams')),
            'signals' => self::countSignals($loop),
            'futureTicks' => self::countFutureTicks($loop),
        ];
    }

    /** A one-line description for a failure message. */
    public static function describe(): string
    {
        $census = self::census();

        return \sprintf(
            '%d timer(s), %d read stream(s), %d write stream(s), %d signal(s), %d future tick(s)',
            $census['timers'],
            $census['readStreams'],
            $census['writeStreams'],
            $census['signals'],
            $census['futureTicks'],
        );
    }

    private static function countTimers(StreamSelectLoop $loop): int
    {
        $timers = self::read($loop, 'timers');
        if (!\is_object($timers)) {
            throw new \RuntimeException('the loop\'s timer store is not an object');
        }

        $property = new \ReflectionProperty($timers, 'timers');
        $property->setAccessible(true);
        $value = $property->getValue($timers);

        if (\is_array($value)) {
            return \count($value);
        }
        if ($value instanceof \Countable) {
            return \count($value);
        }

        throw new \RuntimeException(
            'the loop\'s timer store holds a ' . \get_debug_type($value) . ', which this census '
            . 'cannot count - and a timer it cannot count is a timer it cannot report',
        );
    }

    /**
     * Listeners armed across all signals.
     *
     * `SignalsHandler` keeps `array<int, list<callable>>`, so this counts
     * LISTENERS rather than distinct signal numbers: two handlers on one signal
     * are two things that must be removed, and reporting them as one would make
     * a half-cleaned loop look clean.
     */
    private static function countSignals(StreamSelectLoop $loop): int
    {
        $signals = self::read($loop, 'signals');
        if (!\is_object($signals)) {
            throw new \RuntimeException('the loop\'s signal store is not an object');
        }

        $property = new \ReflectionProperty($signals, 'signals');
        $property->setAccessible(true);
        $value = $property->getValue($signals);

        if (!\is_array($value)) {
            throw new \RuntimeException(
                'the loop\'s signal store holds a ' . \get_debug_type($value) . ', which this '
                . 'census cannot count - and a signal it cannot count is a signal it cannot '
                . 'report',
            );
        }

        $listeners = 0;
        foreach ($value as $forOneSignal) {
            $listeners += \is_array($forOneSignal) ? \count($forOneSignal) : 1;
        }

        return $listeners;
    }

    /** Callbacks queued on the future-tick queue. */
    private static function countFutureTicks(StreamSelectLoop $loop): int
    {
        $queue = self::read($loop, 'futureTickQueue');
        if (!\is_object($queue)) {
            throw new \RuntimeException('the loop\'s future-tick queue is not an object');
        }

        $property = new \ReflectionProperty($queue, 'queue');
        $property->setAccessible(true);
        $value = $property->getValue($queue);

        if (\is_array($value) || $value instanceof \Countable) {
            return \count($value);
        }

        throw new \RuntimeException(
            'the loop\'s future-tick queue holds a ' . \get_debug_type($value) . ', which this '
            . 'census cannot count',
        );
    }

    /** @return mixed */
    private static function read(StreamSelectLoop $loop, string $name)
    {
        $property = new \ReflectionProperty($loop, $name);
        $property->setAccessible(true);

        return $property->getValue($loop);
    }
}
