<?php

declare(strict_types=1);

namespace CandyCore\Gloss\Tests;

use CandyCore\Gloss\Align;
use CandyCore\Gloss\Border;
use CandyCore\Gloss\Table\Table;
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
}
