<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Sgr\Sgr;

final class CellTest extends TestCase
{
    public function testEmptyCell(): void
    {
        $c = Cell::empty();
        $this->assertSame(' ', $c->grapheme);
        $this->assertFalse($c->continuation);
        $this->assertNull($c->hyperlink);
    }

    public function testConstructor(): void
    {
        $sgr = Sgr::empty()->withBold(true)->withForeground(Color::indexed16(1));
        $hl = Hyperlink::fromRaw('id1', 'https://example.com');
        $c = new Cell(grapheme: 'X', sgr: $sgr, continuation: false, hyperlink: $hl);

        $this->assertSame('X', $c->grapheme);
        $this->assertTrue($c->sgr()->bold);
        $this->assertFalse($c->continuation);
        $this->assertNotNull($c->hyperlink);
        $this->assertSame('id1', $c->hyperlink->id);
    }

    public function testContinuation(): void
    {
        $prev = new Cell(grapheme: '世', sgr: Sgr::empty()->withForeground(Color::indexed16(2)));
        $cont = Cell::continuation($prev);

        $this->assertSame('', $cont->grapheme);
        $this->assertTrue($cont->continuation);
        $this->assertTrue($cont->sgr()->foreground?->equals(Color::indexed16(2)) ?? false);
    }

    public function testSgrFallsBackToEmpty(): void
    {
        $c = new Cell(grapheme: 'A');
        $this->assertSame(Sgr::class, $c->sgr()::class);
    }

    public function testForeground(): void
    {
        $fg = Color::indexed16(4);
        $sgr = Sgr::empty()->withForeground($fg);
        $c = new Cell(grapheme: 'B', sgr: $sgr);
        $this->assertTrue($c->foreground()?->equals($fg) ?? false);
    }

    public function testBackground(): void
    {
        $bg = Color::truecolor(10, 20, 30);
        $sgr = Sgr::empty()->withBackground($bg);
        $c = new Cell(grapheme: 'C', sgr: $sgr);
        $this->assertTrue($c->background()?->equals($bg) ?? false);
    }

    public function testEqualsFalseOnGrapheme(): void
    {
        $a = new Cell(grapheme: 'A');
        $b = new Cell(grapheme: 'B');
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseOnContinuation(): void
    {
        $a = new Cell(grapheme: 'A', continuation: false);
        $b = new Cell(grapheme: 'A', continuation: true);
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsTrue(): void
    {
        $sgr = Sgr::empty()->withBold(true);
        $a = new Cell(grapheme: 'X', sgr: $sgr);
        $b = new Cell(grapheme: 'X', sgr: $sgr);
        $this->assertTrue($a->equals($b));
    }
}
