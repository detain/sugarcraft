<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Sort;
use SugarCraft\Dashboard\Components\GridTable\SortDirection;

/**
 * D-143: the column-chooser state machine (factories, isSorted polarity,
 * toggle flip) had no direct pin — comparator selection itself lives in
 * GridTable and is already exercised there; this file pins the carrier
 * semantics the chooser depends on.
 */
final class SortTest extends TestCase
{
    public function testNoneIsUnsortedAscending(): void
    {
        $sort = Sort::none();

        self::assertNull($sort->column());
        self::assertSame(SortDirection::Asc, $sort->direction());
        self::assertFalse($sort->isSorted());
    }

    public function testAscAndDescFactoriesSetBothSlots(): void
    {
        $asc = Sort::asc('name');
        self::assertSame('name', $asc->column());
        self::assertSame(SortDirection::Asc, $asc->direction());
        self::assertTrue($asc->isSorted());

        $desc = Sort::desc('age');
        self::assertSame('age', $desc->column());
        self::assertSame(SortDirection::Desc, $desc->direction());
        self::assertTrue($desc->isSorted());
    }

    public function testEmptyColumnStringStillCountsAsSorted(): void
    {
        // isSorted() keys off null, not emptiness — a zero-length column
        // name is an explicit (if odd) choice and stays "sorted".
        $sort = Sort::asc('');

        self::assertTrue($sort->isSorted());
        self::assertSame('', $sort->column());
    }

    public function testToggleFlipsBothDirectionsAndCarriesTheColumn(): void
    {
        $asc = Sort::asc('name');
        $flipped = $asc->toggleDirection();

        self::assertSame(SortDirection::Desc, $flipped->direction());
        self::assertSame('name', $flipped->column());
        self::assertNotSame($asc, $flipped);

        $back = $flipped->toggleDirection();
        self::assertSame(SortDirection::Asc, $back->direction());
        self::assertSame('name', $back->column());
        // The original was never mutated by either flip.
        self::assertSame(SortDirection::Asc, $asc->direction());
    }

    public function testToggleOnNoneStaysUnsortedAscending(): void
    {
        // Asc -> Desc flip on an unsorted table must not invent a column:
        // the column stays null, so isSorted() remains false even though
        // the direction slot moved — the polarity contract is column-only.
        $toggled = Sort::none()->toggleDirection();

        self::assertNull($toggled->column());
        self::assertSame(SortDirection::Desc, $toggled->direction());
        self::assertFalse($toggled->isSorted());
    }
}
