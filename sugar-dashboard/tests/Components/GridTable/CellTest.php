<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Cell;

/**
 * D-143: Cell::toString() carries the whole currency/integer/percent
 * rendering surface and had no direct pin — every arm is a separate
 * number_format contract (scale, thousands separator, percent sign) that
 * a refactor of the match arms would silently change.
 */
final class CellTest extends TestCase
{
    public function testNullValuePaintsEmptyEvenWhenFormatted(): void
    {
        // The null guard sits BEFORE the match, so a formatted null cell
        // is still empty — that ordering is the contract.
        self::assertSame('', Cell::new(null)->toString());
        self::assertSame('', Cell::new(null, 'currency')->toString());
        self::assertNull(Cell::new()->value());
        self::assertNull(Cell::new()->format());
    }

    public function testCurrencyFormatsToTwoDecimalsWithThousandsGroups(): void
    {
        self::assertSame('1,234.50', Cell::new(1234.5, 'currency')->toString());
        self::assertSame('1,234.50', Cell::new('1234.5', 'currency')->toString());
        self::assertSame('0.00', Cell::new(0, 'currency')->toString());
    }

    public function testIntegerTruncatesTowardZeroThenGroups(): void
    {
        self::assertSame('9,876', Cell::new(9876, 'integer')->toString());
        self::assertSame('9,876', Cell::new('9876.5', 'integer')->toString());
        self::assertSame('1,000,000', Cell::new(1000000, 'integer')->toString());
    }

    public function testPercentScalesByHundredWithOneDecimalAndSign(): void
    {
        self::assertSame('50.0%', Cell::new(0.5, 'percent')->toString());
        self::assertSame('12.5%', Cell::new(0.125, 'percent')->toString());
        self::assertSame('0.0%', Cell::new(0, 'percent')->toString());
    }

    public function testUnknownFormatAndPlainCellFallThroughToPlainCast(): void
    {
        self::assertSame('42', Cell::new(42)->toString());
        self::assertSame('text', Cell::new('text')->toString());
        self::assertSame('text', Cell::new('text', 'unknown-format')->toString());
        self::assertSame('1', Cell::new(true)->toString());
    }

    public function testValueAndFormatCarryIndependently(): void
    {
        $cell = Cell::new(7, 'integer');

        self::assertSame(7, $cell->value());
        self::assertSame('integer', $cell->format());
        // Integer 0 is NOT null: the null guard must not swallow it.
        self::assertSame('0', Cell::new(0)->toString());
    }
}
