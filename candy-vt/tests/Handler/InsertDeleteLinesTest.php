<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * IL (CSI L) / DL (CSI M) — insert and delete lines within DECSTBM.
 *
 * Region-clipping and cursor rules mirror charmbracelet/x/vt
 * `Screen.InsertLine`/`Screen.DeleteLine` (no-op outside the region,
 * cursor homed to the left margin on success) and ECMA-48 §8.4.15/§8.4.10.
 *
 * @see https://vt100.net/docs/vt510-rm/IL.html
 * @see https://vt100.net/docs/vt510-rm/DL.html
 */
final class InsertDeleteLinesTest extends TestCase
{
    /**
     * 4×5 screen whose rows read "aaaa".."eeee" (row letter per index).
     */
    private function lettered(): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer(4, 5));
        $p = new Parser($h);
        foreach (['a', 'b', 'c', 'd', 'e'] as $r => $ch) {
            $p->feed(sprintf("\x1b[%d;1H%s%s%s%s", $r + 1, $ch, $ch, $ch, $ch));
        }
        return $h;
    }

    private function row(ScreenHandler $h, int $r): string
    {
        $line = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $line .= $h->buffer->cell($r, $c)->grapheme;
        }
        return $line;
    }

    // ─── IL ─────────────────────────────────────────────────────────────────

    public function testIlInsertsBlankLineAtCursorRowShiftingDown(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[3;1H\x1b[L"); // cursor row index 2
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('bbbb', $this->row($h, 1));
        $this->assertSame('    ', $this->row($h, 2)); // inserted blank
        $this->assertSame('cccc', $this->row($h, 3)); // shifted down
        $this->assertSame('dddd', $this->row($h, 4)); // old row 3
        // Row 'eeee' fell off the region bottom.
    }

    public function testIlWithCount(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[2;1H\x1b[2L"); // two blanks at row index 1
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('    ', $this->row($h, 1));
        $this->assertSame('    ', $this->row($h, 2));
        $this->assertSame('bbbb', $this->row($h, 3));
        $this->assertSame('cccc', $this->row($h, 4));
    }

    public function testIlCountsOverrunAsRegionSize(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[4;1H\x1b[99L"); // rows 3-4 wiped blank
        $this->assertSame('cccc', $this->row($h, 2));
        $this->assertSame('    ', $this->row($h, 3));
        $this->assertSame('    ', $this->row($h, 4));
    }

    // ─── DL ─────────────────────────────────────────────────────────────────

    public function testDlDeletesLineAtCursorRowShiftingUp(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[2;1H\x1b[M"); // delete row index 1
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('cccc', $this->row($h, 1));
        $this->assertSame('dddd', $this->row($h, 2));
        $this->assertSame('eeee', $this->row($h, 3));
        $this->assertSame('    ', $this->row($h, 4));
    }

    public function testDlAtRegionTopPushesDeletedRowsIntoScrollback(): void
    {
        // Mirrors charmbracelet x/vt DeleteLine's scrollback.PushN guard:
        // deletion starting exactly at the (full-width) region top feeds
        // the ring so scroll-up context survives.
        $h = $this->lettered();
        $this->assertCount(0, $h->scrollback->all());
        (new Parser($h))->feed("\x1b[1;1H\x1b[2M");
        /** @var array<int, array<int, Cell>> $rows */
        $rows = $h->scrollback->all();
        $this->assertCount(2, $rows);
        $this->assertSame('aaaa', implode('', array_map(static fn (Cell $c): string => $c->grapheme, $rows[0])));
        $this->assertSame('bbbb', implode('', array_map(static fn (Cell $c): string => $c->grapheme, $rows[1])));
    }

    public function testDlMidRegionDoesNotTouchScrollback(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[3;1H\x1b[M");
        $this->assertCount(0, $h->scrollback->all());
    }

    // ─── DECSTBM clipping ───────────────────────────────────────────────────

    public function testIlIgnoredWhenCursorAboveRegion(): void
    {
        $h = $this->lettered();
        // Region rows 2-4 (1-indexed); cursor parked on row 1 → no-op.
        (new Parser($h))->feed("\x1b[2;4r\x1b[1;1H\x1b[L");
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('bbbb', $this->row($h, 1));
        $this->assertSame('eeee', $this->row($h, 4));
    }

    public function testIlOnlyShiftsWithinRegion(): void
    {
        $h = $this->lettered();
        // Region rows 2-5 (1-indexed = idx 1-4). IL at idx 2: rows 0 (and
        // region-top idx1) stay put, shift bounded by the region bottom.
        (new Parser($h))->feed("\x1b[2;5r\x1b[3;1H\x1b[L");
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('bbbb', $this->row($h, 1));
        $this->assertSame('    ', $this->row($h, 2));
        $this->assertSame('cccc', $this->row($h, 3));
        $this->assertSame('dddd', $this->row($h, 4));
    }

    public function testDlOnlyShiftsWithinRegion(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[2;5r\x1b[2;1H\x1b[M"); // delete idx1 inside region
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('cccc', $this->row($h, 1));
        $this->assertSame('dddd', $this->row($h, 2));
        $this->assertSame('eeee', $this->row($h, 3));
        $this->assertSame('    ', $this->row($h, 4));
    }

    // ─── Cursor rules ───────────────────────────────────────────────────────

    public function testIlHomesCursorColumnKeepsRow(): void
    {
        // charmbracelet x/vt handlers.go: on success IL calls
        // setCursorX(0) — row unchanged, column → left margin.
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[3;3H\x1b[L");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
    }

    public function testDlHomesCursorColumnKeepsRow(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[3;4H\x1b[M");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
    }

    public function testIlOutsideRegionLeavesCursorUnmoved(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[2;4r\x1b[1;3H\x1b[L");
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
    }

    // ─── Sequenced churn: IL then DL round-trips ────────────────────────────

    public function testIlThenDlRoundTripRestoresContent(): void
    {
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[2;1H\x1b[L");
        (new Parser($h))->feed("\x1b[2;1H\x1b[M");
        $this->assertSame('aaaa', $this->row($h, 0));
        $this->assertSame('bbbb', $this->row($h, 1));
        $this->assertSame('cccc', $this->row($h, 2));
        $this->assertSame('dddd', $this->row($h, 3));
        // Row 4 lost to the round trip through the region bottom — the IL
        // pushed 'eeee' out of the region and DL's shift-up pulled blanks
        // down behind it.
        $this->assertSame('    ', $this->row($h, 4));
    }

    // ─── Phantom cell + parameter edges (review round 1: M2, m6) ────────────

    public function testIlDropsArmedPhantomSoNextGraphicStaysOnRow(): void
    {
        $h = new ScreenHandler(new Buffer(10, 4));
        $p = new Parser($h);
        $p->feed('0123456789'); // arms the phantom at (0,9)
        $this->assertTrue($h->wrapPending, 'precondition: phantom armed');
        $p->feed("\x1b[L");
        $this->assertFalse($h->wrapPending, 'column-home is a movement: phantom must die');
        $this->assertSame(0, $h->cursor->col);
        $this->assertSame(0, $h->cursor->row);
        $p->feed('Q');
        $this->assertSame('Q', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(
            '0',
            $h->buffer->cell(1, 0)->grapheme,
            'row shifted down by IL itself, not by a phantom wrap',
        );
    }

    public function testDlDropsArmedPhantom(): void
    {
        $h = new ScreenHandler(new Buffer(10, 4));
        $p = new Parser($h);
        $p->feed("\x1b[2;1H");
        $p->feed('0123456789'); // arms the phantom at (1,9)
        $p->feed("\x1b[M");
        $this->assertFalse($h->wrapPending);
        $this->assertSame(0, $h->cursor->col);
        $this->assertSame(1, $h->cursor->row);
        $p->feed('Q');
        $this->assertSame('Q', $h->buffer->cell(1, 0)->grapheme, 'next graphic stays on the cursor row');
    }

    public function testIlAndDlExplicitZeroCountAreNoOps(): void
    {
        // Explicit Ps=0 is a no-op per the cited charm InsertLine/DeleteLine
        // guards — content AND cursor (column included) must not move.
        $h = $this->lettered();
        (new Parser($h))->feed("\x1b[3;2H\x1b[0L\x1b[0M");
        foreach (['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'] as $r => $text) {
            $this->assertSame($text, $this->row($h, $r), "row {$r} untouched");
        }
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col, 'no-op must not home the column either');
    }
}
