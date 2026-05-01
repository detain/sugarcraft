<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Util;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    public function testHexLong(): void
    {
        $c = Color::hex('#ff8000');
        $this->assertSame(255, $c->r);
        $this->assertSame(128, $c->g);
        $this->assertSame(0,   $c->b);
    }

    public function testHexShort(): void
    {
        $c = Color::hex('#f80');
        $this->assertSame(255, $c->r);
        $this->assertSame(136, $c->g);
        $this->assertSame(0,   $c->b);
    }

    public function testHexRoundTrip(): void
    {
        $this->assertSame('#abcdef', Color::hex('#abcdef')->toHex());
    }

    public function testHexRejectsBogus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::hex('not-a-color');
    }

    public function testRgbRangeCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::rgb(0, 256, 0);
    }

    public function testRenderTrueColorFg(): void
    {
        $sgr = Color::rgb(255, 128, 0)->toFg(ColorProfile::TrueColor);
        $this->assertSame("\x1b[38;2;255;128;0m", $sgr);
    }

    public function testRenderAscii(): void
    {
        $this->assertSame('', Color::rgb(255, 0, 0)->toFg(ColorProfile::Ascii));
        $this->assertSame('', Color::rgb(255, 0, 0)->toBg(ColorProfile::Ascii));
    }

    public function testRender256DownsamplesPureRed(): void
    {
        $sgr = Color::rgb(255, 0, 0)->toFg(ColorProfile::Ansi256);
        $this->assertSame("\x1b[38;5;196m", $sgr);
    }

    public function testRender16DownsamplesPureRedToAnsi9(): void
    {
        $sgr = Color::rgb(255, 0, 0)->toFg(ColorProfile::Ansi);
        $this->assertSame("\x1b[91m", $sgr);
    }

    public function testAnsi256IndexBounds(): void
    {
        $first = Color::ansi256(0);
        $last  = Color::ansi256(255);
        $this->assertSame(0,   $first->r);
        $this->assertSame(238, $last->r);
        $this->assertSame(238, $last->g);
        $this->assertSame(238, $last->b);
    }

    public function testAnsi256CubeMidpoint(): void
    {
        $c = Color::ansi256(124);
        $this->assertSame(175, $c->r);
        $this->assertSame(0,   $c->g);
        $this->assertSame(0,   $c->b);
    }
}
