<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Palettes;
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

    /**
     * The seven overlapping Dracula slots must stay byte-identical to
     * candy-core's Palettes SSOT so the two never drift apart.
     */
    public function testDraculaOverlapMatchesCorePalettesSsot(): void
    {
        $theme = Theme::dracula();
        $overlap = [
            1 => 'red',
            2 => 'green',
            3 => 'yellow',
            4 => 'comment',
            5 => 'pink',
            6 => 'cyan',
            7 => 'foreground',
            8 => 'comment',
        ];
        foreach ($overlap as $slot => $name) {
            $this->assertSame(
                hexdec(ltrim(Palettes::DRACULA[$name], '#')),
                $theme->color($slot),
                "dracula slot {$slot} should equal Palettes::DRACULA['{$name}']",
            );
        }
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

    // ─── E736 6.3 memo + E742 canonical xterm palette ───

    /**
     * Independent xterm-256 ground truth for slots 16..255: cube at
     * idx = 16 + 36r + 6g + b with component levels 0 | 55+40L, then the
     * grayscale ramp 8 + 10(i−232). Computed here from the spec, never from
     * Theme's own builder, so a regression in extendedPalette() cannot
     * self-certify.
     *
     * @return array<int, int>
     */
    private static function canonicalExtension(): array
    {
        $canonical = [];
        for ($r = 0; $r < 6; $r++) {
            for ($g = 0; $g < 6; $g++) {
                for ($b = 0; $b < 6; $b++) {
                    $canonical[16 + 36 * $r + 6 * $g + $b] =
                        (($r ? 55 + 40 * $r : 0) << 16)
                        | (($g ? 55 + 40 * $g : 0) << 8)
                        | ($b ? 55 + 40 * $b : 0);
                }
            }
        }
        for ($i = 0; $i < 24; $i++) {
            $gray = 8 + 10 * $i;
            $canonical[232 + $i] = ($gray << 16) | ($gray << 8) | $gray;
        }
        return $canonical;
    }

    public function testExtensionIsCanonicalXtermRegionAcrossEveryPalette(): void
    {
        // E742 bless-correct replacement for the r87 "refuses to bless"
        // freeze pin: every palette (default + all five factories) must now
        // ship the full 256-slot table with the 16..255 extension at TRUE
        // xterm indices — no −16 shift, no shadowed cube rows, grayscale in
        // the table rather than only via the rgb() fallback. The shared memo
        // hands the identical region to all six builders; base 0..15 stays
        // each palette's own manual sixteen (pinned per-factory above).
        $canonical = self::canonicalExtension();
        $paletteRef = new \ReflectionProperty(Theme::class, 'palette');

        $themes = [
            'default' => new Theme(),
            'tokyoNight' => Theme::tokyoNight(),
            'dracula' => Theme::dracula(),
            'solarizedDark' => Theme::solarizedDark(),
            'tokyoNightLight' => Theme::tokyoNightLight(),
            'tokyoNightStorm' => Theme::tokyoNightStorm(),
        ];

        foreach ($themes as $name => $theme) {
            $palette = $paletteRef->getValue($theme);
            $this->assertCount(256, $palette, $name);
            $this->assertSame(range(0, 255), array_keys($palette), $name);
            $this->assertSame($canonical, array_slice($palette, 16, 240, true), $name);
        }
    }

    public function testDefaultPaletteCarriesXtermValuesAtTheirTrueIndices(): void
    {
        $theme = new Theme();
        $palette = Theme::defaultPalette();

        // The table is 0..255 contiguous (the pre-fix union ended at 215).
        $this->assertCount(256, $palette);

        // Base sixteen byte-identical to the historical manual row.
        $base = [
            0x000000, 0x800000, 0x008000, 0x808000, 0x000080, 0x800080, 0x008080, 0xc0c0c0,
            0x808080, 0xff0000, 0x00ff00, 0xffff00, 0x0000ff, 0xff00ff, 0x00ffff, 0xffffff,
        ];
        foreach ($base as $index => $rgb) {
            $this->assertSame($rgb, $theme->color($index), "base slot $index");
        }

        // xterm truth at the measured-defect slots (E742 ruling: colour 196
        // IS red 0xff0000, not the shifted cube leak that produced 0xff87d7).
        $this->assertSame(0x000000, $theme->color(16));
        $this->assertSame(0x875f87, $theme->color(96));
        $this->assertSame(0xff0000, $theme->color(196));
        $this->assertSame(0xffffff, $theme->color(231));
        $this->assertSame(0x080808, $theme->color(232));
        $this->assertSame(0xeeeeee, $theme->color(255));

        // Off-by-16 IMPOSSIBILITY: the old table's answer at 196 was the
        // canonical value of index 212; after the fix that value must live at
        // 212 only, the two slots must be distinct, and none of the three
        // historical shifted outputs may reappear at its old position.
        $this->assertSame(0xff87d7, $theme->color(212));
        $this->assertNotSame($theme->color(196), $theme->color(212));
        $this->assertNotSame(0xff87d7, $theme->color(196));
        $this->assertNotSame(0x0087d7, $theme->color(16));
        $this->assertNotSame(0x87d700, $theme->color(96));

        // Grayscale rides the TABLE, not the fallback (pre-fix, 216..255 were
        // absent from the array and every read fell through to rgb()).
        $this->assertSame(0x080808, $palette[232]);
        $this->assertSame(0xeeeeee, $palette[255]);
    }

    public function testExtendedPaletteMemoReturnsIdenticalArrayEveryCall(): void
    {
        $a = new \ReflectionMethod(Theme::class, 'extendedPalette');
        $first = $a->invoke(null);
        $second = $a->invoke(null);

        $this->assertSame($first, $second);
        // The memo is a VALUE copy (COW), not a shared mutable reference:
        // a defensive count check that the 240-entry extension never grew.
        $this->assertCount(240, $first);

        // Source census: the memo must EXIST (a `static $extended` line
        // inside the method slice). Kill the memo and compute-again-per-call
        // stays behaviourally green — only this pin catches the regression.
        $lines = file((string) $a->getFileName());
        $body = implode('', array_slice($lines, $a->getStartLine() - 1, $a->getEndLine() - $a->getStartLine() + 1));
        $this->assertSame(1, substr_count($body, 'static $extended'));
    }
}
