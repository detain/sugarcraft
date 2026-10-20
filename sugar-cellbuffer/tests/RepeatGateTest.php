<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Diff\RepeatGate;
use SugarCraft\CellBuffer\Diff\RepeatRunOp;
use SugarCraft\CellBuffer\Diff\SetCellOp;

/**
 * A-02: the REP byte-cost gate. DiffEncoder (and sugar-image's
 * OutputOptimizer) consult this single home so a run is encoded the same
 * way on the diff path and the mosaic path.
 *
 * Break-even derivation pinned here rather than trusted from the docblock:
 * REP costs 3 + digits(count) bytes; the literal arm costs count × runeBytes.
 * For ASCII the first strict win is count 5, so every run below five
 * repeats (four total cells plus the tie at five) stays literal.
 */
final class RepeatGateTest extends TestCase
{
    /**
     * Every sub-threshold ASCII count must report false — this is the
     * "runs below four lose" pin expressed at the honest boundary (the
     * gate sees the repeat count, i.e. run length minus one).
     */
    public function testLosingAsciiCountsAreRefused(): void
    {
        foreach ([1, 2, 3, 4] as $count) {
            self::assertFalse(
                RepeatGate::worth($count),
                "count {$count} of a 1-byte rune must stay literal",
            );
        }
    }

    /** Count 5 is the first strict win: "\x1b[5b" (4 bytes) beats "AAAAA" (5). */
    public function testFirstStrictAsciiWin(): void
    {
        self::assertTrue(RepeatGate::worth(5));
    }

    /** Count 10 crosses into two digits (cost 5) and still wins (10 literal bytes). */
    public function testTwoDigitCountsStillWin(): void
    {
        self::assertTrue(RepeatGate::worth(10));
        self::assertTrue(RepeatGate::worth(39));
    }

    /**
     * The tie at count 4 (4 vs 4 bytes) answers FALSE: equal size with
     * fewer capability assumptions takes the literal arm.
     */
    public function testTiePrefersLiteral(): void
    {
        self::assertSame(4, RepeatGate::repByteCost(4));
        self::assertFalse(RepeatGate::worth(4));
    }

    /**
     * Multi-byte runes flip the gate early: a 3-byte block glyph wins at
     * count 2 (6 literal bytes vs 4) — the chafa charBytes asymmetry.
     */
    public function testWideRunesWinEarly(): void
    {
        self::assertFalse(RepeatGate::worth(1, 3), 'one 3-byte literal still beats the frame');
        self::assertTrue(RepeatGate::worth(2, 3));
    }

    /** Nothing to repeat, and nonsense rune widths, refuse — fail closed. */
    public function testDegenerateCountsRefused(): void
    {
        self::assertFalse(RepeatGate::worth(0));
        self::assertFalse(RepeatGate::worth(-3));
        self::assertFalse(RepeatGate::worth(50, 0));
    }

    /** repByteCost is the same arithmetic the worth() ladder consumes. */
    public function testRepByteCostIsTheDecimalFraming(): void
    {
        self::assertSame(4, RepeatGate::repByteCost(9));
        self::assertSame(5, RepeatGate::repByteCost(99));
        self::assertSame(0, RepeatGate::repByteCost(0));
    }

    /**
     * End-to-end: the encoder emits the REP sequence only above the gate,
     * literal repeats below, and the cell grid reads back identically
     * either way (the gate is a transport choice, not a content choice).
     */
    public function testEncoderSwitchesShapeAtTheGate(): void
    {
        $below = (new DiffEncoder())->encode([
            new SetCellOp([Cell::new('▀')]),
            new RepeatRunOp('▀', 1, 1),
        ]);
        $above = (new DiffEncoder())->encode([
            new SetCellOp([Cell::new('▀')]),
            new RepeatRunOp('▀', 2, 1),
        ]);

        self::assertSame('▀▀', $below, 'count 1 of a 3-byte rune: 3 literal beats 4 framed');
        self::assertSame("▀\x1b[2b", $above, 'count 2 of a 3-byte rune: 6 literal loses to 4 framed');
    }
}
