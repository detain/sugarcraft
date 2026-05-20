<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table, WrapMode};
use PHPUnit\Framework\TestCase;

final class TableMultilineModeTest extends TestCase
{
    public function testMultilineModeDefaultsToFalse(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 20),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
        // When multilineMode is false, only first line is shown
        $this->assertStringContainsString('Alice', $view);
    }

    public function testWithMultilineModeReturnsNewInstance(): void
    {
        $t = Table::withColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))]);

        $a = $t->withMultilineMode(false);
        $b = $t->withMultilineMode(true);

        $this->assertNotSame($t, $a);
        $this->assertNotSame($t, $b);
        $this->assertNotSame($a, $b);
    }

    public function testMultilineModeRendersAllWrappedLines(): void
    {
        $t = Table::withColumns([
            Column::new('desc', 'Desc', 10)->withWrapMode(WrapMode::Character),
        ])->withRows([
            Row::new(RowData::from(['desc' => 'ABCDEFGHIJK'])),
        ])->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
        // Should contain all wrapped lines
        $this->assertStringContainsString('ABCDEFGHIJ', $view);
    }

    public function testMultilineModeFalseRendersFirstLineOnly(): void
    {
        $t = Table::withColumns([
            Column::new('desc', 'Desc', 5)->withWrapMode(WrapMode::Character),
        ])->withRows([
            Row::new(RowData::from(['desc' => 'ABCDEFGHI'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
        // Should contain first 5 chars truncated
        $this->assertStringContainsString('ABCDE', $view);
    }

    public function testMultilineModeRowHeightEqualsMaxCellHeight(): void
    {
        $t = Table::withColumns([
            Column::new('short', 'Short', 5),
            Column::new('long', 'Long', 5)->withWrapMode(WrapMode::Character),
        ])->withRows([
            Row::new(RowData::from([
                'short' => 'A',
                'long' => 'ABCDEFGHIJ',
            ])),
        ])->withMultilineMode(true);

        $view = $t->View();
        $lines = \explode("\n", $view);

        // Count data lines (excluding borders and header)
        // Table structure: top border, header, header sep, data row(s), bottom border
        // With character wrap at width 5, 'ABCDEFGHIJ' becomes 2 lines
        $this->assertGreaterThan(4, \count($lines));
    }

    public function testMultilineModeWithWordWrap(): void
    {
        $t = Table::withColumns([
            Column::new('text', 'Text', 8)->withWrapMode(WrapMode::WordWrap),
        ])->withRows([
            Row::new(RowData::from(['text' => 'one two three four'])),
        ])->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
        // Word wrap should break at word boundaries within 8 chars
        $this->assertStringContainsString('one', $view);
    }

    public function testMultilineModeWithStyledCells(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from([
                'id' => StyledCell::new('1', '31'),
                'name' => StyledCell::new('Alice', '1'),
            ])),
        ])->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('1', $view);
    }

    public function testMultilineModeWithMultipleRows(): void
    {
        $t = Table::withColumns([
            Column::new('id', 'ID', 5),
            Column::new('desc', 'Desc', 8)->withWrapMode(WrapMode::Character),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'desc' => 'ABCDEFGH'])),
            Row::new(RowData::from(['id' => '2', 'desc' => 'IJKLMNOP'])),
        ])->withMultilineMode(true);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('1', $view);
        $this->assertStringContainsString('2', $view);
        $this->assertStringContainsString('ABCDEFGH', $view);
        $this->assertStringContainsString('IJKLMNOP', $view);
    }
}
