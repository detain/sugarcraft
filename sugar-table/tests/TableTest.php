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
        return Table::fromColumns([
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
        $t = Table::fromColumns([
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])->withSelectedIndex(3);
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
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10);

        $this->assertSame('Page 1 of 1', $t->PageFooter());
    }

    public function testMissingDataIndicator(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5), Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['id' => '1'])),  // no 'name'
            ])
            ->withMissingIndicator('<missing>');

        $view = $t->View();
        $this->assertStringContainsString('<missing>', $view);
    }

    public function testStyledCellOverridesColumnStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
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
        $t = Table::fromColumns([Column::new('val', 'Val', 12)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withShowHeader(false);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringNotContainsString('ID', $view);
    }

    public function testHideFooter(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withPageSize(10)
            ->withShowFooter(false);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testViewportScrollBeyondRows(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->withViewportHeight(5)
            ->withScrollY(10);

        $view = $t->View();
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

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testEmptyTableReturnsEmptyString(): void
    {
        $t = Table::fromColumns([]);
        $this->assertSame('', $t->View());
    }

    public function testColor256Foreground(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;5;196');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColor256Background(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;21');

        $view = $t->View();
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
            ->withStyleFunc(fn(int $row) => $row === 0 ? $redStyle : Style::new());

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testStyleFuncWithFgBgAndAttr(): void
    {
        $styled = Style::new(0x00ff00, 0x0000aa, Style::ATTR_BOLD | Style::ATTR_UNDERLINE);
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withStyleFunc(fn() => $styled);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testMultilineMode(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => "line1\nline2"]))])
            ->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithRgbColor(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('38;2;255;128;0');

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testBorderStyleWithBrightColor(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('1;91');

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString("\x1b[", $view);
    }

    public function testTableBaseStyle(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBaseStyle('1;32');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testSelectableFalse(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withSelectable(false);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testSelectPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Dynamic)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 10)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Content)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 20)->withColumnWidth(\SugarCraft\Table\ColumnWidth::Percent, 25.0)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(1, $widths);
        $this->assertGreaterThan(0, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testHeaderStyleCustom(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withHeaderStyle('1;4;34');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testFilterWithNoMatchingRows(): void
    {
        $t = Table::fromColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->Filter('name', 'xyz');

        $this->assertSame(0, $t->TotalRows());
    }

    public function testSortByNumeric(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))])
            ->addRows([
                Row::new(RowData::from(['id' => '2'])),
                Row::new(RowData::from(['id' => '3'])),
            ]);

        $this->assertSame(3, $t->TotalRows());
    }

    public function testBorderStyleWithBg256Grayscale(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;244');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testBorderStyleWithBrightBg(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => 'X']))])
            ->withBorderStyle('48;5;105');

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWithStyle(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withStyle('1;31'),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testColumnWithAlignLeft(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withAlignLeft(),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    public function testFilterCaseInsensitive(): void
    {
        $t = Table::fromColumns([Column::new('name', 'Name', 10)])
            ->withRows([
                Row::new(RowData::from(['name' => 'Alice'])),
                Row::new(RowData::from(['name' => 'Bob'])),
            ])
            ->Filter('name', 'ALI');

        $this->assertSame(1, $t->TotalRows());
    }

    public function testZeroPageSizeMeansNoPagination(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
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
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([]);

        $this->assertSame(0, $t->TotalRows());
    }

    public function testRowsFooterFirstPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 1 to 25 of 100 rows', $t->RowsFooter());
    }

    public function testRowsFooterSecondPage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(1)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 26 to 50 of 100 rows', $t->RowsFooter());
    }

    public function testRowsFooterLastPagePartial(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(3)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        // Page 3: rows 76-100
        $this->assertSame('Showing 76 to 100 of 100 rows', $t->RowsFooter());
    }

    public function testRowsFooterEmptyTable(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([])
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 0 to 0 of 0 rows', $t->RowsFooter());
    }

    public function testRowsFooterSinglePage(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 10)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $this->assertSame('Showing 1 to 10 of 10 rows', $t->RowsFooter());
    }

    public function testFooterTypePageMode(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10)
            ->withFooterType(\SugarCraft\Table\FooterType::Page);

        $this->assertSame('Page 1 of 1', $t->PageFooter());
        $this->assertSame('Showing 1 to 1 of 1 rows', $t->RowsFooter());
    }

    public function testFooterTypeImmutability(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 5)])
            ->withRows([Row::new(RowData::from(['n' => '1']))])
            ->withPageSize(10);

        $t2 = $t->withFooterType(\SugarCraft\Table\FooterType::Rows);
        $this->assertNotSame($t, $t2);
        // Original should still have default Page footer type
        $this->assertStringContainsString('Page', $t->View());
    }

    public function testFooterTypeBothRendersCombined(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 50)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 100)
                )
            )
            ->withPageSize(25)
            ->withPage(1)
            ->withFooterType(\SugarCraft\Table\FooterType::Both);

        $view = $t->View();
        $this->assertStringContainsString('Page 2 of 4', $view);
        $this->assertStringContainsString('Showing 26 to 50 of 100 rows', $view);
    }

    public function testFooterTypeRowsRendersCorrectly(): void
    {
        $t = Table::fromColumns([Column::new('n', 'N', 30)])
            ->withRows(
                \array_map(
                    fn($i) => Row::new(RowData::from(['n' => (string) $i])),
                    \range(1, 25)
                )
            )
            ->withPageSize(25)
            ->withFooterType(\SugarCraft\Table\FooterType::Rows);

        $view = $t->View();
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
        $this->assertSame(3, $t->TotalRows()); // no exception
    }

    public function testWithHiddenColsNonOverlappingIsAllowed(): void
    {
        $t = $this->makeTable()->withHiddenCols([0])->withFrozenCols([1]);
        $this->assertSame(3, $t->TotalRows()); // no exception
    }

    // -------------------------------------------------------------------------
    // Item 5.2 — Filter/SortBy invalid column key validation
    // -------------------------------------------------------------------------

    public function testSortByThrowsOnInvalidColumnKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        $this->makeTable()->SortBy('nonexistent_key');
    }

    public function testFilterThrowsOnInvalidColumnKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/i');
        $this->makeTable()->Filter('invalid_key', 'text');
    }

    public function testSortByWithValidKeyDoesNotThrow(): void
    {
        $t = $this->makeTable()->SortBy('name');
        $this->assertSame('Alice', $t->CurrentRowData()?->get('name'));
    }

    public function testFilterWithValidKeyDoesNotThrow(): void
    {
        $t = $this->makeTable()->Filter('name', 'ali');
        $this->assertSame(1, $t->TotalRows());
    }
}
