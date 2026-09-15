<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\EraseHandler;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * BCE (Background Color Erase) fills erased cells with the pen's
 * BACKGROUND only — foreground and every attribute (bold, underline,
 * reverse …) reset to default. The pre-fix factory copied the WHOLE pen
 * into the blanks, so `CSI 1;31;42m` + `CSI 2J` produced bold red-on-green
 * spaces instead of green-on-default ones.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html (BCE)
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (erase colour = background attribute)
 */
final class BceBackgroundOnlyTest extends TestCase
{
    private function feed(string $bytes, int $cols = 8, int $rows = 3): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    public function testErasedCellsKeepBackgroundButDropForegroundAndAttrs(): void
    {
        $sgr = (new Sgr(bold: true, underline: true, foreground: Color::indexed16(1), background: Color::indexed16(2)));
        $buf = new Buffer(6, 1);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        (new EraseHandler())->apply(ord('K'), [2], $buf, new Cursor(row: 0, col: 0), $sgr);

        $blank = $buf->cell(0, 0);
        $this->assertSame(' ', $blank->grapheme);
        $this->assertNotNull($blank->background(), 'erased cell carries the pen background');
        $this->assertTrue($blank->background()->equals(Color::indexed16(2)));
        $this->assertFalse($blank->sgr()->bold, 'bold must not bleed into erased blanks');
        $this->assertFalse($blank->sgr()->underline, 'underline must not bleed into erased cells');
        $this->assertTrue($blank->foreground() === null || $blank->foreground()->equals(Color::indexed16(7)), 'foreground back to default');
    }

    public function testWireLevelEdKeepsBackgroundOnly(): void
    {
        // Bold + red fg + green bg pen, print a glyph, then ED 2 from home.
        $h = $this->feed("\x1b[1;31;42mZZ\x1b[H\x1b[2J");
        $cleared = $h->buffer->cell(0, 0);

        $this->assertSame(' ', $cleared->grapheme);
        $this->assertTrue($cleared->background()->equals(Color::indexed16(2)), 'green bg survives the erase');
        $this->assertFalse($cleared->sgr()->bold, 'the blanks are not bold');
        $this->assertNull($cleared->foreground(), 'no red ghost in the erased field');
    }

    public function testWireLevelElKeepsBackgroundOnly(): void
    {
        $h = $this->feed("\x1b[31;44mabcd\x1b[1;1H\x1b[0K");
        $cleared = $h->buffer->cell(0, 1);
        $this->assertSame(' ', $cleared->grapheme);
        $this->assertTrue($cleared->background()->equals(Color::indexed16(4)));
        $this->assertNull($cleared->foreground());
    }

    public function testDefaultBackgroundErasesToPlainBlank(): void
    {
        // No explicit bg on the pen → Cell::empty() as before (fg-only pen
        // never painted the blank in the first place).
        $h = $this->feed("\x1b[31mabcd\x1b[H\x1b[2J");
        $cleared = $h->buffer->cell(0, 0);
        $this->assertNull($cleared->background());
        $this->assertNull($cleared->foreground());
    }

    public function testTruecolorBackgroundSurvivesErase(): void
    {
        $h = $this->feed("\x1b[48;2;10;20;30m\x1b[1mQ\x1b[H\x1b[2J");
        $cleared = $h->buffer->cell(0, 0);
        $this->assertTrue($cleared->background()?->equals(Color::truecolor(10, 20, 30)) ?? false, 'truecolor bg kept');
        $this->assertFalse($cleared->sgr()->bold);
    }

    public function testDchGapCarriesBackgroundOnly(): void
    {
        // xterm applies the erase colour to DCH's right-margin gap too —
        // the two cells shifted-in from the pre-existing blanks stay plain,
        // the gap at the line end gets the pen background.
        $h = $this->feed("\x1b[31;46mABCDEF\x1b[H\x1b[2P");
        $this->assertSame('EF', $h->buffer->cell(0, 2)->grapheme . $h->buffer->cell(0, 3)->grapheme, 'shift happened');
        $gap = $h->buffer->cell(0, 6);
        $this->assertSame(' ', $gap->grapheme);
        $this->assertTrue($gap->background()->equals(Color::indexed16(6)), 'DCH gap is cyan (bg-only)');
        $this->assertNull($gap->foreground(), 'no red ghost in the DCH gap');
    }

    public function testIchGapCarriesBackgroundOnly(): void
    {
        $h = $this->feed("\x1b[46mAB\x1b[H\x1b[2@");
        $gap = $h->buffer->cell(0, 0);
        $this->assertSame(' ', $gap->grapheme);
        $this->assertTrue($gap->background()->equals(Color::indexed16(6)), 'ICH blanks are cyan');
        $this->assertSame('A', $h->buffer->cell(0, 2)->grapheme, 'shift still happens');
    }
}
