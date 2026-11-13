<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\Column;
use SugarCraft\Table\ColumnWidth;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\StyledCell;
use SugarCraft\Table\Table;

/**
 * Pins for the plain-text reading of cell values across the query and
 * width paths (audit MAJORs 4 + 11).
 *
 * Before the shared plainText() helper, the filter, search, and sort paths
 * stringified cells through __toString — a StyledCell's wrapper SGR rode
 * into every stripos()/strcasecmp(), so 'apple' sorted AFTER a red-styled
 * 'zebra', and searching visible text missed styled rows. The same casts
 * threw Error outright on a stdClass cell (one bad value killed the whole
 * pane mid-render), and the Content-width solver measured 'hello' as 14
 * cells because the wrapper was included.
 */
final class TableStyledCellInternalsTest extends TestCase
{
    /** Strip the table's own SGR runs so visible text can be asserted. */
    private static function plain(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    private static function nameTable(array $rows, string $style = 'left'): Table
    {
        return Table::fromColumns([
            Column::new('name', 'Name', 12)->withAlignLeft(true),
        ])->withRows($rows)->withSelectable(false);
    }

    public function testSortOrdersStyledCellsByTheirVisibleValue(): void
    {
        $t = self::nameTable([
            Row::new(RowData::from(['name' => StyledCell::new('zebra', '31')])),
            Row::new(RowData::from(['name' => 'apple'])),
        ])->sortBy('name', true);

        $names = array_map(
            static function (Row $r): string {
                $v = $r->data->get('name');
                return $v instanceof StyledCell ? (string) $v->value : (string) $v;
            },
            $t->pagedRows(),
        );

        $this->assertSame(['apple', 'zebra'], array_values($names));
    }

    public function testSearchMatchesStyledCellValueNotItsWrapper(): void
    {
        $t = self::nameTable([
            Row::new(RowData::from(['name' => StyledCell::new('hello', '31')])),
        ]);

        // The wrapper SGR contains no 'lo' substring, so a positive hit can
        // only come from the visible value; 'hel' proves it is the value.
        $this->assertSame(1, $t->search('hel')->totalRows());
        $this->assertSame(1, $t->search('llo')->totalRows());
        $this->assertSame(0, $t->search('zebra')->totalRows());
    }

    public function testFilterMatchesStyledCellValue(): void
    {
        $t = self::nameTable([
            Row::new(RowData::from(['name' => StyledCell::new('hello', '31')])),
            Row::new(RowData::from(['name' => 'world'])),
        ]);

        $this->assertSame(1, $t->filter('name', 'ell')->totalRows());
    }

    public function testNonScalarCellCostsItsOwnMatchabilityNotThePane(): void
    {
        $t = self::nameTable([
            Row::new(RowData::from(['name' => 'apple'])),
            Row::new(RowData::from(['name' => new \stdClass()])),
        ]);

        // Every path that reads values must survive the junk cell: the old
        // unguarded (string) casts threw Error here and killed the pane.
        $this->assertStringContainsString('apple', self::plain($t->view()));
        $this->assertSame(2, $t->sortBy('name', true)->totalRows());
        $this->assertSame(1, $t->search('apple')->totalRows());
        $this->assertSame(1, $t->filter('name', 'ple')->totalRows());
    }

    public function testComputeColumnWidthsMeasuresStyledValueNotWrapper(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'N', 2)->withColumnWidth(ColumnWidth::Content),
        ])->withRows([
            Row::new(RowData::from(['name' => StyledCell::new('hello', '31')])),
        ])->withSelectable(false);

        // Title 'N' is 1 cell, value 'hello' is 5; the SGR wrapper would
        // have measured 14+ and overflowed the column.
        $widths = $t->computeColumnWidths(40);
        $this->assertSame(5, $widths[0]);
    }
}
