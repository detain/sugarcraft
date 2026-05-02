<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Sprinkles\Align;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;
use CandyCore\Sprinkles\Table\Table;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    public function testEmptyTableRendersEmpty(): void
    {
        $this->assertSame('', Table::new()->render());
    }

    public function testHeadersAndRowsWithNormalBorder(): void
    {
        $out = Table::new()
            ->headers('Name', 'Age')
            ->row('Alice', '30')
            ->row('Bob',   '25')
            ->border(Border::normal())
            ->render();

        $expected =
            "┌───────┬─────┐\n"
          . "│ Name  │ Age │\n"
          . "├───────┼─────┤\n"
          . "│ Alice │ 30  │\n"
          . "│ Bob   │ 25  │\n"
          . "└───────┴─────┘";
        $this->assertSame($expected, $out);
    }

    public function testRoundedBorderUsesRoundedCorners(): void
    {
        $out = Table::new()
            ->row('a')
            ->border(Border::rounded())
            ->render();
        $this->assertStringStartsWith('╭', $out);
        $this->assertStringContainsString('╮', $out);
        $this->assertStringContainsString('╰', $out);
        $this->assertStringContainsString('╯', $out);
    }

    public function testNoBorderUsesTwoSpaceColumnGap(): void
    {
        $out = Table::new()
            ->row('Alice', '30')
            ->row('Bob',   '25')
            ->render();
        $expected =
            "Alice  30\n"
          . "Bob    25";
        $this->assertSame($expected, $out);
    }

    public function testHeaderAlignCenter(): void
    {
        $out = Table::new()
            ->headers('Age')
            ->row('100')
            ->headerAlign(Align::Center)
            ->border(Border::normal())
            ->render();
        // Width = 3 ("100"). Header "Age" centered in 3 → "Age" (no extra).
        // 4-cell row width still 3.
        $this->assertStringContainsString('│ Age │', $out);
        $this->assertStringContainsString('│ 100 │', $out);
    }

    public function testRowAlignRight(): void
    {
        $out = Table::new()
            ->row('a')
            ->row('bbb')
            ->rowAlign(Align::Right)
            ->render();
        // Col width = 3. 'a' right-aligned → "  a", 'bbb' → "bbb".
        $expected = "  a\nbbb";
        $this->assertSame($expected, $out);
    }

    public function testJaggedRowsArePadded(): void
    {
        $out = Table::new()
            ->row('a', 'b', 'c')
            ->row('x') // missing 2 cells
            ->border(Border::normal())
            ->render();

        // Should not throw, all rows have same column count, missing cells empty.
        $this->assertStringContainsString('│ x │', $out);
    }

    public function testStyleFuncIsCalledForEachCell(): void
    {
        $calls = [];
        Table::new()
            ->headers('A', 'B')
            ->row('1', '2')
            ->styleFunc(function (int $row, int $col) use (&$calls): Style {
                $calls[] = [$row, $col];
                return Style::new();
            })
            ->render();
        $this->assertContains([Table::HEADER_ROW, 0], $calls);
        $this->assertContains([Table::HEADER_ROW, 1], $calls);
        $this->assertContains([0, 0], $calls);
        $this->assertContains([0, 1], $calls);
    }

    public function testStyleFuncStylesHeaderDifferently(): void
    {
        $out = Table::new()
            ->headers('Name')
            ->row('Alice')
            ->styleFunc(function (int $row): Style {
                return $row === Table::HEADER_ROW
                    ? Style::new()->bold()->colorProfile(ColorProfile::Ansi)
                    : Style::new();
            })
            ->render();
        // Header line should contain bold SGR, body line should not.
        $lines = explode("\n", $out);
        $this->assertStringContainsString("\x1b[1m", $lines[0]);
        $this->assertStringNotContainsString("\x1b[1m", $lines[1]);
    }

    public function testBorderHeaderToggleHidesSeparator(): void
    {
        $with = Table::new()
            ->headers('A')
            ->row('1')
            ->border(Border::normal())
            ->render();
        $without = Table::new()
            ->headers('A')
            ->row('1')
            ->border(Border::normal())
            ->borderHeader(false)
            ->render();
        $this->assertStringContainsString('├', $with);
        $this->assertStringNotContainsString('├', $without);
    }

    public function testBorderRowAddsSeparatorBetweenRows(): void
    {
        $out = Table::new()
            ->row('1')
            ->row('2')
            ->border(Border::normal())
            ->borderRow(true)
            ->render();
        $this->assertStringContainsString('├', $out);
    }

    public function testBorderColumnOffJoinsCellsWithoutSeparator(): void
    {
        $out = Table::new()
            ->row('A', 'B')
            ->border(Border::normal())
            ->borderColumn(false)
            ->render();
        // No interior │ between A and B
        $lines = explode("\n", $out);
        // Body line: │ A  B │
        $this->assertStringNotContainsString('│ A │ B │', $lines[1]);
        $this->assertStringContainsString('│', $lines[1]);
    }

    public function testOffsetSkipsRows(): void
    {
        $out = Table::new()
            ->row('keep-me-out')
            ->row('shown')
            ->offset(1)
            ->render();
        $this->assertStringNotContainsString('keep-me-out', $out);
        $this->assertStringContainsString('shown', $out);
    }

    public function testWidthCapTruncates(): void
    {
        $out = Table::new()
            ->row('this is a long row')
            ->width(8)
            ->render();
        $this->assertSame('this is ', $out);
    }
}
