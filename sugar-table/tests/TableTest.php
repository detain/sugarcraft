<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Buffer\Style;
use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table};
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function makeTable(): Table
    {
        return Table::withColumns([
            Column::new('id',   'ID',     5),
            Column::new('name', 'Name',  20),
            Column::new('city', 'City',  15),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'NYC'])),
            Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'LA'])),
            Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'CHI'])),
        ]);
    }

    public function testNew(): void
    {
        $t = $this->makeTable();
        $this->assertSame(3, \count($t->Columns()));
        $this->assertSame(3, $t->TotalRows());
    }

    public function testAddRow(): void
    {
        $t = $this->makeTable();
        $t = $t->addRow(Row::new(RowData::from(['id' => '4', 'name' => 'Dave', 'city' => 'HOU'])));
        $this->assertSame(4, $t->TotalRows());
    }

    public function testSortByAscending(): void
    {
        $t = $this->makeTable()->SortBy('name', ascending: true);
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
        $this->assertSame('Bob',   $t->pagedRows()[1]->data->get('name'));
    }

    public function testSortByDescending(): void
    {
        $t = $this->makeTable()->SortBy('name', ascending: false);
        $this->assertSame('Carol', $t->pagedRows()[0]->data->get('name'));
    }

    public function testSortToggle(): void
    {
        $t = $this->makeTable()->SortBy('name', true);
        $t = $t->SortBy('name', true);  // same key, should toggle
        $this->assertSame('Carol', $t->filteredSortedRows()[0]->data->get('name'));
    }

    public function testFilter(): void
    {
        $t = $this->makeTable()->Filter('name', 'ali');
        $this->assertSame(1, $t->TotalRows());
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    public function testFilterClear(): void
    {
        $t = $this->makeTable()->Filter('name', 'ali');
        $t = $t->ClearFilter('name');
        $this->assertSame(3, $t->TotalRows());
    }

    public function testFilterMultipleColumns(): void
    {
        $t = Table::withColumns([
            Column::new('name', 'Name', 10),
            Column::new('city', 'City', 10),
        ])->withRows([
            Row::new(RowData::from(['name' => 'Alice', 'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Bob',   'city' => 'NYC'])),
            Row::new(RowData::from(['name' => 'Carol', 'city' => 'LA'])),
        ])->Filter('name', 'a')
          ->Filter('city', 'NYC');

        $this->assertSame(1, $t->TotalRows());
    }

    public function testSelectNext(): void
    {
        $t = $this->makeTable()->SelectNext();
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    public function testSelectPrevious(): void
    {
        $t = $this->makeTable()->SelectNext()->SelectNext()->SelectPrevious();
        $this->assertSame('Bob', $t->CurrentRowData()?->get('name'));
    }

    public function testSelectNextClampsAtEnd(): void
    {
        $t = $this->makeTable();
        for ($i = 0; $i < 20; $i++) {
            $t = $t->SelectNext();
        }
        $this->assertSame('Carol', $t->CurrentRowData()?->get('name'));
    }

    public function testWithSelectedIndexMovesCursor(): void
    {
        $t = $this->makeTable()->withSelectedIndex(2);
        $this->assertSame(2, $t->SelectedIndex());
        $this->assertSame('Carol', $t->CurrentRowData()?->get('name'));
    }

    public function testWithSelectedIndexClamps(): void
    {
        $t = $this->makeTable();
        $this->assertSame(2, $t->withSelectedIndex(99)->SelectedIndex());
        $this->assertSame(0, $t->withSelectedIndex(-5)->SelectedIndex());
    }

    public function testWithSelectedIndexNoOpOnEmpty(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])->withSelectedIndex(3);
        $this->assertSame(0, $t->SelectedIndex());
    }

    public function testWithSelectedIndexImmutable(): void
    {
        $t = $this->makeTable();
        $this->assertNotSame($t, $t->withSelectedIndex(1));
        $this->assertSame(0, $t->SelectedIndex());
    }

    public function testPagination(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(0, 49)
                )
            )
            ->withPageSize(10)
            ->withPage(2);  // 0-indexed page 2 = rows 20-29

        $this->assertSame(5, $t->TotalPages());
        $this->assertSame('20', $t->CurrentRowData()?->get('n'));
    }

    public function testNextPage(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->NextPage();

        $this->assertSame('11', $t->CurrentRowData()?->get('n'));
    }

    public function testPageFooter(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10);

        $this->assertSame('Page 1 of 1', $t->PageFooter());
    }

    public function testMissingDataIndicator(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5), Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),  // no 'name'
            ])
            ->withMissingIndicator('<missing>');

        $view = $t->View();
        $this->assertStringContainsString('<missing>', $view);
    }

    public function testStyledCellOverridesColumnStyle(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => StyledCell::new('X', '1;31')])),
            ]);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('X', $view);
    }

    public function testZebraStriping(): void
    {
        $t = $this->makeTable()->withZebra();
        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('Alice', $view);
    }

    public function testFrozenCols(): void
    {
        $t = $this->makeTable()->withFrozenCols([0]);
        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('ID', $view);
    }

    public function testHorizontalScroll(): void
    {
        $t = $this->makeTable()->withScrollX(5);
        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testRowStyle(): void
    {
        $t = $this->makeTable()
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'X', 'city' => 'Y']))->withStyle('1'),
            ]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testRowWithZebra(): void
    {
        $t = $this->makeTable()
            ->withRows([
                Row::new(RowData::from(['id' => '1', 'name' => 'X', 'city' => 'Y']))->withZebra(),
            ]);

        $this->assertTrue($t->Rows()[0]->zebra);
    }

    public function testClearSort(): void
    {
        $t = $this->makeTable()->SortBy('name', false)->ClearSort();
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    public function testClearAllFilters(): void
    {
        $t = $this->makeTable()
            ->Filter('name', 'ali')
            ->ClearAllFilters();

        $this->assertSame(3, $t->TotalRows());
    }

    public function testViewRendersTopAndBottomBorders(): void
    {
        $t = $this->makeTable();
        $view = $t->View();
        $this->assertStringContainsString('┌', $view);
        $this->assertStringContainsString('└', $view);
        $this->assertStringContainsString('─', $view);
    }

    public function testViewRendersHeader(): void
    {
        $t = $this->makeTable();
        $view = $t->View();
        $this->assertStringContainsString('ID', $view);
        $this->assertStringContainsString('Name', $view);
        $this->assertStringContainsString('City', $view);
    }

    public function testImmutability(): void
    {
        $a = $this->makeTable();
        $b = $a->SortBy('name', false);
        $this->assertNotSame($a, $b);
        $this->assertSame(3, $a->TotalRows());
    }

    public function testStyleFuncWithStringReturn(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),
                Row::new(RowData::from(['id' => '2'])),
            ])
            ->withStyleFunc(fn(int $row) => $row === 0 ? '1;31' : '');

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('1', $view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testStyleFuncWithStyleReturn(): void
    {
        $redStyle = Style::new(0xff0000);
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => 'X'])),
            ])
            ->withStyleFunc(fn(int $row) => $redStyle);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testWideCharColumnLayout(): void
    {
        $t = Table::withColumns([Column::new('val', 'Val', 12)])
            ->withRows([
                Row::new(RowData::from(['val' => 'short'])),
                Row::new(RowData::from(['val' => '中文'])),
                Row::new(RowData::from(['val' => 'longer label'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThanOrEqual(4, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('short', $view);
        $this->assertStringContainsString('longer label', $view);
    }

    public function testHideHeader(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withShowHeader(false);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringNotContainsString('ID', $view);
    }

    public function testHideFooter(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withPageSize(10)
            ->withShowFooter(false);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testViewportScrollBeyondRows(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withViewportHeight(5)
            ->withScrollY(10);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testViewportScrollWithinRows(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ])
            ->withViewportHeight(2)
            ->withScrollY(1);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testEmptyTableReturnsEmptyString(): void
    {
        $t = Table::withColumns([]);
        $this->assertSame('', $t->View());
    }

    public function testColor256Foreground(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;5;196');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColor256Background(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;21');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testStyleFuncWithFgColor(): void
    {
        $redStyle = Style::new(0xff0000);
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([
                Row::new(RowData::from(['id' => 'A'])),
                Row::new(RowData::from(['id' => 'B'])),
            ])
            ->withStyleFunc(fn(int $row) => $row === 0 ? $redStyle : Style::new());

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testStyleFuncWithFgBgAndAttr(): void
    {
        $styled = Style::new(0x00ff00, 0x0000aa, Style::ATTR_BOLD | Style::ATTR_UNDERLINE);
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withStyleFunc(fn() => $styled);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testMultilineMode(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => "line1\nline2"]))])
            ->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithRgbColor(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;2;255;128;0');

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testBorderStyleWithBrightColor(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('1;91');

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testTableBaseStyle(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBaseStyle('1;32');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testSelectableFalse(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withSelectable(false);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testSelectPage(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->SelectPage(2);

        $this->assertSame(2, $t->CurrentPage());
    }

    public function testPreviousPage(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(10)
            ->SelectPage(2)
            ->PreviousPage();

        $this->assertSame(1, $t->CurrentPage());
    }

    public function testColumnWidthDynamicWithContent(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Dynamic)])
            ->withRows([
                Row::new(RowData::from(['id' => 'tiny'])),
                Row::new(RowData::from(['id' => 'this is a longer value'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThanOrEqual(5, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWidthContentType(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Content)])
            ->withRows([
                Row::new(RowData::from(['id' => 'short'])),
            ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWidthPercent(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 20)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Percent, 25.0)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThan(0, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testHeaderStyleCustom(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withHeaderStyle('1;4;34');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testFilterWithNoMatchingRows(): void
    {
        $t = Table::withColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->Filter('name', 'xyz');

        $this->assertSame(0, $t->TotalRows());
    }

    public function testSortByNumeric(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows([
                Row::new(RowData::from(['n' => '10'])),
                Row::new(RowData::from(['n' => '2'])),
                Row::new(RowData::from(['n' => '100'])),
            ])
            ->SortBy('n', true);

        $rows = $t->filteredSortedRows();
        $this->assertSame('2', $rows[0]->data->get('n'));
    }

    public function testSortByNumericDescending(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows([
                Row::new(RowData::from(['n' => '10'])),
                Row::new(RowData::from(['n' => '2'])),
                Row::new(RowData::from(['n' => '100'])),
            ])
            ->SortBy('n', false);

        $rows = $t->filteredSortedRows();
        $this->assertSame('100', $rows[0]->data->get('n'));
    }

    public function testAddRows(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->addRows([
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ]);

        $this->assertSame(3, $t->TotalRows());
    }

    public function testBorderStyleWithBg256Grayscale(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;244');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithBrightBg(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;105');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWithStyle(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5)->withStyle('1;31'),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWithAlignLeft(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5)->withAlignLeft(),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testFilterCaseInsensitive(): void
    {
        $t = Table::withColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->Filter('name', 'ALI');

        $this->assertSame(1, $t->TotalRows());
    }

    public function testZeroPageSizeMeansNoPagination(): void
    {
        $t = Table::withColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 30)
                )
            )
            ->withPageSize(0);

        $this->assertSame(1, $t->TotalPages());
    }

    public function testTotalRowsWithNoRows(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([]);

        $this->assertSame(0, $t->TotalRows());
    }
}
