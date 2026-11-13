<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\CellBuffer\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\StyledCell;
use SugarCraft\Table\Table;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function makeTable(): Table
    {
        return Table::fromColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 20),
            Column::new('city', 'City', 15),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
            Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
            Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'CHI'])),
        ]);
    }

    public function testNew(): void
    {
        $t = $this->makeTable();
        $this->assertSame(3, \count($t->columns()));
        $this->assertSame(3, $t->totalRows());
    }

    public function testAddRow(): void
    {
        $t = $this->makeTable();
        $t = $t->addRow(Row::new(RowData::from(['id' => '4', 'name' => 'Dave', 'city' => 'HOU'])));
        $this->assertSame(4, $t->totalRows());
    }

    public function testSortByAscending(): void
    {
        $t = $this->makeTable()->sortBy('name', ascending: true);
        $this->assertSame('Alice', $t->currentRowData()?->get('name'));
        $this->assertSame('Bob', $t->pagedRows()[1]->data->get('name'));
    }

    public function testSortByDescending(): void
    {
        $t = $this->makeTable()->sortBy('name', ascending: false);
        $this->assertSame('Carol', $t->pagedRows()[0]->data->get('name'));
    }

    public function testSortToggle(): void
    {
        $t = $this->makeTable()->sortBy('name', true);
        $t = $t->sortBy('name', true);  // same key, should toggle
        $this->assertSame('Carol', $t->filteredSortedRows()[0]->data->get('name'));
    }

    public function testFilter(): void
    {
        $t = $this->makeTable()->filter('name', 'ali');
        $this->assertSame(1, $t->totalRows());
        $this->assertSame('Alice', $t->currentRowData()?->get('name'));
    }

    public function testFilterClear(): void
    {
        $t = $this->makeTable()->filter('name', 'ali');
        $t = $t->clearFilter('name');
        $this->assertSame(3, $t->totalRows());
    }

    public function testFilterMultipleColumns(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob',   'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Carol', 'city' => 'LA'])),
        ])->filter('name', 'a')
          ->filter('city', 'NYC');

        $this->assertSame(1, $t->totalRows());
    }

    public function testSelectNext(): void
    {
        $t = $this->makeTable()->selectNext();
        $this->assertSame('Bob', $t->currentRowData()?->get('name'));
    }

    public function testSelectPrevious(): void
    {
        $t = $this->makeTable()->selectNext()->selectNext()->selectPrevious();
        $this->assertSame('Bob', $t->currentRowData()?->get('name'));
    }

    public function testSelectNextClampsAtEnd(): void
    {
        $t = $this->makeTable();
        for ($i = 0; $i < 20; $i++) {
            $t = $t->selectNext();
        }
        $this->assertSame('Carol', $t->currentRowData()?->get('name'));
    }

    public function testWithSelectedIndexMovesCursor(): void
    {
        $t = $this->makeTable()->withSelectedIndex(2);
        $this->assertSame(2, $t->selectedIndex());
        $this->assertSame('Carol', $t->currentRowData()?->get('name'));
    }

    public function testWithSelectedIndexClamps(): void
    {
        $t = $this->makeTable();
        $this->assertSame(2, $t->withSelectedIndex(99)->selectedIndex());
        $this->assertSame(0, $t->withSelectedIndex(-5)->selectedIndex());
    }

    public function testWithSelectedIndexNoOpOnEmpty(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])->withSelectedIndex(3);
        $this->assertSame(0, $t->selectedIndex());
    }

    public function testWithSelectedIndexImmutable(): void
    {
        $t = $this->makeTable();
        $this->assertNotSame($t, $t->withSelectedIndex(1));
        $this->assertSame(0, $t->selectedIndex());
    }

    public function testPagination(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(0, 49)
                )
            )
            ->withPageSize(10)
            ->withPage(2);  // 0-indexed page 2 = rows 20-29

        $this->assertSame(5, $t->totalPages());
        $this->assertSame('20', $t->currentRowData()?->get('n'));
    }

    /**
     * ST-04 pin: shrinking the page size past the last surviving page must
     * clamp the page and re-park the cursor — it used to keep the stale
     * interior page, rendering an empty body with a "Page 3 of 1" footer.
     */
    /**
     * ST-55 (finding 067): PageSize() is a read-only round-trip of
     * withPageSize() — default 0 (no pagination) and the set value.
     */
    public function testPageSizeGetterRoundTrips(): void
    {
        $t = $this->makeTable();
        $this->assertSame(0, $t->pageSize(), 'pagination is opt-in: the default page size is 0');

        $paged = $t->withPageSize(5);
        $this->assertSame(5, $paged->pageSize());
        $this->assertSame(0, $t->pageSize(), 'the setter left the original untouched');
    }

    public function testWithPageSizeShrinkClampsPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(0, 24)
                )
            )
            ->withPageSize(10)
            ->withPage(2);
        $this->assertSame(3, $t->totalPages());

        $shrunk = $t->withPageSize(30);

        $this->assertSame(1, $shrunk->totalPages());
        $this->assertSame(0, $shrunk->currentPage());
        $this->assertSame('0', $shrunk->currentRowData()?->get('n'));
        $this->assertSame('Page 1 of 1', $shrunk->pageFooter());
        $this->assertNotEmpty($shrunk->rows(), 'shrink must never strand the frame as an empty body');
    }

    /**
     * ST-04 pin: growth keeps the current page, re-paginated under the new
     * size — page 1 of 3 (rows 10-19) at size 10 becomes page 1 of 5
     * (rows 5-9) at size 5, cursor parked on the page's first row.
     */
    public function testWithPageSizeGrowRepaginatesCurrentPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(0, 24)
                )
            )
            ->withPageSize(10)
            ->withPage(1);

        $grown = $t->withPageSize(5);

        $this->assertSame(5, $grown->totalPages());
        $this->assertSame(1, $grown->currentPage());
        $this->assertSame('5', $grown->currentRowData()?->get('n'));
    }

    public function testNextPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->nextPage();

        $this->assertSame('11', $t->currentRowData()?->get('n'));
    }

    public function testPageFooter(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10);

        $this->assertSame('Page 1 of 1', $t->pageFooter());
    }

    public function testMissingDataIndicator(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5), Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),  // no 'name'
            ])
            ->withMissingIndicator('<missing>');

        $view = $t->view();
        $this->assertStringContainsString('<missing>', $view);
    }

    public function testStyledCellOverridesColumnStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => StyledCell::new('X', '1;31')])),
            ]);

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('X', $view);
    }

    public function testZebraStriping(): void
    {
        $t = $this->makeTable()->withZebra();
        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('Alice', $view);
    }

    public function testFrozenCols(): void
    {
        $t = $this->makeTable()->withFrozenCols([0]);
        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('ID', $view);
    }

    public function testHorizontalScroll(): void
    {
        $t = $this->makeTable()->withScrollX(5);
        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testRowStyle(): void
    {
        $t = $this->makeTable()
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'X', 'city' => 'Y']))->withStyle('1'),
            ]);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testRowWithZebra(): void
    {
        $t = $this->makeTable()
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'X', 'city' => 'Y']))->withZebra(),
            ]);

        $this->assertTrue($t->rows()[0]->zebra);
    }

    /**
     * ST-48 (finding 057): the styleFunc slot stores a Closure — the only
     * legal property shape for the callable contract, wrapped at ingress.
     */
    public function testStyleFuncStorageIsClosure(): void
    {
        $fn = static fn (int $row): string => '';
        $t = $this->makeTable()->withStyleFunc($fn);
        $stored = (new \ReflectionProperty(Table::class, 'styleFunc'))->getValue($t);
        $this->assertInstanceOf(\Closure::class, $stored);

        $cleared = (new \ReflectionProperty(Table::class, 'styleFunc'))->getValue($t->withStyleFunc(null));
        $this->assertNull($cleared);
    }

    public function testClearSort(): void
    {
        $t = $this->makeTable()->sortBy('name', false)->clearSort();
        $this->assertSame('Alice', $t->currentRowData()?->get('name'));
    }

    public function testClearAllFilters(): void
    {
        $t = $this->makeTable()
            ->filter('name', 'ali')
            ->clearAllFilters();

        $this->assertSame(3, $t->totalRows());
    }

    public function testViewRendersTopAndBottomBorders(): void
    {
        $t = $this->makeTable();
        $view = $t->view();
        $this->assertStringContainsString('┌', $view);
        $this->assertStringContainsString('└', $view);
        $this->assertStringContainsString('─', $view);
    }

    public function testViewRendersHeader(): void
    {
        $t = $this->makeTable();
        $view = $t->view();
        $this->assertStringContainsString('ID', $view);
        $this->assertStringContainsString('Name', $view);
        $this->assertStringContainsString('City', $view);
    }

    public function testImmutability(): void
    {
        $a = $this->makeTable();
        $b = $a->sortBy('name', false);
        $this->assertNotSame($a, $b);
        $this->assertSame(3, $a->totalRows());
    }

    public function testStyleFuncWithStringReturn(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),
                Row::new(RowData::from(['id' => '2'])),
            ])
            ->withStyleFunc(fn (int $row) => $row === 0 ? '1;31' : '');

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('1', $view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testStyleFuncWithStyleReturn(): void
    {
        $redStyle = Style::new(0xff0000);
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => 'X'])),
            ])
            ->withStyleFunc(fn (int $row) => $redStyle);

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testWideCharColumnLayout(): void
    {
        $t = Table::fromColumns([Column::new('val', 'Val', 12)])
            ->withRows([
                Row::new(RowData::from(['val' => 'short'])),
                Row::new(RowData::from(['val' => '中文'])),
                Row::new(RowData::from(['val' => 'longer label'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThanOrEqual(4, $widths[0]);

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString('short', $view);
        $this->assertStringContainsString('longer label', $view);
    }

    public function testHideHeader(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withShowHeader(false);

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringNotContainsString('ID', $view);
    }

    public function testHideFooter(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withPageSize(10)
            ->withShowFooter(false);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testViewportScrollBeyondRows(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withViewportHeight(5)
            ->withScrollY(10);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testViewportScrollWithinRows(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ])
            ->withViewportHeight(2)
            ->withScrollY(1);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testEmptyTableReturnsEmptyString(): void
    {
        $t = Table::fromColumns([]);
        $this->assertSame('', $t->view());
    }

    public function testColor256Foreground(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;5;196');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testColor256Background(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;21');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testStyleFuncWithFgColor(): void
    {
        $redStyle = Style::new(0xff0000);
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => 'A'])),
                Row::new(RowData::from(['id' => 'B'])),
            ])
            ->withStyleFunc(fn (int $row) => $row === 0 ? $redStyle : Style::new());

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testStyleFuncWithFgBgAndAttr(): void
    {
        $styled = Style::new(0x00ff00, 0x0000aa, Style::ATTR_BOLD | Style::ATTR_UNDERLINE);
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withStyleFunc(fn () => $styled);

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testMultilineMode(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => "line1\nline2"]))])
            ->withMultilineMode(true);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithRgbColor(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;2;255;128;0');

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testBorderStyleWithBrightColor(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('1;91');

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testTableBaseStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBaseStyle('1;32');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testSelectableFalse(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withSelectable(false);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testSelectPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->selectPage(2);

        $this->assertSame(2, $t->currentPage());
    }

    public function testPreviousPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->selectPage(2)
            ->previousPage();

        $this->assertSame(1, $t->currentPage());
    }

    public function testColumnWidthDynamicWithContent(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Dynamic)])
            ->withRows([
                Row::new(RowData::from(['id' => 'tiny'])),
                Row::new(RowData::from(['id' => 'this is a longer value'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThanOrEqual(5, $widths[0]);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testColumnWidthContentType(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Content)])
            ->withRows([
                Row::new(RowData::from(['id' => 'short'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testColumnWidthPercent(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 20)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Percent, 25.0)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThan(0, $widths[0]);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testHeaderStyleCustom(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withHeaderStyle('1;4;34');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testFilterWithNoMatchingRows(): void
    {
        $t = Table::fromColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->filter('name', 'xyz');

        $this->assertSame(0, $t->totalRows());
    }

    public function testSortByNumeric(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([
                Row::new(RowData::from(['n' => '10'])),
                Row::new(RowData::from(['n' => '2'])),
                Row::new(RowData::from(['n' => '100'])),
            ])
            ->sortBy('n', true);

        $rows = $t->filteredSortedRows();
        $this->assertSame('2', $rows[0]->data->get('n'));
    }

    public function testSortByNumericDescending(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([
                Row::new(RowData::from(['n' => '10'])),
                Row::new(RowData::from(['n' => '2'])),
                Row::new(RowData::from(['n' => '100'])),
            ])
            ->sortBy('n', false);

        $rows = $t->filteredSortedRows();
        $this->assertSame('100', $rows[0]->data->get('n'));
    }

    public function testAddRows(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->addRows([
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ]);

        $this->assertSame(3, $t->totalRows());
    }

    public function testBorderStyleWithBg256Grayscale(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;244');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithBrightBg(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;105');

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testColumnWithStyle(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withStyle('1;31'),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testColumnWithAlignLeft(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withAlignLeft(),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testFilterCaseInsensitive(): void
    {
        $t = Table::fromColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->filter('name', 'ALI');

        $this->assertSame(1, $t->totalRows());
    }

    public function testZeroPageSizeMeansNoPagination(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(0);

        $this->assertSame(1, $t->totalPages());
    }

    public function testTotalRowsWithNoRows(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([]);

        $this->assertSame(0, $t->totalRows());
    }

    public function testRowsFooterFirstPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 1 to 25 of 100 rows', $t->rowsFooter());
    }

    public function testRowsFooterSecondPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(1)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 26 to 50 of 100 rows', $t->rowsFooter());
    }

    public function testRowsFooterLastPagePartial(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(3)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        // Page 3: rows 76-100
        $this->assertSame('Showing 76 to 100 of 100 rows', $t->rowsFooter());
    }

    public function testRowsFooterEmptyTable(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([])
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 0 to 0 of 0 rows', $t->rowsFooter());
    }

    public function testRowsFooterSinglePage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 10)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 1 to 10 of 10 rows', $t->rowsFooter());
    }

    public function testFooterTypePageMode(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10)
            ->withFooterType(\SugarCraft\Table\FooterType::Page);

        $this->assertSame('Page 1 of 1', $t->pageFooter());
        $this->assertSame('Showing 1 to 1 of 1 rows', $t->rowsFooter());
    }

    public function testFooterTypeImmutability(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10);

        $t2 = $t->withFooterType(\SugarCraft\Table\FooterType::Rows);
        $this->assertNotSame($t, $t2);
        // Original should still have default Page footer type
        $this->assertStringContainsString('Page', $t->view());
    }

    public function testFooterTypeBothRendersCombined(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 50)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(1)
            ->withFooterType(\SugarCraft\Table\FooterType::Both);

        $view = $t->view();
        $this->assertStringContainsString('Page 2 of 4', $view);
        $this->assertStringContainsString('Showing 26 to 50 of 100 rows', $view);
    }

    public function testFooterTypeRowsRendersCorrectly(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 30)])
            ->withRows(
                \array_map(
                    fn ($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 25)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $view = $t->view();
        $this->assertStringContainsString('Showing 1 to 25 of 25 rows', $view);
        // Should not contain "Page" style footer
        $this->assertStringNotContainsString('Page 1 of 1', $view);
    }

    // -------------------------------------------------------------------------
    // Item 5.1 — Frozen/hidden conflict detection
    // -------------------------------------------------------------------------

    public function testWithFrozenColsThrowsOnOverlapWithHiddenCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/frozen.*hidden|hidden.*frozen/i');
        $this->makeTable()->withFrozenCols([0])->withHiddenCols([0]);
    }

    public function testWithHiddenColsThrowsOnOverlapWithFrozenCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/hidden.*frozen|frozen.*hidden/i');
        $this->makeTable()->withHiddenCols([0])->withFrozenCols([0]);
    }

    public function testWithFrozenColsMultipleOverlapReportsAllConflicting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Columns 0 and 1 are both frozen and hidden
        $this->makeTable()->withFrozenCols([0, 1])->withHiddenCols([0, 1, 2]);
    }

    public function testWithFrozenColsNonOverlappingIsAllowed(): void
    {
        $t = $this->makeTable()->withFrozenCols([0])->withHiddenCols([1]);
        $this->assertSame(3, $t->totalRows()); // no exception
    }

    public function testWithHiddenColsNonOverlappingIsAllowed(): void
    {
        // Frozen must stay a contiguous prefix (audit #3); hiding a column
        // that is not frozen still composes: frozen [0], hidden [2].
        $t = $this->makeTable()->withHiddenCols([2])->withFrozenCols([0]);
        $this->assertSame(3, $t->totalRows()); // no exception
    }

    // -------------------------------------------------------------------------
    // Item 5.2 — Filter/SortBy invalid column key validation
    // -------------------------------------------------------------------------

    public function testSortByThrowsOnInvalidColumnKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        $this->makeTable()->sortBy('nonexistent_key');
    }

    public function testFilterThrowsOnInvalidColumnKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        $this->makeTable()->filter('invalid_key', 'text');
    }

    public function testSortByWithValidKeyDoesNotThrow(): void
    {
        $t = $this->makeTable()->sortBy('name');
        $this->assertSame('Alice', $t->currentRowData()?->get('name'));
    }

    public function testFilterWithValidKeyDoesNotThrow(): void
    {
        $t = $this->makeTable()->filter('name', 'ali');
        $this->assertSame(1, $t->totalRows());
    }

    // -------------------------------------------------------------------------
    // Navigation boundary short-circuit
    // -------------------------------------------------------------------------

    public function testSelectNextAtLastRowReturnsSameInstance(): void
    {
        $t = $this->makeTable()->selectNext()->selectNext(); // on 'Carol' (last)
        $this->assertSame($t, $t->selectNext());
    }

    public function testSelectPreviousAtFirstRowReturnsSameInstance(): void
    {
        $t = $this->makeTable(); // selectedIndex 0
        $this->assertSame($t, $t->selectPrevious());
    }

    public function testSelectNextOnEmptyTableReturnsSameInstance(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)]);
        $this->assertSame($t, $t->selectNext());
    }

    public function testSelectNextMidTableReturnsNewInstance(): void
    {
        $t = $this->makeTable();
        $next = $t->selectNext();
        $this->assertNotSame($t, $next);
        $this->assertSame(0, $t->selectedIndex());
        $this->assertSame(1, $next->selectedIndex());
    }

    public function testSelectPreviousMidTableReturnsNewInstance(): void
    {
        $t = $this->makeTable()->selectNext();
        $prev = $t->selectPrevious();
        $this->assertNotSame($t, $prev);
        $this->assertSame(0, $prev->selectedIndex());
    }

    // -------------------------------------------------------------------------
    // widthSolveCache LRU bound
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, int>> */
    private function widthSolveCacheOf(Table $t): array
    {
        $prop = new \ReflectionProperty(Table::class, 'widthSolveCache');
        return $prop->getValue($t);
    }

    public function testWidthSolveCacheStaysBounded(): void
    {
        $t = $this->makeTable();
        for ($w = 40; $w < 60; $w++) {
            $t->computeColumnWidths($w);
        }
        $this->assertLessThanOrEqual(8, \count($this->widthSolveCacheOf($t)));
    }

    public function testWidthSolveCacheEvictsOldestFirst(): void
    {
        $t = $this->makeTable();
        for ($w = 40; $w < 49; $w++) { // 9 distinct widths, cap is 8
            $t->computeColumnWidths($w);
        }
        $cache = $this->widthSolveCacheOf($t);
        $this->assertArrayNotHasKey(40, $cache); // first-in evicted
        $this->assertArrayHasKey(48, $cache);
    }

    public function testWidthSolveCacheHitRefreshesRecency(): void
    {
        $t = $this->makeTable();
        for ($w = 40; $w < 48; $w++) { // fill to cap; 40 is oldest
            $t->computeColumnWidths($w);
        }
        $t->computeColumnWidths(40);   // touch 40 → now most recent
        $t->computeColumnWidths(100);  // evicts 41, not 40
        $cache = $this->widthSolveCacheOf($t);
        $this->assertArrayHasKey(40, $cache);
        $this->assertArrayNotHasKey(41, $cache);
    }

    public function testWidthSolveCacheHitReturnsSameWidths(): void
    {
        $t = $this->makeTable();
        $first = $t->computeColumnWidths(42);
        $this->assertSame($first, $t->computeColumnWidths(42));
    }

    // -------------------------------------------------------------------------
    // SelectedRow() convenience accessor
    // -------------------------------------------------------------------------

    public function testSelectedRowReturnsCurrentRow(): void
    {
        $t = $this->makeTable()->selectNext();
        $this->assertSame('Bob', $t->selectedRow()?->data->get('name'));
        $this->assertSame($t->currentRow(), $t->selectedRow());
    }

    public function testSelectedRowOnEmptyTableReturnsNull(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)]);
        $this->assertNull($t->selectedRow());
    }

    public function testSelectedRowRespectsFilterAndPaging(): void
    {
        $rows = [];
        foreach (['ant', 'bee', 'cat', 'cow', 'dog', 'doe'] as $i => $name) {
            $rows[] = Row::new(RowData::from(['id' => (string) $i, 'name' => $name]));
        }
        $t = Table::fromColumns([
            Column::new('id', 'ID', 4),
            Column::new('name', 'Name', 10)->withFilterable(),
        ])->withRows($rows)->filter('name', 'o')->withPageSize(2);

        // Filter 'o' keeps cow, dog, doe; page size 2 → page 1 holds only 'doe'
        $t = $t->selectPage(1);
        $this->assertSame('doe', $t->selectedRow()?->data->get('name'));

        // withSelectedIndex is page-global and auto-pages (audit #5, mirroring
        // Bubbles table.SetCursor): index 1 lands on 'dog' on page 0 — the
        // page follows the cursor instead of the selection falling off a
        // short page into null.
        $moved = $t->withSelectedIndex(1);
        $this->assertSame('dog', $moved->selectedRow()?->data->get('name'));
        $this->assertSame(0, $moved->currentPage());
    }

    // -------------------------------------------------------------------------
    // Additional Table method coverage
    // -------------------------------------------------------------------------

    public function testWithColumnsReplacesColumns(): void
    {
        $t = $this->makeTable();
        $t2 = $t->withColumns([
            Column::new('x', 'X', 5),
            Column::new('y', 'Y', 10),
        ]);

        $this->assertSame(2, \count($t2->columns()));
        $this->assertSame('x', $t2->columns()[0]->key);
        $this->assertSame('y', $t2->columns()[1]->key);
        // Original unchanged
        $this->assertSame(3, \count($t->columns()));
    }

    public function testWithColumnsInvalidatesCache(): void
    {
        $t = $this->makeTable()->sortBy('name');
        $this->assertSame('Alice', $t->currentRowData()?->get('name'));

        // Replace columns - should clear sort cache
        $t2 = $t->withColumns([
            Column::new('id', 'ID', 5)->withFilterable(),
        ]);
        // Should not throw even though original sort was by 'name' which no longer exists
        $this->assertNotSame($t, $t2);
    }

    public function testWithBaseStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBaseStyle('1;31');

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testWithMissingIndicatorCustom(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])), // no 'name' key
        ])->withMissingIndicator('???');

        $view = $t->view();
        $this->assertStringContainsString('???', $view);
    }

    public function testWithSelectableFalseDisablesSelection(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withSelectable(false);

        // Navigation should work but selection highlight should not appear
        $view = $t->view();
        $this->assertIsString($view);
        // No reverse-video highlight (style '7') should appear
        $this->assertStringNotContainsString("\x1b[7m", $view);
    }

    public function testWithSelectableTrueEnablesSelection(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withSelectable(true)
            ->selectNext(); // Select a row to trigger highlight

        $view = $t->view();
        $this->assertIsString($view);
        // Should have selection highlight (reverse video). The format is ESC[7m or ESC[0;7m
        $this->assertMatchesRegularExpression('/\x1b\[(?:0;)?7m/', $view);
    }

    public function testWithPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(fn($i) => Row::new(RowData::from(['n' => (string) $i])), \range(1, 30))
            )
            ->withPageSize(10)
            ->withPage(2);

        $this->assertSame(2, $t->currentPage());
        $this->assertSame('21', $t->currentRowData()?->get('n'));
    }

    public function testWithScrollX(): void
    {
        $t = $this->makeTable()->withScrollX(1);
        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithZebraEvenStyle(): void
    {
        $t = $this->makeTable()->withZebra();
        // Zebra should apply even row styling
        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithHeaderStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withHeaderStyle('1;4;33'); // bold underline yellow

        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testWithShowHeaderFalse(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withShowHeader(false);

        $view = $t->view();
        $this->assertStringNotContainsString('ID', $view);
    }

    public function testWithShowHeaderTrue(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withShowHeader(true);

        $view = $t->view();
        $this->assertStringContainsString('ID', $view);
    }

    public function testWithShowFooterFalseNoPageSize(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withShowFooter(false);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithShowFooterTrueWithPagination(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withPageSize(10)
            ->withShowFooter(true);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithBorderUsesCustomBorder(): void
    {
        $border = \SugarCraft\Style\Border::rounded();
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorder($border);

        $view = $t->view();
        $this->assertIsString($view);
        // Rounded border uses different characters
        $this->assertStringContainsString('╭', $view);
        $this->assertStringContainsString('╮', $view);
    }

    public function testWithCellPadding(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 10)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withCellPadding(1);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithCellPaddingZero(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 10)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withCellPadding(0);

        $view = $t->view();
        $this->assertIsString($view);
    }

    public function testWithWidthFixedWithFlexColumn(): void
    {
        // withWidth affects Flex columns - creates exact-width table
        $t = Table::fromColumns([Column::new('id', 'ID', 5)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Flex)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withWidth(50);

        $widths = $t->computeColumnWidths(50);
        // The flex column should expand to fill the target width
        $this->assertGreaterThanOrEqual(50, \array_sum($widths));
    }

    public function testWithWidthZeroRestoresNatural(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withWidth(0);

        // Should compute natural width from columns
        $this->assertGreaterThan(0, $t->computeColumnWidths(80)[0] ?? 0);
    }

    public function testWithHiddenCols(): void
    {
        $t = $this->makeTable()->withHiddenCols([1]); // hide 'name' column
        $view = $t->view();
        $this->assertIsString($view);
        $this->assertStringNotContainsString('Name', $view);
        $this->assertStringContainsString('ID', $view);
    }

    public function testColumnMethod(): void
    {
        $t = $this->makeTable();
        $col = $t->column('name');
        $this->assertNotNull($col);
        $this->assertSame('name', $col->key);
    }

    public function testColumnMethodReturnsNullForMissing(): void
    {
        $t = $this->makeTable();
        $col = $t->column('nonexistent');
        $this->assertNull($col);
    }

    public function testRowsFooterWithNoPaginationShowsAll(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(\array_map(fn($i) => Row::new(RowData::from(['n' => (string) $i])), \range(1, 10)))
            ->withPageSize(0) // no pagination
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 1 to 10 of 10 rows', $t->rowsFooter());
    }

    public function testComputeTotalWidthWithoutTargetWidth(): void
    {
        $t = $this->makeTable();
        // Use reflection to call private method
        $refl = new \ReflectionMethod(Table::class, 'computeTotalWidth');
        $refl->setAccessible(true);
        $width = $refl->invoke($t);
        $this->assertGreaterThan(0, $width);
    }

    public function testFilterableColumnsOnly(): void
    {
        // When some columns are marked filterable, only those should be searched
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10)->withFilterable(),
            Column::new('city', 'City', 10), // not filterable
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'Denver'])),
        ]);

        // Filter on filterable column should work
        $t2 = $t->filter('name', 'Ali');
        $this->assertSame(1, $t2->totalRows());

        // Filter on a non-filterable column is now a loud guarantee, not a
        // silent no-op (audit ST-26):
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'city' is not filterable");
        $t->filter('city', 'NYC');
    }

    public function testClearFilterOnUnmarkedColumnAlsoThrows(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10)->withFilterable(),
            Column::new('city', 'City', 10),
        ])->withRows([Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC']))]);

        $this->expectException(\InvalidArgumentException::class);
        $t->clearFilter('city');
    }

    public function testStrandedFilterTextStaysInertAfterColumnSwap(): void
    {
        // The defensive render-time skip (ST-26): text set while 'city' was
        // filterable, then the column loses the mark — filtering halts rather
        // than silently matching new data.
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10)->withFilterable(),
            Column::new('city', 'City', 10)->withFilterable(),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'Denver'])),
        ])->filter('city', 'NYC');
        $this->assertSame(1, $t->totalRows());

        $swapped = $t->withColumns([
            Column::new('name', 'Name', 10)->withFilterable(),
            Column::new('city', 'City', 10),
        ]);
        $this->assertSame(2, $swapped->totalRows(), 'stranded text must not filter, not throw at render');
    }

    public function testGlobalSearchOnlySearchesFilterableColumns(): void
    {
        // When columns are explicitly marked filterable, global search should only search those
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10)->withFilterable(),
            Column::new('city', 'City', 10), // not filterable
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'Denver'])),
        ]);

        // Search for term only in non-filterable column
        $t2 = $t->search('NYC');
        // Should NOT find it because city is not filterable
        $this->assertSame(0, $t2->totalRows());
    }

    public function testGlobalSearchSearchesAllColumnsWhenNoneFilterable(): void
    {
        // When NO columns are marked filterable, search should search all (back-compat)
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'Denver'])),
        ]);

        // Search should find 'NYC' even though no columns are explicitly filterable
        $t2 = $t->search('NYC');
        $this->assertSame(1, $t2->totalRows());
    }

    /**
     * ST-05: the old multi-key sort test was vacuous — its secondary key
     * only ever saw rows already ordered by insertion, so deleting the
     * tie-break leg left it green. Fixture rebuilt so the secondary key
     * DISCRIMINATES: city is primary (two rows tie on NYC), name is the
     * tie-breaker, and the full ordered sequence is asserted.
     */
    public function testSortByAppendsSecondaryTieBreaker(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Carol', 'city' => 'CHI'])),
        ]);

        // Primary city asc (CHI < NYC), secondary name asc breaks the NYC tie.
        $t2 = $t->sortBy('city', true, true)->sortBy('name', true, false);
        $names = \array_map(fn (Row $r): string => $r->data->get('name'), $t2->filteredSortedRows());

        $this->assertSame(['Carol', 'Alice', 'Bob'], $names);
    }

    /**
     * ST-05 mirror: a differing PRIMARY must not be reordered by the
     * secondary. name desc wins over city asc; if the comparator consulted
     * keys in the wrong order (or the secondary leaked dominance) the
     * sequence would flip to Alice-before-Bob.
     */
    public function testPrimarySortNotReorderedBySecondary(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'CHI'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'NYC'])),
        ]);

        $t2 = $t->sortBy('name', false, true)->sortBy('city', true, false);
        $names = \array_map(fn (Row $r): string => $r->data->get('name'), $t2->filteredSortedRows());

        $this->assertSame(['Bob', 'Alice'], $names);
    }

    public function testClearSortClearsAllSorts(): void
    {
        $t = $this->makeTable()
            ->sortBy('name', true)
            ->sortBy('city', true, false);

        $t2 = $t->clearSort();
        $this->assertSame('Alice', $t2->currentRowData()?->get('name')); // original order
    }

    public function testAddRowsMultiple(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->addRows([
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ]);

        $this->assertSame(3, $t->totalRows());
    }

    public function testTableImmutability(): void
    {
        $a = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))]);

        // Test each configuration method returns a new instance
        $t = $a
            ->withBaseStyle('1')
            ->withMissingIndicator('?')
            ->withBorderStyle('1;31')
            ->withSelectable(false)
            ->withPageSize(10)
            ->withPage(0)
            ->withScrollX(0)
            ->withViewportHeight(10)
            ->withScrollY(0)
            ->withZebra(false)
            ->withHeaderStyle('1')
            ->withShowHeader(true)
            ->withShowFooter(true)
            ->withMultilineMode(false)
            ->withCellPadding(1)
            ->withBorderless(false)
            ->withWidth(80)
            ->withHiddenCols([]);

        $this->assertNotSame($a, $t);
        // Verify original unchanged
        $this->assertSame(1, $a->totalRows());
    }

    /**
     * ST-09: plain data arrays are honored at the row entry points — the
     * shape README quickstarts and candy-sql call sites already pass.
     */
    public function testWithRowsCoercesPlainDataArrays(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5), Column::new('name', 'Name', 8)])
            ->withRows([
                ['id' => '1', 'name' => 'Alice'],
                Row::new(RowData::from(['id' => '2', 'name' => 'Bob'])),
            ]);

        $this->assertSame(2, $t->totalRows());
        $rows = $t->rows();
        $this->assertInstanceOf(Row::class, $rows[0]);
        $this->assertSame('Alice', $rows[0]->data->get('name'));
        $this->assertSame('Bob', $rows[1]->data->get('name'));
    }

    public function testAddRowsCoercesPlainDataArrays(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([['id' => '1']])
            ->addRows([['id' => '2']]);

        $this->assertSame(2, $t->totalRows());
    }

    /**
     * ST-09: an unusable entry names its index and type at the boundary
     * instead of detonating as a raw TypeError deep in the renderer.
     */
    public function testWithRowsRejectsOffendingEntry(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('withRows()[1] must be Row or array, got string');
        $t->withRows([Row::new(RowData::from(['id' => '1'])), 'oops']);
    }

    public function testFromColumnsRejectsOffendingEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fromColumns()[1] must be Column, got array');
        Table::fromColumns([Column::new('id', 'ID', 5), ['id']]);
    }

    /**
     * ST-03: the query-state readouts reflect the setters' state, and the
     * returned arrays are value copies — mutating them cannot touch the table.
     */
    public function testQueryStateAccessorsRoundTrip(): void
    {
        $t = Table::fromColumns([
            Column::new('name', 'Name', 8),
            Column::new('city', 'City', 8),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Ann', 'city' => 'Rome'])),
            Row::new(RowData::from(['name' => 'Bob', 'city' => 'Oslo'])),
        ]);

        $this->assertSame([], $t->sortColumns());
        $this->assertSame([], $t->activeFilters());
        $this->assertSame('', $t->searchText());

        $sorted = $t->sortBy('city')->sortBy('name', ascending: false, primary: false);
        $this->assertSame(
            [['key' => 'city', 'asc' => true], ['key' => 'name', 'asc' => false]],
            $sorted->sortColumns()
        );

        $filtered = $sorted->filter('city', 'Oslo');
        $this->assertSame(['city' => 'Oslo'], $filtered->activeFilters());

        $searched = $filtered->search('ann');
        $this->assertSame('ann', $searched->searchText());

        // Defensive copies: PHP array returns are already copies, and the
        // accessor must not expose live storage under any write-through.
        $copy = $searched->sortColumns();
        $copy[] = ['key' => 'rogue', 'asc' => true];
        $filters = $searched->activeFilters();
        $filters['city'] = 'tampered';
        $this->assertSame($sorted->sortColumns(), $searched->sortColumns());
        $this->assertSame(['city' => 'Oslo'], $searched->activeFilters());
    }

    /**
     * ST-03 (finding 075): totalWidth() reports the solved render budget —
     * the pinned frame when set, else content widths plus inter-column borders.
     */
    public function testTotalWidthReadoutFollowsTheSolve(): void
    {
        $t = Table::fromColumns([
            Column::new('a', 'A', 4),
            Column::new('b', 'B', 6),
        ]);

        $this->assertSame(11, $t->totalWidth(), 'fixed 4 + 6 plus one inter-column border');

        $pinned = $t->withWidth(30);
        $this->assertSame(30, $pinned->totalWidth(), 'a pinned frame is honoured exactly');
    }
}
