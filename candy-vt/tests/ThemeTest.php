<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Theme;
use SugarCraft\Vt\Themes;

final class ThemeTest extends TestCase
{
    public function testTokyoNightColors(): void
    {
        $theme = Theme::tokyoNight();

        $this->assertSame(0x15161e, $theme->color(0));
        $this->assertSame(0xf7768e, $theme->color(1));
        $this->assertSame(0x9ece6a, $theme->color(2));
        $this->assertSame(0xe0af68, $theme->color(3));
        $this->assertSame(0x7aa2f7, $theme->color(4));
        $this->assertSame(0xbb9af7, $theme->color(5));
        $this->assertSame(0x7dcfff, $theme->color(6));
        $this->assertSame(0xa9b1d6, $theme->color(7));
        $this->assertSame(0x414868, $theme->color(8));
        $this->assertSame(0xf7768e, $theme->color(9));
        $this->assertSame(0x9ece6a, $theme->color(10));
        $this->assertSame(0xe0af68, $theme->color(11));
        $this->assertSame(0x7aa2f7, $theme->color(12));
        $this->assertSame(0xbb9af7, $theme->color(13));
        $this->assertSame(0x7dcfff, $theme->color(14));
        $this->assertSame(0xc0caf5, $theme->color(15));
    }

    public function testTokyoNightBackground(): void
    {
        $theme = Theme::tokyoNight();
        $this->assertSame(0, $theme->defaultBg);
        $this->assertSame(0x15161e, $theme->color($theme->defaultBg));
        $this->assertSame(7, $theme->defaultFg);
        $this->assertSame(0xa9b1d6, $theme->color($theme->defaultFg));
        $this->assertSame(0xc0caf5, $theme->color(15));
    }

    public function testTokyoNightLight(): void
    {
        $theme = Theme::tokyoNightLight();
        $this->assertSame(0x15161e, $theme->color(0));
        $this->assertSame(0xf7768e, $theme->color(1));
        $this->assertSame(0x9ece6a, $theme->color(2));
        $this->assertSame(0xe0af68, $theme->color(3));
        $this->assertSame(0x7aa2f7, $theme->color(4));
        $this->assertSame(0xbb9af7, $theme->color(5));
        $this->assertSame(0x7dcfff, $theme->color(6));
        $this->assertSame(0xa9b1d6, $theme->color(7));
        $this->assertSame(0x414868, $theme->color(8));
        $this->assertSame(0xf7768e, $theme->color(9));
        $this->assertSame(0x9ece6a, $theme->color(10));
        $this->assertSame(0xe0af68, $theme->color(11));
        $this->assertSame(0x7aa2f7, $theme->color(12));
        $this->assertSame(0xbb9af7, $theme->color(13));
        $this->assertSame(0x7dcfff, $theme->color(14));
        $this->assertSame(0xc0caf5, $theme->color(15));
    }

    public function testTokyoNightStorm(): void
    {
        $theme = Theme::tokyoNightStorm();
        $this->assertSame(0x1a1b26, $theme->color(0));
        $this->assertSame(0xf7768e, $theme->color(1));
        $this->assertSame(0x9ece6a, $theme->color(2));
        $this->assertSame(0xe0af68, $theme->color(3));
        $this->assertSame(0x7aa2f7, $theme->color(4));
        $this->assertSame(0xbb9af7, $theme->color(5));
        $this->assertSame(0x7dcfff, $theme->color(6));
        $this->assertSame(0xc0caf5, $theme->color(7));
        $this->assertSame(0x414868, $theme->color(8));
    }

    public function testDraculaHasDifferentPalette(): void
    {
        $theme = Theme::dracula();
        $this->assertSame(0x21222c, $theme->color(0));
        $this->assertSame(0xff5555, $theme->color(1));
    }

    public function testSolarizedDarkHasDifferentPalette(): void
    {
        $theme = Theme::solarizedDark();
        $this->assertSame(0x073642, $theme->color(0));
        $this->assertSame(0xdc322f, $theme->color(1));
    }

    public function testRgbRoundTrip(): void
    {
        $testIndices = [0, 1, 7, 8, 15, 16, 17, 231, 232, 233, 255];
        foreach ($testIndices as $index) {
            $rgb = Theme::rgb($index);
            $this->assertIsArray($rgb);
            $this->assertCount(3, $rgb);
            $this->assertGreaterThanOrEqual(0, $rgb[0]);
            $this->assertLessThanOrEqual(255, $rgb[0]);
            $this->assertGreaterThanOrEqual(0, $rgb[1]);
            $this->assertLessThanOrEqual(255, $rgb[1]);
            $this->assertGreaterThanOrEqual(0, $rgb[2]);
            $this->assertLessThanOrEqual(255, $rgb[2]);
        }
    }

    public function testRgbColorCube(): void
    {
        for ($r = 0; $r < 6; $r++) {
            for ($g = 0; $g < 6; $g++) {
                for ($b = 0; $b < 6; $b++) {
                    $index = 16 + $r * 36 + $g * 6 + $b;
                    $rgb = Theme::rgb($index);
                    $expectedR = $r ? $r * 40 + 55 : 0;
                    $expectedG = $g ? $g * 40 + 55 : 0;
                    $expectedB = $b ? $b * 40 + 55 : 0;
                    $this->assertSame($expectedR, $rgb[0], "R mismatch at index $index");
                    $this->assertSame($expectedG, $rgb[1], "G mismatch at index $index");
                    $this->assertSame($expectedB, $rgb[2], "B mismatch at index $index");
                }
            }
        }
    }

    public function testRgbGrayscale(): void
    {
        for ($i = 0; $i < 24; $i++) {
            $index = 232 + $i;
            $rgb = Theme::rgb($index);
            $expected = (int) floor($i * 10 + 8);
            $this->assertSame($expected, $rgb[0], "Grayscale R mismatch at index $index");
            $this->assertSame($expected, $rgb[1], "Grayscale G mismatch at index $index");
            $this->assertSame($expected, $rgb[2], "Grayscale B mismatch at index $index");
        }
    }

    public function testRgbOutOfRange(): void
    {
        $this->assertSame([0, 0, 0], Theme::rgb(-1));
        $this->assertSame([0, 0, 0], Theme::rgb(256));
    }

    public function testFgIndex(): void
    {
        for ($i = 0; $i <= 15; $i++) {
            $this->assertSame($i, Theme::fgIndex($i));
        }
    }

    public function testBgIndex(): void
    {
        for ($i = 0; $i <= 15; $i++) {
            $this->assertSame($i, Theme::bgIndex($i));
        }
    }

    public function testFgIndexOutOfRange(): void
    {
        $this->assertSame(0, Theme::fgIndex(-1));
        $this->assertSame(0, Theme::fgIndex(16));
    }

    public function testBgIndexOutOfRange(): void
    {
        $this->assertSame(0, Theme::bgIndex(-1));
        $this->assertSame(0, Theme::bgIndex(16));
    }

    public function testColorOutOfRangeReturnsZero(): void
    {
        $theme = Theme::tokyoNight();
        $this->assertSame(0, $theme->color(-1));
        $this->assertSame(0, $theme->color(256));
    }

    public function testAttributeConstants(): void
    {
        // Bit flags must remain distinct, contiguous, and stable. Use
        // reflection so phpstan can't narrow the constants to literals
        // (which would make assertSame tautological).
        $rc = new \ReflectionClass(Theme::class);
        $constants = [
            'ATTR_BOLD' => 1,
            'ATTR_ITALIC' => 2,
            'ATTR_UNDERLINE' => 4,
            'ATTR_INVERSE' => 8,
            'ATTR_STRIKETHROUGH' => 16,
        ];
        foreach ($constants as $name => $expected) {
            $this->assertTrue($rc->hasConstant($name), "missing constant {$name}");
            $this->assertSame($expected, $rc->getConstant($name), "constant {$name} should equal {$expected}");
        }
    }

    public function testDefaultPaletteIsUsedWhenNoCustomPalette(): void
    {
        $theme = new Theme();
        $defaultPalette = Theme::defaultPalette();
        for ($i = 0; $i < 16; $i++) {
            $this->assertSame($defaultPalette[$i], $theme->color($i));
        }
    }

    public function testThemesCatalogAll(): void
    {
        $all = Themes::all();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('TokyoNight', $all);
        $this->assertArrayHasKey('TokyoNightLight', $all);
        $this->assertArrayHasKey('TokyoNightStorm', $all);
        $this->assertArrayHasKey('Dracula', $all);
        $this->assertArrayHasKey('SolarizedDark', $all);
        $this->assertCount(5, $all);
    }

    public function testThemesCatalogV1(): void
    {
        $v1 = Themes::v1();
        $this->assertIsArray($v1);
        $this->assertArrayHasKey('TokyoNight', $v1);
        $this->assertArrayHasKey('TokyoNightLight', $v1);
        $this->assertArrayHasKey('TokyoNightStorm', $v1);
        $this->assertCount(3, $v1);
        $this->assertSame('TokyoNight', array_key_first($v1));
    }

    public function testThemesCatalogTokyoNightFirst(): void
    {
        $v1 = Themes::v1();
        $keys = array_keys($v1);
        $this->assertSame('TokyoNight', $keys[0]);
    }
}
