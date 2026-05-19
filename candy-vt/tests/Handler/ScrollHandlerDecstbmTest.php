<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Handler\ScrollHandler;

/**
 * Tests for DECSTBM scroll margin support.
 *
 * ScrollHandler now accepts explicit scroll region boundaries (top/bottom)
 * so it can scroll a sub-region of the buffer rather than always the
 * full screen.
 */
final class ScrollHandlerDecstbmTest extends TestCase
{
    private function fillRow(Buffer $buf, int $row, string $text): void
    {
        for ($c = 0; $c < strlen($text); $c++) {
            $buf->put($row, $c, new Cell(grapheme: $text[$c]));
        }
    }

    private function rowChars(Buffer $buf, int $row): string
    {
        $s = '';
        for ($c = 0; $c < $buf->cols; $c++) {
            $s .= $buf->cell($row, $c)->grapheme;
        }
        return $s;
    }

    // ─── Scroll region (top=4, bottom=9 in 1-indexed = rows 4..9 0-indexed) ──

    public function testScrollUpRespectsMarginRegion(): void
    {
        // 10 rows, cols=1. Scroll region: rows 4..9 (1-indexed: 5..10).
        $buf = new Buffer(1, 10);
        for ($r = 0; $r < 10; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // scrollUp within region [4, 9] — moves rows 4..8 up one, row 9 goes blank.
        (new ScrollHandler())->scrollUp($buf, 4, 9, 1);

        // Rows outside the region are unchanged.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('1', $this->rowChars($buf, 1));
        $this->assertSame('2', $this->rowChars($buf, 2));
        $this->assertSame('3', $this->rowChars($buf, 3));

        // Inside the region: row 5 pushed to row 4, ..., row 9 pushed to row 8, row 9 blank.
        $this->assertSame('5', $this->rowChars($buf, 4));
        $this->assertSame('6', $this->rowChars($buf, 5));
        $this->assertSame('7', $this->rowChars($buf, 6));
        $this->assertSame('8', $this->rowChars($buf, 7));
        $this->assertSame('9', $this->rowChars($buf, 8));
        $this->assertSame(' ', $this->rowChars($buf, 9)); // new blank row
    }

    public function testScrollDownRespectsMarginRegion(): void
    {
        // 10 rows, cols=1. Scroll region: rows 4..9.
        $buf = new Buffer(1, 10);
        for ($r = 0; $r < 10; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // scrollDown within region [4, 9] — moves rows 4..8 down one, row 4 goes blank.
        (new ScrollHandler())->scrollDown($buf, 4, 9, 1);

        // Rows outside the region are unchanged.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('1', $this->rowChars($buf, 1));
        $this->assertSame('2', $this->rowChars($buf, 2));
        $this->assertSame('3', $this->rowChars($buf, 3));

        // Inside the region: row 4 blank, row 4 pushed to row 5, ..., row 8 pushed to row 9.
        $this->assertSame(' ', $this->rowChars($buf, 4)); // new blank row
        $this->assertSame('4', $this->rowChars($buf, 5));
        $this->assertSame('5', $this->rowChars($buf, 6));
        $this->assertSame('6', $this->rowChars($buf, 7));
        $this->assertSame('7', $this->rowChars($buf, 8));
        $this->assertSame('8', $this->rowChars($buf, 9));

        // Rows below region are unchanged.
        // (rows 10+ don't exist in this 10-row buffer)
    }

    public function testFullScreenScrollWhenNoRegionSet(): void
    {
        // Without explicit margins, scroll region defaults to [0, rows-1].
        $buf = new Buffer(1, 5);
        for ($r = 0; $r < 5; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // Simulate default full-screen scroll: top=0, bottom=4.
        (new ScrollHandler())->scrollUp($buf, 0, 4, 1);

        $this->assertSame('1', $this->rowChars($buf, 0));
        $this->assertSame('2', $this->rowChars($buf, 1));
        $this->assertSame('3', $this->rowChars($buf, 2));
        $this->assertSame('4', $this->rowChars($buf, 3));
        $this->assertSame(' ', $this->rowChars($buf, 4));
    }

    public function testScrollUpClampsToRegionHeight(): void
    {
        // Region height is 3 rows (4..6).
        $buf = new Buffer(1, 10);
        for ($r = 0; $r < 10; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // Trying to scroll 5 within a 3-row region clamps to 3.
        (new ScrollHandler())->scrollUp($buf, 4, 6, 5);

        // All 3 region rows blank after full scroll.
        $this->assertSame(' ', $this->rowChars($buf, 4));
        $this->assertSame(' ', $this->rowChars($buf, 5));
        $this->assertSame(' ', $this->rowChars($buf, 6));

        // Rows outside region untouched.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('3', $this->rowChars($buf, 3));
        $this->assertSame('7', $this->rowChars($buf, 7));
    }

    public function testIndexTriggersScrollAtBottomOfRegion(): void
    {
        $buf = new Buffer(1, 10);
        for ($r = 0; $r < 10; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // Cursor at bottom of scroll region [4, 9].
        $cursor = new \SugarCraft\Vt\Cursor\Cursor(row: 9, col: 0);
        $handler = new ScrollHandler();
        $newCursor = $handler->index($buf, $cursor, 4, 9);

        // Cursor stays at row 9 (bottom of region).
        $this->assertSame(9, $newCursor->row);
        // Region row 4 got scrolled: row 5→row 4, row 6→row 5, ..., row 9→row 8.
        $this->assertSame('5', $this->rowChars($buf, 4));
        $this->assertSame('6', $this->rowChars($buf, 5));
        $this->assertSame('7', $this->rowChars($buf, 6));
        $this->assertSame('8', $this->rowChars($buf, 7));
        $this->assertSame('9', $this->rowChars($buf, 8));
        // Row 9 is blank (what row 4 was).
        $this->assertSame(' ', $this->rowChars($buf, 9));
        // Rows outside region unchanged.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('3', $this->rowChars($buf, 3));
    }

    public function testReverseIndexTriggersScrollAtTopOfRegion(): void
    {
        $buf = new Buffer(1, 10);
        for ($r = 0; $r < 10; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // Cursor at top of scroll region [4, 9].
        $cursor = new \SugarCraft\Vt\Cursor\Cursor(row: 4, col: 0);
        $handler = new ScrollHandler();
        $newCursor = $handler->reverseIndex($buf, $cursor, 4, 9);

        // Cursor stays at row 4 (top of region).
        $this->assertSame(4, $newCursor->row);
        // scrollDown [4,9] by 1: rows 5..9 shift down 1, row 4 goes blank.
        // After scrollDown: row4=' ', row5='4', row6='5', row7='6', row8='7', row9='8'
        $this->assertSame(' ', $this->rowChars($buf, 4));
        $this->assertSame('4', $this->rowChars($buf, 5));
        $this->assertSame('5', $this->rowChars($buf, 6));
        $this->assertSame('6', $this->rowChars($buf, 7));
        $this->assertSame('7', $this->rowChars($buf, 8));
        $this->assertSame('8', $this->rowChars($buf, 9));
        // Rows 0..3 (outside region) unchanged.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('1', $this->rowChars($buf, 1));
        $this->assertSame('2', $this->rowChars($buf, 2));
        $this->assertSame('3', $this->rowChars($buf, 3));
    }

    public function testApplyCsiWithScrollRegion(): void
    {
        // 6 rows, cols=1. Region rows 2..4 (1-indexed: 3..5).
        $buf = new Buffer(1, 6);
        for ($r = 0; $r < 6; $r++) {
            $this->fillRow($buf, $r, (string) $r);
        }

        // CSI SU 1 within [2, 4].
        (new ScrollHandler())->applyCsi(ord('S'), [1], $buf, 2, 4);

        // Rows 0-1 unchanged.
        $this->assertSame('0', $this->rowChars($buf, 0));
        $this->assertSame('1', $this->rowChars($buf, 1));
        // Region [2,4]: row 3→row 2, row 4→row 3, row 4 blank.
        $this->assertSame('3', $this->rowChars($buf, 2));
        $this->assertSame('4', $this->rowChars($buf, 3));
        $this->assertSame(' ', $this->rowChars($buf, 4));
        // Row 5 unchanged (below region).
        $this->assertSame('5', $this->rowChars($buf, 5));
    }
}
