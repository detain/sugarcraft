<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Column;
use SugarCraft\Dashboard\Components\GridTable\Header;

/**
 * D-143: Header is the column/sort-indicator carrier the grid paints
 * from; render() is dead on disk (returns '' — the annotation's
 * "alignment/label painting" seat is stale, disclosed). Pins: defaults,
 * slot transport, and withSortedColumn's dual role (set and clear).
 */
final class HeaderTest extends TestCase
{
    public function testDefaultsAreEmptyColumnsIndicatorsOnUnsorted(): void
    {
        $header = Header::new();

        self::assertSame([], $header->columns());
        self::assertTrue($header->hasSortIndicators());
        self::assertNull($header->sortedColumn());
    }

    public function testNewCarriesExplicitSlotsThrough(): void
    {
        $columns = [Column::new('id', 'ID'), Column::new('name', 'Name')];
        $header = Header::new($columns, false, 'name');

        self::assertCount(2, $header->columns());
        self::assertSame($columns, $header->columns());
        self::assertFalse($header->hasSortIndicators());
        self::assertSame('name', $header->sortedColumn());
    }

    public function testWithSortedColumnSetsAndClearsCarryingEverythingElse(): void
    {
        $columns = [Column::new('id', 'ID')];
        $base = Header::new($columns, false);

        $marked = $base->withSortedColumn('id');
        self::assertSame('id', $marked->sortedColumn());
        self::assertFalse($marked->hasSortIndicators(), 'indicator flag must survive the mark');
        self::assertSame($columns, $marked->columns(), 'column list must survive the mark');
        self::assertNull($base->sortedColumn(), 'base must not mutate');

        // null is the explicit clear arm of the same door.
        self::assertNull($marked->withSortedColumn(null)->sortedColumn());
    }

    public function testRenderIsADeadEmptyPaintingDisclosedVsDocblock(): void
    {
        self::assertSame('', Header::new([Column::new('id', 'ID')])->render());
        self::assertSame('', Header::new()->render());
    }
}
