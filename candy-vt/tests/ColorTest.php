<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Color\Color;

final class ColorTest extends TestCase
{
    public function testDefault(): void
    {
        $c = Color::default();
        $this->assertSame(0, $c->kind);
        $this->assertSame(0, $c->value);
    }

    public function testIndexed16Range(): void
    {
        $c = Color::indexed16(5);
        $this->assertSame(1, $c->kind);
        $this->assertSame(5, $c->value);
    }

    public function testIndexed16OutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::indexed16(16);
    }

    public function testIndexed256Range(): void
    {
        $c = Color::indexed256(200);
        $this->assertSame(2, $c->kind);
        $this->assertSame(200, $c->value);
    }

    public function testIndexed256OutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::indexed256(256);
    }

    public function testTruecolorComponents(): void
    {
        $c = Color::truecolor(255, 128, 0);
        $this->assertSame(3, $c->kind);
        $this->assertSame(0xFF8000, $c->value);
        $this->assertSame(255, $c->red());
        $this->assertSame(128, $c->green());
        $this->assertSame(0, $c->blue());
    }

    public function testTruecolorOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::truecolor(256, 0, 0);
    }

    public function testEquals(): void
    {
        $a = Color::indexed16(1);
        $b = Color::indexed16(1);
        $c = Color::indexed16(2);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
