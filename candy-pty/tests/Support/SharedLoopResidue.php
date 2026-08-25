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
 */
final class SharedLoopResidue
{
    /**
     * @return array{timers:int, readStreams:int, writeStreams:int}
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
        ];
    }

    /** A one-line description for a failure message. */
    public static function describe(): string
    {
        $census = self::census();

        return \sprintf(
            '%d timer(s), %d read stream(s), %d write stream(s)',
            $census['timers'],
            $census['readStreams'],
            $census['writeStreams'],
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

    /** @return mixed */
    private static function read(StreamSelectLoop $loop, string $name)
    {
        $property = new \ReflectionProperty($loop, $name);
        $property->setAccessible(true);

        return $property->getValue($loop);
    }
}
