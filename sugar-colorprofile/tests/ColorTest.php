<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Profile;
use SugarCraft\ColorProfile\StandardColors;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testParseInvalidHexThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::parse('zzzzzz');
    }

    public function testParseTooShortHexThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::parse('ab');
    }

    public function testParseErrorMessageQuotesWhatTheUserTyped(): void
    {
        // CP-16: the rejection names the ORIGINAL input — "#zz", not the
        // stripped fragment "zz" the old message echoed.
        try {
            Color::parse('#zz');
            $this->fail('expected InvalidArgumentException for "#zz"');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('#zz', $e->getMessage());
        }
    }

    public function testParseValidHex3StillWorks(): void
    {
        $c = Color::parse('abc');
        $this->assertSame(0xaa, $c->r);
        $this->assertSame(0xbb, $c->g);
        $this->assertSame(0xcc, $c->b);
    }

    public function testColorProfileAllowsColor(): void
    {
        $this->assertTrue(\SugarCraft\ColorProfile\ColorProfile::Ansi->allowsColor());
        $this->assertTrue(\SugarCraft\ColorProfile\ColorProfile::Ansi256->allowsColor());
        $this->assertTrue(\SugarCraft\ColorProfile\ColorProfile::TrueColor->allowsColor());
        $this->assertFalse(\SugarCraft\ColorProfile\ColorProfile::NoTTY->allowsColor());
        // CP-05 Shape B: the colorless rung emits no color — it does not allow color.
        $this->assertFalse(\SugarCraft\ColorProfile\ColorProfile::Ascii->allowsColor());
    }

    // -------------------------------------------------------------------------
    // ansi16Sgr
    // -------------------------------------------------------------------------

    public function testAnsi16SgrForegroundBasic(): void
    {
        // Foreground base is 30, so index 0 → 30, index 7 → 37
        $this->assertSame(30, Color::ansi16Sgr(0, false));
        $this->assertSame(37, Color::ansi16Sgr(7, false));
    }

    public function testAnsi16SgrForegroundBright(): void
    {
        // Bright foreground base is 90, so index 8 → 90, index 15 → 97
        $this->assertSame(90, Color::ansi16Sgr(8, false));
        $this->assertSame(97, Color::ansi16Sgr(15, false));
    }

    public function testAnsi16SgrBackgroundBasic(): void
    {
        // Background base is 40, so index 0 → 40, index 7 → 47
        $this->assertSame(40, Color::ansi16Sgr(0, true));
        $this->assertSame(47, Color::ansi16Sgr(7, true));
    }

    public function testAnsi16SgrBackgroundBright(): void
    {
        // Bright background base is 100, so index 8 → 100, index 15 → 107
        $this->assertSame(100, Color::ansi16Sgr(8, true));
        $this->assertSame(107, Color::ansi16Sgr(15, true));
    }

    // -------------------------------------------------------------------------
    // toAnsi16Index
    // -------------------------------------------------------------------------

    public function testToAnsi16IndexDarkColorNotBright(): void
    {
        // Black has zero brightness so should not get bright offset
        $c = new Color(0, 0, 0);
        $idx = $c->toAnsi16Index();
        $this->assertLessThan(8, $idx);
    }

    public function testToAnsi16IndexBrightColorGetsBrightOffset(): void
    {
        // Bright red has high brightness so should get bright offset (add 8)
        $c = StandardColors::$brightRed;
        $idx = $c->toAnsi16Index();
        $this->assertGreaterThanOrEqual(8, $idx);
    }

    // -------------------------------------------------------------------------
    // fromAnsi256Index
    // -------------------------------------------------------------------------

    public function testFromAnsi256IndexGreyRamp(): void
    {
        // Index 232 is the first greyscale color
        $c = Color::fromAnsi256Index(232);
        $this->assertSame($c->r, $c->g);
        $this->assertSame($c->g, $c->b);
        // First grey is 8,8,8
        $this->assertSame(8, $c->r);
    }

    public function testFromAnsi256IndexCubeRange(): void
    {
        // Pure red in the 6x6x6 cube is index 196
        $c = Color::fromAnsi256Index(196);
        // Should be approximately red (high R, low G, low B)
        // r=255, g=0, b=0 for pure red
        $this->assertSame(255, $c->r);
        $this->assertSame(0, $c->g);
        $this->assertSame(0, $c->b);
    }

    public function testFromAnsi256IndexLastGrey(): void
    {
        // Index 255 is the last greyscale color
        $c = Color::fromAnsi256Index(255);
        $this->assertSame($c->r, $c->g);
        $this->assertSame($c->g, $c->b);
        // Last grey is 238,238,238
        $this->assertSame(238, $c->r);
    }

    /**
     * CP-16: the decoder is fail-loud like every other decoder-shaped door,
     * not a silent clamp to the ramp ends.
     *
     * @return array<string, array{int}>
     */
    public static function outOfRange256Indices(): array
    {
        return ['below' => [-1], 'one past' => [256], 'far past' => [300]];
    }

    #[DataProvider('outOfRange256Indices')]
    public function testFromAnsi256IndexRejectsIndicesOutsideTheTable(int $idx): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Color::fromAnsi256Index($idx);
    }

    #[DataProvider('outOfRange256Indices')]
    public function testFromAnsi256IndexThrowNamesTheOffendingIndex(int $idx): void
    {
        $this->expectExceptionMessage('0–255, got ' . $idx);
        Color::fromAnsi256Index($idx);
    }

    // -------------------------------------------------------------------------
    // Direct ANSI escape tests
    // -------------------------------------------------------------------------

    public function testToAnsi16Background(): void
    {
        $c = new Color(0, 0, 0);
        $bg = $c->toAnsi16Background();
        $this->assertStringStartsWith("\x1b[40m", $bg);
    }

    public function testToAnsi256Foreground(): void
    {
        $c = new Color(255, 0, 0);
        $fg = $c->toAnsi256Foreground();
        $this->assertStringStartsWith("\x1b[38;5;", $fg);
        $this->assertStringEndsWith("m", $fg);
    }

    // -------------------------------------------------------------------------
    // ColorProfile label
    // -------------------------------------------------------------------------

    public function testColorProfileLabelAnsi256(): void
    {
        $this->assertSame('ANSI 256', \SugarCraft\ColorProfile\ColorProfile::Ansi256->label());
    }

    public function testColorProfileLabelTrueColor(): void
    {
        $this->assertSame('TrueColor', \SugarCraft\ColorProfile\ColorProfile::TrueColor->label());
    }

    public function testColorProfileLabelNoTTY(): void
    {
        $this->assertSame('No TTY', \SugarCraft\ColorProfile\ColorProfile::NoTTY->label());
    }

    public function testColorProfileLabelAscii(): void
    {
        $this->assertSame('ASCII', \SugarCraft\ColorProfile\ColorProfile::Ascii->label());
    }

    public function testAnsi16SgrRejectsIndexBelowRange(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Color::ansi16Sgr(-1, false);
    }

    public function testAnsi16SgrRejectsIndexAboveRange(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Color::ansi16Sgr(16, true);
    }

    public function testAnsi16SgrAcceptsRangeEdges(): void
    {
        $this->assertSame(30, Color::ansi16Sgr(0, false));
        $this->assertSame(107, Color::ansi16Sgr(15, true));
    }

    /**
     * CP-48 boundary pin (-058): toAscii and isDark read ONE threshold now.
     * Exact-128 gray has perceivedBrightness() == 128.0 — inside the old
     * disagreement band where isDark said "not dark" yet toAscii/Fgbg/NoTTY
     * mapped to black. The band [127.5, 128] is the single disclosed
     * byte-class flip (no existing golden covered it: the Ascii-rung tests
     * use 255,0,0 and 137/250 grays, both outside the band — verified by
     * suite-wide grep, this suite went green unchanged before this pin was
     * added, so the flip is new behaviour answering a NEW assertion, not a
     * re-pinned old one).
     */
    public function testAsciiRungAndIsDarkAgreeAtTheSharedBrightnessThreshold(): void
    {
        $exact128 = new Color(128, 128, 128);
        // The BT.601 weights sum to 1.0 only in decimal; in binary the
        // "exact" 128 gray lands at 128 - 1.4e-14 — it could NEVER clear the
        // old `> 128` white gate, which is exactly the band this pin holds
        // shut: not-dark by isDark, black by toAscii, before the shared rung.
        $this->assertEqualsWithDelta(128.0, $exact128->perceivedBrightness(), 1e-9);
        $this->assertLessThan(128.0, $exact128->perceivedBrightness(), 'the epsilon is the disclosed flip itself');
        $this->assertFalse($exact128->isDark(), 'exact-128 must not be dark by either face');
        $white = $exact128->convert(ColorProfile::Ascii);
        $this->assertSame([255, 255, 255], [$white->r, $white->g, $white->b], 'not-dark maps to the white rung');

        $below = new Color(127, 127, 127);
        $this->assertTrue($below->isDark());
        $black = $below->convert(ColorProfile::Ascii);
        $this->assertSame([0, 0, 0], [$black->r, $black->g, $black->b]);

        // Fgbg and NoTTY share the door — one rung, all three faces.
        $fgbg = $exact128->convert(ColorProfile::Fgbg);
        $this->assertSame([255, 255, 255], [$fgbg->r, $fgbg->g, $fgbg->b]);
        $noTty = $exact128->convert(ColorProfile::NoTTY);
        $this->assertSame([255, 255, 255], [$noTty->r, $noTty->g, $noTty->b]);
    }
}
