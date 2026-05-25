<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\Color;
use SugarCraft\Palette\Profile;
use SugarCraft\Palette\StandardColors;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testConstructClampsValuesTo0to255(): void
    {
        $c = new Color(300, -10, 128);
        $this->assertSame(255, $c->r);
        $this->assertSame(0, $c->g);
        $this->assertSame(128, $c->b);
        $this->assertSame(255, $c->a);
    }

    public function testConstructWithAlpha(): void
    {
        $c = new Color(100, 150, 200, 180);
        $this->assertSame(180, $c->a);
    }

    public function testFromHex(): void
    {
        $c = Color::fromHex(0x6b50ff);
        $this->assertSame(0x6b, $c->r);
        $this->assertSame(0x50, $c->g);
        $this->assertSame(0xff, $c->b);
        $this->assertSame(255, $c->a);
    }

    public function testParseHex3Shortand(): void
    {
        $c = Color::parse('#abc');
        $this->assertSame(0xaa, $c->r);
        $this->assertSame(0xbb, $c->g);
        $this->assertSame(0xcc, $c->b);
    }

    public function testParseHex6Long(): void
    {
        $c = Color::parse('#6b50ff');
        $this->assertSame(0x6b, $c->r);
        $this->assertSame(0x50, $c->g);
        $this->assertSame(0xff, $c->b);
    }

    public function testToHex(): void
    {
        $c = new Color(0x6b, 0x50, 0xff);
        $this->assertSame('#6b50ff', $c->toHex());
    }

    // -------------------------------------------------------------------------
    // ANSI conversion
    // -------------------------------------------------------------------------

    public function testTrueColorPassthrough(): void
    {
        $c = new Color(100, 150, 200);
        $converted = $c->convert(Profile::TrueColor);
        $this->assertSame(100, $converted->r);
        $this->assertSame(150, $converted->g);
        $this->assertSame(200, $converted->b);
    }

    public function testToAnsi256IndexInCubeRange(): void
    {
        // Pure red is index 196 in the 6x6x6 cube
        $c = new Color(255, 0, 0);
        $this->assertSame(196, $c->toAnsi256Index());
    }

    public function testToAnsi256IndexForGreyRamp(): void
    {
        // Medium grey should fall in the 232-255 range
        $c = new Color(127, 127, 127);
        $idx = $c->toAnsi256Index();
        $this->assertGreaterThanOrEqual(232, $idx);
        $this->assertLessThanOrEqual(255, $idx);
    }

    public function testToAnsiForegroundEscapes(): void
    {
        $c = new Color(255, 0, 0);
        $fg = $c->toAnsiForeground();
        $this->assertStringStartsWith("\x1b[38;2;255;0;0m", $fg);
    }

    public function testToAnsiBackgroundEscapes(): void
    {
        $c = new Color(0, 128, 0);
        $bg = $c->toAnsiBackground();
        $this->assertStringStartsWith("\x1b[48;2;0;128;0m", $bg);
    }

    public function testEquals(): void
    {
        $a = new Color(10, 20, 30);
        $b = new Color(10, 20, 30);
        $c = new Color(10, 20, 31);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    // -------------------------------------------------------------------------
    // Conversion to simpler profiles
    // -------------------------------------------------------------------------

    public function testConvertToAnsi256IsDifferentFromTrueColor(): void
    {
        // A TrueColor color when converted to ANSI256 should usually round
        $c = new Color(107, 80, 255);
        $ansi256 = $c->convert(Profile::ANSI256);
        $this->assertNotSame($c->r, $ansi256->r);
    }

    public function testConvertToAnsiIsReducedPalette(): void
    {
        $c = new Color(255, 80, 80);
        $ansi = $c->convert(Profile::ANSI);
        $this->assertContains($ansi->r, [0xcd, 0xff, 0x00, 0x7f]);
    }

    // -------------------------------------------------------------------------
    // Named colors discovery
    // -------------------------------------------------------------------------

    public function testNamedColorsIsNonEmptyListOfStrings(): void
    {
        $names = Color::namedColors();
        $this->assertNotEmpty($names);
        $this->assertSame(array_values($names), $names, 'namedColors must be a list');
        foreach ($names as $name) {
            $this->assertIsString($name);
        }
    }

    public function testNamedColorsDelegatesToStandardColorsCatalog(): void
    {
        $this->assertSame(StandardColors::catalog(), Color::namedColors());
    }

    public function testEveryNamedColorResolvesToARealColor(): void
    {
        foreach (Color::namedColors() as $name) {
            $this->assertTrue(
                isset(StandardColors::${$name}),
                "named color '{$name}' must resolve to a StandardColors property",
            );
            $this->assertInstanceOf(Color::class, StandardColors::${$name});
        }
    }
}
