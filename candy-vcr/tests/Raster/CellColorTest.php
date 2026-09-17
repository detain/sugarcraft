<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\CellColor;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Theme;

/**
 * The pure palette-vs-truecolour decision behind both rasterizers, tested without
 * a GD/Imagick canvas (per the project's rasterizer test policy — ext-free
 * colour-extraction unit tests, not pixel diffs). {@see CellColor} is the one
 * authority that decides which colour reaches a tile; if it ever paints a
 * truecolour cell with its palette slot (the exact bug this closes) these go red.
 */
final class CellColorTest extends TestCase
{
    public function testTruecolourForegroundWinsOverPaletteSlot(): void
    {
        $theme = Theme::tokyoNight();
        // fg palette slot 4 (blue) with an overriding packed truecolour — the
        // truecolour must be the paint, not the palette.
        $cell = new Cell('X', fg: 4, bg: 0, fgTruecolor: 0x102030);

        $this->assertSame(0x102030, CellColor::foreground($cell, $theme));
        // Background has no truecolour, so it stays the theme palette lookup.
        $this->assertSame($theme->color(0), CellColor::background($cell, $theme));
    }

    public function testPaletteOnlyCellResolvesThroughTheme(): void
    {
        $theme = Theme::tokyoNight();
        $cell = new Cell('X', fg: 4, bg: 1);

        $this->assertSame($theme->color(4), CellColor::foreground($cell, $theme));
        $this->assertSame($theme->color(1), CellColor::background($cell, $theme));
        $this->assertNotSame(0, CellColor::foreground($cell, $theme));
    }

    public function testTruecolourBackgroundWinsOverPaletteSlot(): void
    {
        $theme = Theme::dracula();
        $cell = (new Cell('X', fg: 7, bg: 1))->withBgTruecolor(0x0A141E);

        $this->assertSame(0x0A141E, CellColor::background($cell, $theme));
        $this->assertSame($theme->color(7), CellColor::foreground($cell, $theme));
    }

    public function testEmulatorSgrTruecolourReadThroughTheSameDecision(): void
    {
        // The emulator representation (SGR carrying a Color::truecolour) must
        // resolve identically to a packed renderer cell — one decision, both shapes.
        $theme = new Theme();
        $sgr = Sgr::empty()->withForeground(Color::truecolor(200, 100, 50));
        $cell = new Cell('Y', sgr: $sgr);

        $this->assertSame((200 << 16) | (100 << 8) | 50, CellColor::foreground($cell, $theme));
    }

    public function testPackPassesNullThroughForTheFallbackLayering(): void
    {
        $this->assertNull(CellColor::pack(null));
        $this->assertSame(0x0A141E, CellColor::pack([0x0A, 0x14, 0x1E]));
    }

    public function testPackMasksEachChannelToEightBits(): void
    {
        $this->assertSame(0xFF00FF, CellColor::pack([255, 0, 255]));
        // Out-of-range channels must not bleed into neighbours.
        $this->assertSame(0x000000, CellColor::pack([0x100, 0x100, 0x100]));
    }

    public function testDefaultPaletteBlueIsTheCanonValue(): void
    {
        // Guard against the single-ANSI-16-canon regression: profile-degradation
        // stays the ONLY place colours reduce; CellColor must not re-tune the
        // palette, only forward Theme::color() untouched.
        $cell = new Cell('X', fg: 4, bg: 0);

        $this->assertSame((new Theme())->color(4), CellColor::foreground($cell, new Theme()));
    }
}
