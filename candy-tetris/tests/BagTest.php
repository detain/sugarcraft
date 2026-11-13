<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class BagTest extends TestCase
{
    private static function deterministicBag(): Bag
    {
        // Deterministic RNG: always pick the smallest index to make the test
        // independent of random_int's runtime sequence.
        return new Bag(static fn(int $max): int => 0);
    }

    /**
     * Drives the value-semantic bag: every next() hands back the drawn
     * piece AND the advanced queue; the caller threads it forward.
     *
     * @return list<string>
     */
    private static function drawAll(Bag $bag, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            [$kind, $bag] = $bag->next();
            $out[] = $kind->value;
        }

        return $out;
    }

    public function testFirstSevenPiecesContainEachKindExactlyOnce(): void
    {
        $seen = self::drawAll(self::deterministicBag(), 7);
        $sorted = $seen;
        sort($sorted);

        $this->assertSame(['I', 'J', 'L', 'O', 'S', 'T', 'Z'], $sorted);
    }

    /**
     * candy-tetris-067: the drought guarantee the class docblock asserts
     * ("any 13 consecutive pieces contain a whole bag"; a single kind at
     * most three times in such a window) was prose, not law. Pin it over
     * 100 bags (700 pieces) per seed — deterministic streams, so a red
     * here is reproducible to the salt.
     */
    public function testAHundredBagRunNeverStarvesAKindAndNeverRepeatsItTooDensely(): void
    {
        $kinds = array_map(static fn(Tetromino $k): string => $k->value, Tetromino::cases());

        foreach ([1, 2, 3, 5, 8, 13, 21, 34] as $salt) {
            $stream = self::drawAll(Bag::seeded($salt), 700);
            self::assertCount(700, $stream, "seed $salt: 100 bags is 700 pieces");

            foreach ($kinds as $kind) {
                $lastSeen = null;
                foreach ($stream as $index => $drawn) {
                    if ($drawn !== $kind) {
                        continue;
                    }
                    if ($lastSeen !== null) {
                        self::assertLessThanOrEqual(
                            13,
                            $index - $lastSeen,
                            "seed $salt: kind $kind went drought for " . ($index - $lastSeen) . ' draws',
                        );
                    }
                    $lastSeen = $index;
                }
            }

            foreach (array_chunk($stream, 13) as $windowIndex => $window) {
                if (count($window) < 13) {
                    continue;
                }
                self::assertEqualsSorted($kinds, array_values(array_unique($window)), "seed $salt window $windowIndex");
                $counts = array_count_values($window);
                foreach ($counts as $kind => $count) {
                    self::assertLessThanOrEqual(3, $count, "seed $salt window $windowIndex: $kind x $count");
                }
            }
        }
    }

    private static function assertEqualsSorted(array $expected, array $actual, string $message): void
    {
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $message);
    }

    public function testTwoBagsContainEachKindTwice(): void
    {
        $seen = self::drawAll(self::deterministicBag(), 14);
        $counts = array_count_values($seen);
        foreach (Tetromino::cases() as $t) {
            $this->assertSame(2, $counts[$t->value], "expected exactly two of {$t->value}");
        }
    }

    public function testPeekDoesNotConsume(): void
    {
        $bag = self::deterministicBag();
        $peeked = $bag->peek(3);
        $this->assertCount(3, $peeked);

        [$first, $bag] = $bag->next();
        [$second, $bag] = $bag->next();
        [$third, ] = $bag->next();

        $this->assertSame($peeked, [$first, $second, $third]);
    }

    public function testPeekRefillsAcrossBagBoundary(): void
    {
        $bag = self::deterministicBag();
        // Drain one bag.
        for ($i = 0; $i < 7; $i++) {
            [, $bag] = $bag->next();
        }
        $next3 = $bag->peek(3);
        $this->assertCount(3, $next3);
        foreach ($next3 as $t) {
            $this->assertInstanceOf(Tetromino::class, $t);
        }
    }

    public function testPeekingNeverDisturbsTheDraw(): void
    {
        $plainDraws = 0;
        $plainBag = new Bag(static function (int $max) use (&$plainDraws): int {
            $plainDraws++;

            return $max > 0 ? $max - 1 : 0;
        });

        $peekedDraws = 0;
        $peekBag = new Bag(static function (int $max) use (&$peekedDraws): int {
            $peekedDraws++;

            return $max > 0 ? $max - 1 : 0;
        });

        $plain = self::drawAll($plainBag, 12);

        // The very same stream, but with previews wedged between every
        // draw. A peek that merged shuffles into its own queue (the pre-fix
        // shape) consumed RNG here and re-ordered the stream — both drifts
        // land red below.
        $withPeeks = [];
        for ($i = 0; $i < 12; $i++) {
            $peekBag->peek(1);
            $peekBag->peek(3);
            $peekBag->peek(7);
            [$kind, $peekBag] = $peekBag->next();
            $withPeeks[] = $kind->value;
        }

        $this->assertSame($plain, $withPeeks, 'previews must not reorder the 7-bag stream');
        $this->assertSame($plainDraws, $peekedDraws, 'previews must not consume RNG draws');
        $this->assertGreaterThan(0, $plainDraws, 'both bags must actually shuffle');
    }

    public function testAnOldSnapshotKeepsItsOwnFuture(): void
    {
        $live = self::deterministicBag();
        $snapshot = $live;
        $promised = $snapshot->peek(3);

        for ($i = 0; $i < 5; $i++) {
            [, $live] = $live->next();
        }

        $this->assertSame(
            $promised,
            $snapshot->peek(3),
            'a bag snapshot cannot be drained by someone else' . "'s advancement",
        );

        [$first, $again] = $snapshot->next();
        [$second, ] = $again->next();

        $this->assertSame($promised[0], $first, 'the snapshot replays its own promised future');
        $this->assertSame($promised[1], $second);
    }
}
