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
    // =========================================================================
    // Palette-slot survival: a colour NAMED as an index stays an index
    // =========================================================================

    /**
     * The bug this pins: `Color::ansi(8)` used to collapse to [127,127,127] and
     * forget it had ever been index 8, so on any terminal advertising more than
     * 16 colours it came back out as `38;5;244` (the nearest 256-cube grey) or
     * `38;2;127;127;127` (absolute RGB). A 16-colour theme is built from
     * `Color::ansi()` precisely so the app DEFERS to the terminal's own palette,
     * and both of those override it.
     */
    public function testAnAnsi16SlotStaysAPaletteCodeAtEveryProfileThatSupportsAnsi(): void
    {
        foreach ([ColorProfile::Ansi, ColorProfile::Ansi256, ColorProfile::TrueColor] as $profile) {
            $this->assertSame("\x1b[90m", Color::ansi(8)->toFg($profile), $profile->name);
            $this->assertSame("\x1b[100m", Color::ansi(8)->toBg($profile), $profile->name);
            // Low half of the palette uses the 30-37 / 40-47 codes.
            $this->assertSame("\x1b[33m", Color::ansi(3)->toFg($profile), $profile->name);
            $this->assertSame("\x1b[43m", Color::ansi(3)->toBg($profile), $profile->name);
        }
    }

    /**
     * An RGB colour that happens to EQUAL a palette slot's nominal value is not
     * a palette slot, and must keep downsampling. This is the property that
     * makes the change a memory of intent rather than a lookup table: #7f7f7f is
     * byte-identical to `Color::ansi(8)`'s RGB and must still render as absolute
     * truecolor.
     */
    public function testAnRgbColourMatchingASlotsNominalValueStillDownsamples(): void
    {
        $rgb = Color::hex('#7f7f7f');
        $slot = Color::ansi(8);

        $this->assertSame($slot->toHex(), $rgb->toHex(), 'precondition: same RGB');
        $this->assertSame("\x1b[38;2;127;127;127m", $rgb->toFg(ColorProfile::TrueColor));
        $this->assertSame("\x1b[38;5;244m", $rgb->toFg(ColorProfile::Ansi256));
        $this->assertNotSame($rgb->toFg(ColorProfile::TrueColor), $slot->toFg(ColorProfile::TrueColor));
    }

    /** A 256-slot keeps its index where the profile can address one, and only downsamples below that. */
    public function testAnAnsi256SlotStaysAnIndexUntilTheProfileCannotAddressOne(): void
    {
        $c = Color::ansi256(202);

        $this->assertSame("\x1b[38;5;202m", $c->toFg(ColorProfile::TrueColor));
        $this->assertSame("\x1b[38;5;202m", $c->toFg(ColorProfile::Ansi256));
        // No 4-bit spelling for slot 202, so it falls back to nearest-of-16.
        $this->assertSame("\x1b[91m", $c->toFg(ColorProfile::Ansi));
        // ansi256(0-15) IS the 16-colour palette and keeps its 4-bit code.
        $this->assertSame("\x1b[90m", Color::ansi256(8)->toFg(ColorProfile::TrueColor));
    }

    /**
     * BOTH regions of the 256-colour space remember their slot: the 6x6x6 cube
     * (16-231) and the greyscale ramp (232-255).
     *
     * The test above covers cube slot 202 only, and that gap was measured, not
     * guessed: dropping the index from the greyscale branch alone —
     * `new self($g, $g, $g, $index)` -> `new self($g, $g, $g)` — left
     * candy-core, candy-kit and sugar-dash all green while
     * `Color::ansi256(244)->toFg(TrueColor)` silently changed from
     * `\x1b[38;5;244m` to `\x1b[38;2;128;128;128m`, i.e. from deferring to the
     * user's palette to overriding it. Both ends of the ramp are asserted
     * because the branch computes its RGB from the index (`8 + (i - 232) * 10`)
     * and an off-by-one there is invisible at one sample.
     */
    public function testTheGreyscaleRampRemembersItsSlotToo(): void
    {
        foreach ([232, 244, 255] as $index) {
            $c = Color::ansi256($index);

            $this->assertSame($index, $c->ansiIndex, "slot {$index}");
            $this->assertSame("\x1b[38;5;{$index}m", $c->toFg(ColorProfile::TrueColor), "slot {$index} fg");
            $this->assertSame("\x1b[48;5;{$index}m", $c->toBg(ColorProfile::TrueColor), "slot {$index} bg");
        }

        // The RGB behind the ramp is still the ramp's own, so every
        // colour-space question keeps answering from it.
        $this->assertSame('#080808', Color::ansi256(232)->toHex());
        $this->assertSame('#808080', Color::ansi256(244)->toHex());
        $this->assertSame('#eeeeee', Color::ansi256(255)->toHex());
    }

    /** A profile with no ANSI at all still emits nothing, slot or not. */
    public function testAPaletteSlotStillEmitsNothingWithoutAnsiSupport(): void
    {
        $this->assertSame('', Color::ansi(8)->toFg(ColorProfile::Ascii));
        $this->assertSame('', Color::ansi(8)->toBg(ColorProfile::NoTty));
    }

    /**
     * Every DERIVED colour loses the slot, and must: a lightened palette-8 is
     * not palette 8 any more, and emitting `\x1b[90m` for it would silently
     * discard the adjustment. Asserted in both directions — the derived value
     * renders as absolute RGB, and it is not the slot's code.
     */
    public function testDerivedColoursLoseThePaletteOrigin(): void
    {
        $slot = Color::ansi(8);
        $slotSgr = $slot->toFg(ColorProfile::TrueColor);

        $derived = [
            'lighten'       => $slot->lighten(0.2),
            'darken'        => $slot->darken(0.2),
            'alpha'         => $slot->alpha(0.5),
            'blend'         => $slot->blend(Color::ansi(1), 0.5),
            'complementary' => $slot->complementary(),
        ];

        foreach ($derived as $name => $colour) {
            $sgr = $colour->toFg(ColorProfile::TrueColor);
            $this->assertMatchesRegularExpression('/^\x1b\[38;2;\d+;\d+;\d+m$/', $sgr, $name);
            $this->assertNotSame($slotSgr, $sgr, $name);
            $this->assertNull($colour->ansiIndex, $name);
        }
    }

    /**
     * The slot memory changes emission only. Every colour-space question is
     * still answered from the stored RGB, which is what the downsampler, the
     * contrast maths and the hex round-trip all rely on.
     */
    public function testThePaletteOriginDoesNotChangeAnyColourSpaceAnswer(): void
    {
        $slot = Color::ansi(8);

        $this->assertSame('#7f7f7f', $slot->toHex());
        $this->assertEqualsWithDelta(Color::hex('#7f7f7f')->luminance(), $slot->luminance(), 1e-12);
        $this->assertTrue($slot->isDark());
        $this->assertSame(8, $slot->ansiIndex);
        $this->assertNull(Color::hex('#7f7f7f')->ansiIndex);
    }

    /**
     * SGR 58 has no 4-bit palette form, and the only 16-colour spelling
     * available is the FOREGROUND slot — so honouring the index here would trade
     * a downsampled underline for recoloured text. Deliberately unchanged.
     */
    public function testUnderlineColourDoesNotHonourThePaletteOrigin(): void
    {
        $slot = Color::ansi(8);

        $this->assertSame("\x1b[58;2;127;127;127m", $slot->toUnderline(ColorProfile::TrueColor));
        $this->assertSame("\x1b[58;5;244m", $slot->toUnderline(ColorProfile::Ansi256));
        $this->assertSame("\x1b[90m", $slot->toUnderline(ColorProfile::Ansi));
    }
}
