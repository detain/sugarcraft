<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Rendition;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Terminal;

/**
 * The cell→SGR emitter: {@see Cell::colorSgr()} re-projects a cell's colours
 * back onto the wire. It is what makes a truecolour value — previously a
 * one-way dead end on the renderer path — observable in a byte snapshot, so it
 * is pinned here on both the packed-truecolour and palette representations and
 * verified against a full feed→cell→emit round-trip.
 */
final class CellColorSgrTest extends TestCase
{
    public function testPackedTruecolourForegroundEmitsFullRgb(): void
    {
        $cell = (new Cell(char: 'X'))->withFgTruecolor(0xFF8040);

        // fg is the packed truecolour; bg stays its default palette 0, so the
        // emitter pairs `38;2;…` with `48;5;0`.
        $this->assertSame("\x1b[38;2;255;128;64;48;5;0m", $cell->colorSgr());
    }

    public function testPackedTruecolourForegroundAndBackground(): void
    {
        $cell = (new Cell(char: 'X'))->withFgTruecolor(0xFF0000)->withBgTruecolor(0x0080FF);

        $this->assertSame("\x1b[38;2;255;0;0;48;2;0;128;255m", $cell->colorSgr());
    }

    public function testPaletteForegroundEmitsExtendedIndex(): void
    {
        $cell = (new Cell(char: 'X', fg: 7, bg: 0))->withFgTruecolor(null);

        // Renderer default fg=7/bg=0 are genuine palette values, not "unset",
        // so both contribute a 38;5/48;5 pair.
        $this->assertSame("\x1b[38;5;7;48;5;0m", $cell->colorSgr());
    }

    public function testTruecolourSlotWinsOverPaletteNumber(): void
    {
        // A cell carrying both a packed truecolour and the default palette int
        // must emit the truecolour form — the packed slot is the truth.
        $cell = new Cell(char: 'X', fg: 7, bg: 0, fgTruecolor: 0x102030);

        $this->assertSame("\x1b[38;2;16;32;48;48;5;0m", $cell->colorSgr());
        $this->assertSame([16, 32, 48], $cell->fgRgb());
        $this->assertNull($cell->bgRgb());
    }

    public function testEmulatorSgrTruecolourReadThroughTheSameEmitter(): void
    {
        // The emulator representation (SGR carrying a Color::truecolour) must
        // project through colorSgr() identically to a packed renderer cell.
        $sgr = Sgr::empty()
            ->withForeground(Color::truecolor(12, 34, 56))
            ->withBackground(Color::indexed16(4));
        $cell = new Cell(char: 'Y', sgr: $sgr);

        $this->assertSame([12, 34, 56], $cell->fgRgb());
        $this->assertSame("\x1b[38;2;12;34;56;48;5;4m", $cell->colorSgr());
    }

    public function testDefaultColoursEmitNothing(): void
    {
        // A cell with neither a palette colour set nor a truecolour slot
        // (both colours default) contributes no SGR at all.
        $sgr = Sgr::empty()->withForeground(Color::default())->withBackground(Color::default());
        $cell = new Cell(char: ' ', sgr: $sgr);

        $this->assertSame('', $cell->colorSgr());
    }

    public function testRendererFeedRoundTripsTruecolourThroughTheEmitter(): void
    {
        // End-to-end: the renderer used to drop `38;2` onto the palette path;
        // with the unified truecolour pen it now stores the packed RGB and
        // re-emits the exact same colour through colorSgr() — the value-level
        // guarantee the parity work closed the divergence on. (The pixel
        // rasterizers do not yet consume colorSgr(); this pins the cell-level
        // round-trip the emitter exists for.)
        $term = Terminal::new(10, 3);
        $term->feed("\x1b[38;2;200;100;50;48;2;10;20;30mZ");

        $cell = $term->grid()->cell(0, 0);
        $this->assertSame('Z', $cell->char);
        $this->assertSame("\x1b[38;2;200;100;50;48;2;10;20;30m", $cell->colorSgr());
    }

    public function testRenditionIsIndependentOfTheColorEmitter(): void
    {
        // colorSgr() speaks only colour; a double-height stamp must not leak
        // into the byte stream.
        $cell = (new Cell(char: 'X', fg: 1, bg: 1, fgTruecolor: null))->withRendition(Rendition::DoubleTop);

        $this->assertSame(Rendition::DoubleTop, $cell->rendition);
        $this->assertStringNotContainsString('#', $cell->colorSgr());
        $this->assertSame("\x1b[38;5;1;48;5;1m", $cell->colorSgr());
    }
}
