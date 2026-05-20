<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Hsl;
use PHPUnit\Framework\TestCase;

final class HslTest extends TestCase
{
    public function testColorPureRed(): void
    {
        // H=0 (red), S=100%, L=50%
        $color = Hsl::color(0.0, 100.0, 50.0);
        $this->assertSame(255, $color->r);
        $this->assertLessThan(5, abs($color->g));  // ~0
        $this->assertLessThan(5, abs($color->b));  // ~0
    }

    public function testColorPureGreen(): void
    {
        // H=120 (green), S=100%, L=50%
        $color = Hsl::color(120.0, 100.0, 50.0);
        $this->assertLessThan(5, abs($color->r));  // ~0
        $this->assertSame(255, $color->g);
        $this->assertLessThan(5, abs($color->b));  // ~0
    }

    public function testColorPureBlue(): void
    {
        // H=240 (blue), S=100%, L=50%
        $color = Hsl::color(240.0, 100.0, 50.0);
        $this->assertLessThan(5, abs($color->r));  // ~0
        $this->assertLessThan(5, abs($color->g));  // ~0
        $this->assertSame(255, $color->b);
    }

    public function testColorCyan(): void
    {
        // H=180 (cyan), S=100%, L=50%
        $color = Hsl::color(180.0, 100.0, 50.0);
        $this->assertLessThan(5, abs($color->r));  // ~0
        $this->assertSame(255, $color->g);
        $this->assertSame(255, $color->b);
    }

    public function testColorMagenta(): void
    {
        // H=300 (magenta), S=100%, L=50%
        $color = Hsl::color(300.0, 100.0, 50.0);
        $this->assertSame(255, $color->r);
        $this->assertLessThan(5, abs($color->g));  // ~0
        $this->assertSame(255, $color->b);
    }

    public function testColorYellow(): void
    {
        // H=60 (yellow), S=100%, L=50%
        $color = Hsl::color(60.0, 100.0, 50.0);
        $this->assertSame(255, $color->r);
        $this->assertSame(255, $color->g);
        $this->assertLessThan(5, abs($color->b));  // ~0
    }

    public function testColorWhite(): void
    {
        // H=0, S=0%, L=100%
        $color = Hsl::color(0.0, 0.0, 100.0);
        $this->assertSame(255, $color->r);
        $this->assertSame(255, $color->g);
        $this->assertSame(255, $color->b);
    }

    public function testColorBlack(): void
    {
        // H=0, S=0%, L=0%
        $color = Hsl::color(0.0, 0.0, 0.0);
        $this->assertSame(0, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testColorGray(): void
    {
        // H=0, S=0%, L=50%
        $color = Hsl::color(0.0, 0.0, 50.0);
        // Gray should have equal R, G, B around 128
        $this->assertSame($color->r, $color->g);
        $this->assertSame($color->g, $color->b);
        $this->assertGreaterThan(100, $color->r);
        $this->assertLessThan(160, $color->r);
    }

    public function testColorHueWraps(): void
    {
        // H=360 should equal H=0
        $color0 = Hsl::color(0.0, 100.0, 50.0);
        $color360 = Hsl::color(360.0, 100.0, 50.0);
        $this->assertEquals($color0->r, $color360->r);
        $this->assertEquals($color0->g, $color360->g);
        $this->assertEquals($color0->b, $color360->b);
    }

    public function testColorNegativeHueWraps(): void
    {
        // H=-60 should equal H=300
        $colorNeg = Hsl::color(-60.0, 100.0, 50.0);
        $color300 = Hsl::color(300.0, 100.0, 50.0);
        $this->assertEquals($colorNeg->r, $color300->r);
        $this->assertEquals($colorNeg->g, $color300->g);
        $this->assertEquals($colorNeg->b, $color300->b);
    }

    public function testColorSaturationClamped(): void
    {
        // S=150% should be clamped to 100%
        $color = Hsl::color(0.0, 150.0, 50.0);
        $this->assertSame(255, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testColorSaturationNegativeClamped(): void
    {
        // S=-10% should be clamped to 0%
        $color = Hsl::color(0.0, -10.0, 50.0);
        $this->assertSame($color->r, $color->g);
        $this->assertSame($color->g, $color->b);
    }

    public function testColorLightnessClamped(): void
    {
        // L=150% should be clamped to 100%
        $color = Hsl::color(0.0, 100.0, 150.0);
        $this->assertSame(255, $color->r);
        $this->assertSame(255, $color->g);
        $this->assertSame(255, $color->b);
    }

    public function testColorLightnessNegativeClamped(): void
    {
        // L=-10% should be clamped to 0%
        $color = Hsl::color(0.0, 100.0, -10.0);
        $this->assertSame(0, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseValidHslString(): void
    {
        $color = Hsl::parse('hsl(0, 100%, 50%)');
        $this->assertNotNull($color);
        $this->assertSame(255, $color->r);
        $this->assertLessThan(5, abs($color->g));
        $this->assertLessThan(5, abs($color->b));
    }

    public function testParseHslStringWithSpaces(): void
    {
        $color = Hsl::parse('hsl( 0, 100%, 50% )');
        $this->assertNotNull($color);
        $this->assertSame(255, $color->r);
    }

    public function testParseHslCyan(): void
    {
        $color = Hsl::parse('hsl(180, 100%, 50%)');
        $this->assertNotNull($color);
        $this->assertLessThan(5, abs($color->r));
        $this->assertSame(255, $color->g);
        $this->assertSame(255, $color->b);
    }

    public function testParseInvalidReturnsNull(): void
    {
        $this->assertNull(Hsl::parse('not a color'));
        $this->assertNull(Hsl::parse(''));
        $this->assertNull(Hsl::parse('rgb(255,0,0)'));
    }

    public function testParseMissingParensReturnsNull(): void
    {
        $this->assertNull(Hsl::parse('hsl 0, 100%, 50%'));
    }
}
