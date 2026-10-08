<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\SizeError;
use SugarCraft\Top\View\Symbols;
use SugarCraft\Top\View\Units;

final class MiscViewTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    public function testSizeErrorLayoutMatchesBtop(): void
    {
        $s = SizeError::surface(50, 20, 80, 24);
        $rows = $s->plainLines();
        $this->assertCount(20, $rows);
        // btop rows h/2-2, h/2-1, h/2+1, h/2+2 (1-based) -> 7, 8, 10, 11.
        $this->assertSame('             Terminal size too small:', rtrim($rows[7]));
        $this->assertSame('               Width = 50 Height = 20', rtrim($rows[8]));
        $this->assertSame('            Needed for current config:', rtrim($rows[10]));
        $this->assertSame('              Width = 80 Height = 24', rtrim($rows[11]));
        foreach ($s->lines() as $line) {
            $this->assertSame(50, Width::string($line));
        }
    }

    public function testSizeErrorColoursTheShortDimension(): void
    {
        $s = SizeError::surface(90, 20, 80, 24);
        // width 90 >= 80 is green, height 20 < 24 is red.
        $row = $s->lines()[8];
        $this->assertStringContainsString(SizeError::GREEN . '9', $row);
        $this->assertStringContainsString(SizeError::RED . '2', $row);
    }

    public function testSizeErrorOnATinyTerminalStillFillsIt(): void
    {
        $lines = SizeError::surface(10, 3, 80, 24)->lines();
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame(10, Width::string($line));
        }
    }

    public function testUnits(): void
    {
        $this->assertSame('512 Byte', Units::bytes(512));
        $this->assertSame('1.50 KiB', Units::bytes(1536));
        $this->assertSame('16.0 GiB', Units::bytes(16 * 1024 ** 3));
        $this->assertSame('300 MiB/s', Units::bytes(300 * 1024 ** 2, true));
        $this->assertSame('0 Byte', Units::bytes(-5));
    }

    public function testSymbolsNumber(): void
    {
        $this->assertSame('¹', Symbols::number(1, false));
        $this->assertSame('1', Symbols::number(1, true));
        $this->assertSame('⁹', Symbols::number(42, false));
    }

    public function testRectGeometry(): void
    {
        $r = Rect::new(2, 3, 4, 5);
        $this->assertSame(6, $r->right());
        $this->assertSame(8, $r->bottom());
        $this->assertTrue($r->contains(5, 7));
        $this->assertFalse($r->contains(6, 7));
        $this->assertTrue($r->intersect(Rect::new(10, 10, 2, 2))->isEmpty());
        $this->assertTrue(Rect::new(3, 4, 3, 4)->equals($r->intersect(Rect::new(3, 4, 10, 10))));
        $this->assertSame(0, Rect::new(0, 0, -3, 1)->width);
    }
}
