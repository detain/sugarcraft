<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * DECSTBM (CSI r) homes the cursor on success — VT510 §DECSTBM: "moves
 * the cursor to column 1, line 1 of the page" — to absolute (0,0), or to
 * the region top under a live DECOM (xterm CursorSet honours origin mode;
 * DECSTBM resets neither). An ignored/rejected region set must NOT home.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECSTBM)
 */
final class DecstbmHomeTest extends TestCase
{
    private function feed(string $bytes, int $cols = 5, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    public function testDecstbmHomesCursorToPageTopLeft(): void
    {
        $h = $this->feed("\x1b[4;4H\x1b[2;5r");
        $this->assertSame(1, $h->scrollRegionTop);
        $this->assertSame(4, $h->scrollRegionBottom);
        $this->assertSame(0, $h->cursor->row, 'DECSTBM homes the cursor (VT500)');
        $this->assertSame(0, $h->cursor->col);
    }

    public function testBareDecstbmHomesAfterScribble(): void
    {
        // Default full-screen region, cursor walked off by printing — the
        // margin reset still homes.
        $h = $this->feed("abc\x1b[r");
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        $this->assertSame(0, $h->scrollRegionTop);
        $this->assertSame(4, $h->scrollRegionBottom);
    }

    public function testDecstbmHomesToRegionTopUnderDecom(): void
    {
        // Origin mode on: the home position the cursor lands on is the
        // region's own top-left, not absolute (0,0).
        $h = $this->feed("\x1b[?6h\x1b[3;5r");
        $this->assertSame(2, $h->scrollRegionTop);
        $this->assertSame(2, $h->cursor->row, 'origin-relative home');
        $this->assertSame(0, $h->cursor->col);
        $this->assertTrue($h->mode->originMode, 'DECOM survives DECSTBM (VT510 + xterm agree)');
    }

    public function testInvalidRegionDoesNotHome(): void
    {
        // top > bottom → rejected, and the cursor stays put.
        $h = $this->feed("\x1b[4;4H\x1b[5;2r");
        $this->assertSame(3, $h->cursor->row, 'rejected DECSTBM must not move the cursor');
        $this->assertSame(3, $h->cursor->col);
        $this->assertSame(0, $h->scrollRegionTop, 'region unchanged');
        $this->assertSame(4, $h->scrollRegionBottom);
    }

    public function testDecstbmDropsPhantomWrap(): void
    {
        // Print exactly to the right margin to arm the phantom, home via
        // DECSTBM, then print: the glyph must land at (0,0) — the stale
        // phantom from the old margin cannot skip a line.
        $h = $this->feed("12345\x1b[2;4rX", cols: 5, rows: 5);
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme, 'X printed at home, not after a line-skip');
        $this->assertSame(' ', $h->buffer->cell(1, 0)->grapheme, 'row 1 untouched');
        $this->assertFalse($h->wrapPending, 'DECSTBM homing disarms the phantom');
    }

    public function testRegionScrollAfterHomeStillClipsToRegion(): void
    {
        // Six filled rows, then margin set homes the cursor to (0,0) —
        // above the region — while the region itself keeps rows 1..4.
        $h = $this->feed("R0\r\nR1\r\nR2\r\nR3\r\nR4\r\nR5\x1b[2;5r", cols: 2, rows: 6);
        $this->assertSame(0, $h->cursor->row, 'post-DECSTBM home');
        $this->assertSame(1, $h->scrollRegionTop);
        $this->assertSame('R', $h->buffer->cell(0, 0)->grapheme, 'line above region survives');
    }
}
