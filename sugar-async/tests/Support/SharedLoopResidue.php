<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests\Support;

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * What is still armed on the SHARED ReactPHP loop — lib-local port of the
 * sugar-pty helper of the same name (pattern copied, dependency avoided).
 *
 * WHY THIS EXISTS: a test that runs the shared Loop::get() and stop()s it
 * early leaves armed timers behind. The cost is not slowness — it is
 * order-dependence: a sibling's drain check truncates against the stale
 * timer (measured in this suite's history as a 0.461 s foreign drain), so a
 * leak pin passes or fails on file order instead of on the code under test.
 * Every loop-driving test either keeps the shared loop clean at the end of
 * each test (the assertSharedLoopClean tearDown contract) or runs on a
 * private StreamSelectLoop, which is the established pattern in this lib.
 *
 * A GUARD MUST GO RED ON WHAT IT CANNOT READ: unknown loop classes and
 * unreadable stores throw rather than answering a comfortable zero — an
 * unreadable loop reported as empty is indistinguishable from a clean one,
 * and the callers here assert an absence.
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
                . 'It must be taught that loop rather than left to answer zero for it.',
            );
        }

        return [
            'timers' => self::countTimers($loop),
            'readStreams' => \count(self::read($loop, 'readStreams')),
            'writeStreams' => \count(self::read($loop, 'writeStreams')),
            'signals' => self::countSignalListeners($loop),
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
        $timers = self::readObject($loop, 'timers');
        $value = (new \ReflectionProperty($timers, 'timers'))->getValue($timers);

        if (\is_array($value)) {
            return \count($value);
        }
        if ($value instanceof \Countable) {
            return \count($value);
        }

        throw new \RuntimeException(
            'the loop\'s timer store holds a ' . \get_debug_type($value) . ', which this census '
            . 'cannot count — and a timer it cannot count is a timer it cannot report',
        );
    }

    /**
     * Listeners armed across all signals. This react version's
     * SignalsHandler keeps array<int, list<callable>> $signals, so this
     * counts LISTENERS: handlers per signal each must be removed, and
     * reporting a half-cleaned loop as clean defeats the census.
     */
    private static function countSignalListeners(StreamSelectLoop $loop): int
    {
        $reflection = new \ReflectionProperty($loop, 'signals');
        $handler = $reflection->getValue($loop);
        if ($handler === null) {
            // No signal has ever been added: the handler is born lazily.
            return 0;
        }
        if (!\is_object($handler)) {
            throw new \RuntimeException('the loop\'s signal store is neither null nor an object');
        }

        return \array_sum(\array_map(
            static fn ($listeners): int => \is_array($listeners) ? \count($listeners) : 1,
            self::read($handler, 'signals'),
        ));
    }

    private static function countFutureTicks(StreamSelectLoop $loop): int
    {
        $queue = self::readObject($loop, 'futureTickQueue');
        if (\method_exists($queue, 'isEmpty')) {
            return $queue->isEmpty() ? 0 : 1;
        }

        throw new \RuntimeException('the loop\'s future-tick queue cannot be inspected');
    }

    /**
     * @return array<array-key, mixed>|list<mixed>
     */
    private static function read(object $subject, string $property): array
    {
        $reflection = new \ReflectionProperty($subject, $property);
        $reflection->setAccessible(true);
        $value = $reflection->getValue($subject);

        if (!\is_array($value)) {
            throw new \RuntimeException("loop store '{$property}' is not an array");
        }

        return $value;
    }

    private static function readObject(object $subject, string $property): object
    {
        $reflection = new \ReflectionProperty($subject, $property);
        $reflection->setAccessible(true);
        $value = $reflection->getValue($subject);

        if (!\is_object($value)) {
            throw new \RuntimeException("loop store '{$property}' is not an object");
        }

        return $value;
    }
}
