<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Support;

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * What is still armed on the SHARED ReactPHP loop (sugar-forms-036).
 *
 * Ported from the canonical `sugar-pty/tests/Support/SharedLoopResidue.php`
 * minus the pump specifics (this suite drives no PosixPump/MultiPump loops —
 * see tests/bootstrap.php's header note). The WHY travels with the port: an
 * orphan timer armed on the shared loop makes the NEXT `Loop::run()` in the
 * suite return because that orphan called `Loop::stop()`, not because its own
 * work finished — measured in sugar-pty as a 0.002s test becoming 4.797s in
 * shard order. Leaked signals/streams can hold `stream_select()` with no
 * timeout at all.
 *
 * A GUARD MUST GO RED ON WHAT IT CANNOT READ: this throws rather than
 * answering a comfortable zero for an unknown loop class or an unreadable
 * store — an unreadable loop reported as empty is indistinguishable from a
 * clean one, and callers here assert an ABSENCE.
 */
final class SharedLoopResidue
{
    private function __construct()
    {
    }

    /**
     * @return array{timers:int, readStreams:int, writeStreams:int, signals:int, futureTicks:int}
     */
    public static function census(): array
    {
        $loop = Loop::get();
        if (!$loop instanceof StreamSelectLoop) {
            throw new \RuntimeException(
                'the shared loop is a ' . $loop::class . ', which this census cannot inspect. '
                . 'It must be taught that loop rather than left to answer zero for it.',
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
     * Listener count, not distinct signal numbers: two handlers on one signal
     * are two things that must be removed, and reporting them as one would
     * make a half-cleaned loop look clean.
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
                . 'census cannot count',
            );
        }

        $listeners = 0;
        foreach ($value as $forOneSignal) {
            $listeners += \is_array($forOneSignal) ? \count($forOneSignal) : 1;
        }

        return $listeners;
    }

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
