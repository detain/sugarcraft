<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Style;

final class CellTest extends TestCase
{
    public function testNewDefault(): void
    {
        $cell = Cell::new();

        $this->assertSame(' ', $cell->rune());
        $this->assertNull($cell->style());
        $this->assertNull($cell->link());
        $this->assertSame(1, $cell->width());
    }

    public function testNewWithRune(): void
    {
        $cell = Cell::new('x');

        $this->assertSame('x', $cell->rune());
        $this->assertSame(1, $cell->width());
    }

    public function testNewWithStyle(): void
    {
        $style = Style::bold();
        $cell = Cell::new('x', $style);

        $this->assertSame($style, $cell->style());
    }

    public function testNewWithHyperlink(): void
    {
        $link = Hyperlink::new('https://example.com', 'id1');
        $cell = Cell::new('x', null, $link);

        $this->assertSame($link, $cell->link());
        $this->assertSame('https://example.com', $cell->link()->url());
        $this->assertSame('id1', $cell->link()->id());
    }

    public function testNewWithExplicitWidth(): void
    {
        $cell = Cell::new('x', null, null, 2);

        $this->assertSame(2, $cell->width());
    }

    public function testContinuationCell(): void
    {
        $cell = Cell::continuation();

        $this->assertSame('', $cell->rune());
        $this->assertNull($cell->style());
        $this->assertNull($cell->link());
        $this->assertSame(0, $cell->width());
    }

    public function testWideCharCell(): void
    {
        // CJK character '中' has display width 2
        $cell = Cell::new('中', null, null, 2);

        $this->assertSame('中', $cell->rune());
        $this->assertSame(2, $cell->width());
    }

    public function testStyleEquality(): void
    {
        $style1 = Style::new(0xff0000, 0x000000, Style::ATTR_BOLD);
        $style2 = Style::new(0xff0000, 0x000000, Style::ATTR_BOLD);
        $cell1 = Cell::new('x', $style1);
        $cell2 = Cell::new('x', $style2);

        $this->assertEquals($style1, $style2);
    }

    public function testEqualsConsidersRuneStyleLinkWidth(): void
    {
        $style = Style::bold();
        $link = Hyperlink::new('https://example.com');

        $cell1 = Cell::new('x', $style, $link, 1);
        $cell2 = Cell::new('x', $style, $link, 1);
        $this->assertTrue($cell1->equals($cell2));

        // Rune differs
        $cell3 = Cell::new('y', $style, $link, 1);
        $this->assertFalse($cell1->equals($cell3));

        // Style differs
        $cell4 = Cell::new('x', Style::new(null, null, Style::ATTR_ITALIC), $link, 1);
        $this->assertFalse($cell1->equals($cell4));

        // Link differs
        $cell5 = Cell::new('x', $style, Hyperlink::new('https://other.com'), 1);
        $this->assertFalse($cell1->equals($cell5));

        // Width differs — the key divergence from DiffOptimiser::cellsEqual
        $cell6 = Cell::new('x', $style, $link, 2);
        $this->assertFalse($cell1->equals($cell6));
    }
}
