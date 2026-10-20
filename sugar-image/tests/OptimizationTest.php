<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Optimization;

/**
 * A-02: the chafa CHAFA_OPTIMIZATIONS ladder parity vocabulary.
 */
final class OptimizationTest extends TestCase
{
    /** Flag values are chafa's bits verbatim (chafa-common.h:187-196). */
    public function testBitValuesMatchUpstream(): void
    {
        self::assertSame(0, Optimization::None->value);
        self::assertSame(1, Optimization::ReuseAttributes->value);
        self::assertSame(4, Optimization::RepeatCells->value);
    }

    /**
     * 1<<1 (chafa SKIP_CELLS, "reserved for future use") is deliberately
     * absent — this pin turns RED if someone invents a local meaning for the
     * upstream dead bit.
     */
    public function testReservedSkipCellBitStaysAbsent(): void
    {
        self::assertNull(Optimization::tryFrom(2), 'the skip bit must have no case');
    }

    public function testBitsFoldCommutatively(): void
    {
        self::assertSame(0, Optimization::bits());
        self::assertSame(0, Optimization::bits(Optimization::None));
        self::assertSame(1, Optimization::bits(Optimization::ReuseAttributes));
        self::assertSame(5, Optimization::bits(Optimization::ReuseAttributes, Optimization::RepeatCells));
        self::assertSame(
            5,
            Optimization::bits(Optimization::RepeatCells, Optimization::ReuseAttributes),
        );
        self::assertSame(
            Optimization::all(),
            Optimization::bits(Optimization::None, Optimization::ReuseAttributes, Optimization::RepeatCells),
            'None folds as the zero flag chafa gives it, never as a veto',
        );
    }

    public function testHasTestsSingleBitsAndNoneIsNoFlag(): void
    {
        $mask = Optimization::all();
        self::assertTrue(Optimization::has($mask, Optimization::ReuseAttributes));
        self::assertTrue(Optimization::has($mask, Optimization::RepeatCells));
        self::assertFalse(Optimization::has($mask, Optimization::None));
        self::assertFalse(Optimization::has(0, Optimization::RepeatCells));
        self::assertFalse(
            Optimization::has(Optimization::bits(Optimization::RepeatCells), Optimization::ReuseAttributes),
        );
    }
}
