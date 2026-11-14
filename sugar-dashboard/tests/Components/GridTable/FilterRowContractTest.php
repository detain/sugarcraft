<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Dashboard\Components\GridTable\Filter;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Row;
use SugarCraft\Dashboard\Components\GridTable\SortDirection;

/**
 * D-149 (finding 164): the Row transport is raw-typed and the Filter
 * polarity has exactly one truth source. The annotation's 'string-cast in
 * get()' seat is stale vs disk (get() never cast in this era) — this file
 * pins what the transport actually guarantees so a future refactor cannot
 * silently regress it.
 */
final class FilterRowContractTest extends TestCase
{
    public function testRowKeepsTypedCellsUntouchedThroughTheTransport(): void
    {
        $row = new Row(['n' => 42, 'flag' => true, 'ratio' => 0.5, 'text' => 'x', 'zero' => 0, 'false' => false]);

        self::assertSame(42, $row->get('n'));
        self::assertTrue($row->get('flag'));
        self::assertSame(0.5, $row->get('ratio'));
        self::assertSame('x', $row->get('text'));
        // The coercion victims of a string cast, pinned explicitly:
        self::assertSame(0, $row->get('zero'));
        self::assertFalse($row->get('false'));
        self::assertNull($row->get('missing'));
        self::assertTrue($row->has('zero'));
        self::assertTrue($row->has('false'));
    }

    public function testNumericCellsSortNumericallyNotLexically(): void
    {
        $column = \SugarCraft\Dashboard\Components\GridTable\Column::new('n', 'N', sortable: true);
        $rows = [new Row(['n' => 9]), new Row(['n' => 10]), new Row(['n' => 100]), new Row(['n' => 1])];
        $grid = GridTable::new([$column], $rows)->sort($column);

        self::assertSame(SortDirection::Asc, $grid->sortState()->direction);

        $invoke = new ReflectionMethod(GridTable::class, 'getSortedRows');
        $sorted = $invoke->invoke($grid, $rows);

        // A lexical (string) cast would order these 1, 10, 100, 9.
        self::assertSame([1, 9, 10, 100], array_map(static fn (Row $r): int => $r->get('n'), $sorted));
    }

    public function testFilterActivityCountsOnlyNonEmptyValues(): void
    {
        $neutral = Filter::fromArray(['name' => '']);
        self::assertFalse($neutral->isActive());
        self::assertFalse($neutral->hasFilter('name'));
        self::assertSame(['name' => ''], $neutral->filters());

        $mixed = $neutral->withFilter('age', '4');
        self::assertTrue($mixed->isActive());
        self::assertFalse($mixed->hasFilter('name'));
        self::assertTrue($mixed->hasFilter('age'));

        self::assertFalse(Filter::none()->isActive());
        self::assertFalse(Filter::none()->clear()->isActive());
    }

    public function testActiveFilterTracksAtLeastOneValueBeingPresent(): void
    {
        $filter = Filter::none()->withFilter('a', 'x');
        self::assertTrue($filter->isActive());
        $emptied = $filter->withFilter('a', '');
        self::assertFalse($emptied->isActive());
        self::assertSame('x', $filter->filter('a'));
        self::assertSame('', $emptied->filter('a')); // raw transport value kept
    }
}
