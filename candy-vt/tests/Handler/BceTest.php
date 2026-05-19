<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\EraseHandler;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Tests for Background Color Erase (BCE) — when CSI Ps J/K erases with
 * a non-null SGR background, erased cells inherit that background color.
 *
 * Mirrors charmbracelet/x/vt.erase/bce tests.
 */
final class BceTest extends TestCase
{
    /** Helper: fill a row with sequential ASCII letters. */
    private function fillRow(Buffer $buf, int $row, string $chars): void
    {
        for ($c = 0; $c < strlen($chars); $c++) {
            $buf->put($row, $c, new Cell(grapheme: $chars[$c]));
        }
    }

    /** Helper: extract grapheme string from a buffer row. */
    private function rowChars(Buffer $buf, int $row): string
    {
        $s = '';
        for ($c = 0; $c < $buf->cols; $c++) {
            $s .= $buf->cell($row, $c)->grapheme;
        }
        return $s;
    }

    // ─── BCE: no SGR background → blank cell with null background ────────────

    public function testEraseWithoutSgrBackgroundProducesBlankCell(): void
    {
        // SGR background is null; erased cells should be Cell::empty().
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('K'), [0], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCD      ', $this->rowChars($buf, 0));
        // Erased cells have no background color set.
        $this->assertNull($buf->cell(0, 5)->background());
        $this->assertNull($buf->cell(0, 9)->background());
    }

    public function testEdMode2WithoutSgrBackgroundProducesBlankCells(): void
    {
        $buf = new Buffer(5, 3);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        $this->fillRow($buf, 2, 'CCCCC');
        (new EraseHandler())->apply(ord('J'), [2], $buf, new Cursor(row: 1, col: 2));
        $this->assertSame('     ', $this->rowChars($buf, 0));
        $this->assertSame('     ', $this->rowChars($buf, 1));
        $this->assertSame('     ', $this->rowChars($buf, 2));
    }

    // ─── BCE: with SGR background → erased cells carry that color ─────────────

    public function testBceElMode2ErasesWithCurrentBackground(): void
    {
        $sgr = Sgr::empty()->withBackground(Color::indexed16(4)); // red background
        $buf = new Buffer(10, 2);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        $this->fillRow($buf, 1, 'KLMNOPQRST');
        (new EraseHandler())->apply(ord('K'), [2], $buf, new Cursor(row: 0, col: 5), $sgr);

        // Row 0 should be erased with red-background cells.
        $this->assertSame('          ', $this->rowChars($buf, 0));
        $bg = $buf->cell(0, 3)->background();
        $this->assertNotNull($bg);
        $this->assertTrue($bg->equals(Color::indexed16(4)));

        // Row 1 should be untouched.
        $this->assertSame('KLMNOPQRST', $this->rowChars($buf, 1));
    }

    public function testBceEdMode2ErasesDisplayWithCurrentBackground(): void
    {
        $sgr = Sgr::empty()->withBackground(Color::truecolor(0, 255, 0)); // green
        $buf = new Buffer(5, 3);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        $this->fillRow($buf, 2, 'CCCCC');
        (new EraseHandler())->apply(ord('J'), [2], $buf, new Cursor(row: 1, col: 2), $sgr);

        // All rows should be erased with green-background cells.
        for ($r = 0; $r < 3; $r++) {
            $this->assertSame('     ', $this->rowChars($buf, $r));
            $bg = $buf->cell($r, 0)->background();
            $this->assertNotNull($bg);
            $this->assertTrue($bg->equals(Color::truecolor(0, 255, 0)));
        }
    }

    public function testBceEdMode0ErasesCursorToEndWithCurrentBackground(): void
    {
        // ED mode 0 erases from cursor to end of display (VT100 spec).
        // Cursor at (0, 2): row 0 cols 2-4, then full rows 1 and 2.
        $sgr = Sgr::empty()->withBackground(Color::indexed16(2)); // green
        $buf = new Buffer(5, 3);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        $this->fillRow($buf, 2, 'CCCCC');
        (new EraseHandler())->apply(ord('J'), [0], $buf, new Cursor(row: 0, col: 2), $sgr);

        // Row 0: cols 0-1 preserved, cols 2-4 cleared with green bg.
        $this->assertSame('AA   ', $this->rowChars($buf, 0));
        // Rows 1 and 2: fully erased (cursor to end of display).
        $this->assertSame('     ', $this->rowChars($buf, 1));
        $this->assertSame('     ', $this->rowChars($buf, 2));

        // Erased cells in row 0 carry green background.
        $bg = $buf->cell(0, 2)->background();
        $this->assertNotNull($bg);
        $this->assertTrue($bg->equals(Color::indexed16(2)));
    }

    public function testBceEchErasesCharsWithCurrentBackground(): void
    {
        $sgr = Sgr::empty()->withBackground(Color::indexed16(1)); // black/highlight
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('X'), [3], $buf, new Cursor(row: 0, col: 4), $sgr);

        // Positions 4,5,6 should be erased (with the black background).
        $this->assertSame('ABCD   HIJ', $this->rowChars($buf, 0));
        for ($c = 4; $c <= 6; $c++) {
            $bg = $buf->cell(0, $c)->background();
            $this->assertNotNull($bg);
            $this->assertTrue($bg->equals(Color::indexed16(1)));
        }
        // Positions 7,8,9 should still have the original char and no background.
        $this->assertSame('HIJ', substr($this->rowChars($buf, 0), 7));
    }

    // ─── BCE: with foreground-only SGR (no background) → blank null-bg cell ──

    public function testBceWithOnlyForegroundProducesNullBackgroundCell(): void
    {
        $sgr = Sgr::empty()->withForeground(Color::indexed16(3)); // yellow fg only
        $buf = new Buffer(5, 1);
        $this->fillRow($buf, 0, 'XXXXX');
        (new EraseHandler())->apply(ord('K'), [2], $buf, new Cursor(row: 0, col: 0), $sgr);

        // Erased with null background (BCE only applies when bg is explicitly set).
        $this->assertSame('     ', $this->rowChars($buf, 0));
        $this->assertNull($buf->cell(0, 2)->background());
    }
}
