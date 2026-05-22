<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    public function testHexLong(): void
    {
        $c = Color::hex('#ff8000');
        $this->assertSame(255, $c->r);
        $this->assertSame(128, $c->g);
        $this->assertSame(0, $c->b);
    }

    public function testHexShort(): void
    {
        $c = Color::hex('#f80');
        $this->assertSame(255, $c->r);
        $this->assertSame(136, $c->g);
        $this->assertSame(0, $c->b);
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
        $this->assertSame(0, $first->r);
        $this->assertSame(238, $last->r);
        $this->assertSame(238, $last->g);
        $this->assertSame(238, $last->b);
    }

    public function testAnsi256CubeMidpoint(): void
    {
        $c = Color::ansi256(124);
        $this->assertSame(175, $c->r);
        $this->assertSame(0, $c->g);
        $this->assertSame(0, $c->b);
    }

    public function testNeutralGrayPrefersGrayscaleRamp(): void
    {
        // 128/128/128 is closer to gray index 244 (138/138/138) than any
        // 6×6×6 cube colour. Without the grayscale ramp the downsampler
        // mapped this to a less accurate cube value.
        $sgr = Color::rgb(128, 128, 128)->toFg(ColorProfile::Ansi256);
        $this->assertSame("\x1b[38;5;244m", $sgr);
    }

    public function testSaturatedColorStillUsesCube(): void
    {
        // Pure red has no grayscale equivalent — must stay in the cube.
        $sgr = Color::rgb(255, 0, 0)->toFg(ColorProfile::Ansi256);
        $this->assertSame("\x1b[38;5;196m", $sgr);
    }

    public function testHsl(): void
    {
        // Pure red: hue=0, sat=1, lightness=0.5
        $c = Color::hsl(0.0, 1.0, 0.5);
        $this->assertSame(255, $c->r);
        $this->assertSame(0, $c->g);
        $this->assertSame(0, $c->b);
        // Pure white
        $w = Color::hsl(0.0, 0.0, 1.0);
        $this->assertSame(255, $w->r);
        $this->assertSame(255, $w->g);
        $this->assertSame(255, $w->b);
    }

    public function testHsv(): void
    {
        // Pure green: hue=120, sat=1, value=1
        $c = Color::hsv(120.0, 1.0, 1.0);
        $this->assertSame(0, $c->r);
        $this->assertSame(255, $c->g);
        $this->assertSame(0, $c->b);
    }

    public function testToHslRoundTrip(): void
    {
        $orig = Color::rgb(128, 200, 50);
        [$h, $s, $l] = $orig->toHsl();
        $back = Color::hsl($h, $s, $l);
        $this->assertEqualsWithDelta(128, $back->r, 1.0);
        $this->assertEqualsWithDelta(200, $back->g, 1.0);
        $this->assertEqualsWithDelta(50, $back->b, 1.0);
    }

    public function testLighten(): void
    {
        $c = Color::hex('#808080')->lighten(0.2);
        $this->assertGreaterThan(128, $c->r);
        // Stays grey.
        $this->assertSame($c->r, $c->g);
        $this->assertSame($c->g, $c->b);
    }

    public function testDarken(): void
    {
        $c = Color::hex('#808080')->darken(0.2);
        $this->assertLessThan(128, $c->r);
    }

    public function testAlphaOverBlack(): void
    {
        $c = Color::rgb(255, 255, 255)->alpha(0.5);
        $this->assertEqualsWithDelta(128, $c->r, 1.0);
        $this->assertEqualsWithDelta(128, $c->g, 1.0);
        $this->assertEqualsWithDelta(128, $c->b, 1.0);
    }

    public function testAlphaOverColour(): void
    {
        $fg = Color::rgb(255, 0, 0);
        $bg = Color::rgb(0, 0, 255);
        $c = $fg->alpha(0.5, $bg);
        $this->assertEqualsWithDelta(128, $c->r, 1.0);
        $this->assertSame(0, $c->g);
        $this->assertEqualsWithDelta(128, $c->b, 1.0);
    }

    public function testBlend(): void
    {
        $a = Color::rgb(0, 0, 0);
        $b = Color::rgb(100, 100, 100);
        $mid = $a->blend($b, 0.5);
        $this->assertSame(50, $mid->r);
        $start = $a->blend($b, 0.0);
        $this->assertSame(0, $start->r);
        $end = $a->blend($b, 1.0);
        $this->assertSame(100, $end->r);
    }

    public function testBlend1dEndpoints(): void
    {
        $a = Color::rgb(0, 0, 0);
        $b = Color::rgb(255, 255, 255);
        $stops = $a->blend1d($b, 5);
        $this->assertCount(5, $stops);
        $this->assertSame(0, $stops[0]->r);
        $this->assertSame(255, $stops[4]->r);
    }

    public function testBlend2d(): void
    {
        $tl = Color::rgb(0, 0, 0);
        $tr = Color::rgb(255, 0, 0);
        $bl = Color::rgb(0, 0, 255);
        $br = Color::rgb(255, 0, 255);
        $grid = $tl->blend2d($tr, $bl, $br, 3, 3);
        $this->assertCount(3, $grid);
        $this->assertCount(3, $grid[0]);
        $this->assertSame(0, $grid[0][0]->r);
        $this->assertSame(255, $grid[0][2]->r);
        $this->assertSame(0, $grid[2][0]->r);
        $this->assertSame(255, $grid[2][2]->r);
    }

    public function testComplementary(): void
    {
        // Red → cyan
        $c = Color::rgb(255, 0, 0)->complementary();
        $this->assertSame(0, $c->r);
        $this->assertEqualsWithDelta(255, $c->g, 1.0);
        $this->assertEqualsWithDelta(255, $c->b, 1.0);
    }

    public function testIsDark(): void
    {
        $this->assertTrue(Color::hex('#000000')->isDark());
        $this->assertFalse(Color::hex('#ffffff')->isDark());
        // Mid-gray (#888) gamma-decodes to ~0.25 luminance — "dark".
        $this->assertTrue(Color::hex('#888888')->isDark());
        // Lighter gray crosses the 0.5 threshold.
        $this->assertFalse(Color::hex('#cccccc')->isDark());
    }

    public function testLuminanceBlackWhite(): void
    {
        $this->assertSame(0.0, Color::rgb(0, 0, 0)->luminance());
        $this->assertEqualsWithDelta(1.0, Color::rgb(255, 255, 255)->luminance(), 1e-9);
    }
}
