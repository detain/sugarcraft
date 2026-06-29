<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Table\{Column, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

final class TableBorderStyleTest extends TestCase
{
    public function testWithBorderReturnsNewInstance(): void
    {
        $t = Table::fromColumns([Column::new('id', 'ID', 5)])
            ->withRows([Row::new(RowData::from(['id' => '1']))]);

        $bordered = $t->withBorder(Border::normal());

        $this->assertNotSame($t, $bordered);
    }

    public function testBorderNormalRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withBorder(Border::normal());

        $view = $t->View();

        // Normal border uses standard box-drawing characters
        $this->assertStringContainsString('┌', $view);  // topLeft
        $this->assertStringContainsString('┐', $view);  // topRight
        $this->assertStringContainsString('└', $view);  // bottomLeft
        $this->assertStringContainsString('┘', $view);  // bottomRight
        $this->assertStringContainsString('─', $view);  // top/bottom
        $this->assertStringContainsString('│', $view);  // left/right
    }

    public function testBorderRoundedRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::rounded());

        $view = $t->View();

        // Rounded border uses rounded corners
        $this->assertStringContainsString('╭', $view);  // topLeft
        $this->assertStringContainsString('╮', $view);  // topRight
        $this->assertStringContainsString('╰', $view);  // bottomLeft
        $this->assertStringContainsString('╯', $view);  // bottomRight
        $this->assertStringContainsString('─', $view);  // top/bottom (same as normal)
        $this->assertStringContainsString('│', $view);  // left/right (same as normal)
    }

    public function testBorderThickRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::thick());

        $view = $t->View();

        // Thick border uses heavy box-drawing characters
        $this->assertStringContainsString('┏', $view);  // topLeft
        $this->assertStringContainsString('┓', $view);  // topRight
        $this->assertStringContainsString('┗', $view);  // bottomLeft
        $this->assertStringContainsString('┛', $view);  // bottomRight
        $this->assertStringContainsString('━', $view);  // top/bottom
        $this->assertStringContainsString('┃', $view);  // left/right
    }

    public function testBorderDoubleRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::double());

        $view = $t->View();

        // Double border uses double-line box-drawing characters
        $this->assertStringContainsString('╔', $view);  // topLeft
        $this->assertStringContainsString('╗', $view);  // topRight
        $this->assertStringContainsString('╚', $view);  // bottomLeft
        $this->assertStringContainsString('╝', $view);  // bottomRight
        $this->assertStringContainsString('═', $view);  // top/bottom
        $this->assertStringContainsString('║', $view);  // left/right
    }

    public function testBorderBlockRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::block());

        $view = $t->View();

        // Block border uses solid block characters
        $this->assertStringContainsString('█', $view);
    }

    public function testBorderAsciiRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::ascii());

        $view = $t->View();

        // ASCII border uses +, -, |
        $this->assertStringContainsString('+', $view);
        $this->assertStringContainsString('-', $view);
        $this->assertStringContainsString('|', $view);
    }

    public function testBorderHiddenRendersOnlySpaces(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::hidden());

        $view = $t->View();

        // Hidden border uses spaces for all border chars
        $this->assertStringContainsString('1', $view);
        // No visible border characters
        $this->assertStringNotContainsString('┌', $view);
        $this->assertStringNotContainsString('─', $view);
        $this->assertStringNotContainsString('|', $view);
    }

    public function testBorderMarkdownBorderRendersCorrectCharacters(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ])->withBorder(Border::markdownBorder());

        $view = $t->View();

        // Markdown border uses | and - for table-style rendering
        $this->assertStringContainsString('|', $view);
        $this->assertStringContainsString('-', $view);
        // But not the standard box-drawing corners
        $this->assertStringNotContainsString('┌', $view);
        $this->assertStringNotContainsString('┐', $view);
    }

    public function testBorderNormalWithHeaderSeparator(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])->withBorder(Border::normal());

        $view = $t->View();
        $lines = \explode("\n", $view);

        // Header separator should use ├ ┤ with ─ between
        // The second line is the header, third is the separator
        $this->assertCount(5, $lines);  // top border, header, sep, data, bottom
    }

    public function testBorderAndBorderStyleCanBothBeApplied(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ])
            ->withBorder(Border::double())
            ->withBorderStyle('1;34');  // bold blue

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('╔', $view);
        $this->assertStringContainsString('═', $view);
    }

    public function testDefaultBorderCharsPreservedWhenNoBorderSet(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
        ])->withRows([
            Row::new(RowData::from(['id' => '1'])),
        ]);
        // No Border set - should use default chars

        $view = $t->View();
        $this->assertStringContainsString('┌', $view);
        $this->assertStringContainsString('─', $view);
        $this->assertStringContainsString('│', $view);
    }
}
