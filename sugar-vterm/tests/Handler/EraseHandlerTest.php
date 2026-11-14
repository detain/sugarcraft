<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Cursor\Cursor;
use SugarCraft\Vterm\Handler\EraseHandler;

final class EraseHandlerTest extends TestCase
{
    /** Fill `cols` cells of row 0 with sequential letters A..Z and pad. */
    private function fillRow(Buffer $buf, int $row, string $chars): void
    {
        for ($c = 0; $c < strlen($chars); $c++) {
            $buf->put($row, $c, new Cell(grapheme: $chars[$c]));
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

    // ─── EL (CSI K) ─────────────────────────────────────────────────────────

    public function testElMode0ErasesCursorToEnd(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('K'), [0], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCD      ', $this->rowChars($buf, 0));
    }

    public function testElMode0DefaultParamErasesCursorToEnd(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('K'), [], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCD      ', $this->rowChars($buf, 0));
    }

    public function testElMode1ErasesStartToCursor(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('K'), [1], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('     FGHIJ', $this->rowChars($buf, 0));
    }

    public function testElMode2ErasesEntireLine(): void
    {
        $buf = new Buffer(10, 2);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        $this->fillRow($buf, 1, 'KLMNOPQRST');
        (new EraseHandler())->apply(ord('K'), [2], $buf, new Cursor(row: 0, col: 5));
        $this->assertSame('          ', $this->rowChars($buf, 0));
        $this->assertSame('KLMNOPQRST', $this->rowChars($buf, 1));
    }

    public function testElInvalidModeIgnored(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('K'), [9], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCDEFGHIJ', $this->rowChars($buf, 0));
    }

    // ─── ED (CSI J) ─────────────────────────────────────────────────────────

    public function testEdMode0ErasesCursorToEndOfScreen(): void
    {
        $buf = new Buffer(5, 3);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        $this->fillRow($buf, 2, 'CCCCC');
        (new EraseHandler())->apply(ord('J'), [0], $buf, new Cursor(row: 1, col: 2));
        $this->assertSame('AAAAA', $this->rowChars($buf, 0));
        $this->assertSame('BB   ', $this->rowChars($buf, 1));
        $this->assertSame('     ', $this->rowChars($buf, 2));
    }

    public function testEdMode1ErasesStartOfScreenToCursor(): void
    {
        $buf = new Buffer(5, 3);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        $this->fillRow($buf, 2, 'CCCCC');
        (new EraseHandler())->apply(ord('J'), [1], $buf, new Cursor(row: 1, col: 2));
        $this->assertSame('     ', $this->rowChars($buf, 0));
        $this->assertSame('   BB', $this->rowChars($buf, 1));
        $this->assertSame('CCCCC', $this->rowChars($buf, 2));
    }

    public function testEdMode2ErasesEntireScreen(): void
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

    public function testEdMode3IsNoOp(): void
    {
        // Mode 3 = erase scrollback; we have none, so nothing changes.
        $buf = new Buffer(5, 2);
        $this->fillRow($buf, 0, 'AAAAA');
        $this->fillRow($buf, 1, 'BBBBB');
        (new EraseHandler())->apply(ord('J'), [3], $buf, new Cursor(row: 0, col: 0));
        $this->assertSame('AAAAA', $this->rowChars($buf, 0));
        $this->assertSame('BBBBB', $this->rowChars($buf, 1));
    }

    // ─── ECH (CSI X) ────────────────────────────────────────────────────────

    public function testEchErasesNCharsFromCursor(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('X'), [3], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCD   HIJ', $this->rowChars($buf, 0));
    }

    public function testEchClampsAtRightEdge(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('X'), [99], $buf, new Cursor(row: 0, col: 7));
        $this->assertSame('ABCDEFG   ', $this->rowChars($buf, 0));
    }

    public function testEchDefaultParamErasesOneChar(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('X'), [], $buf, new Cursor(row: 0, col: 5));
        $this->assertSame('ABCDE GHIJ', $this->rowChars($buf, 0));
    }

    // ─── DCH (CSI P) ────────────────────────────────────────────────────────

    public function testDchShiftsCellsLeftAndPadsRight(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('P'), [3], $buf, new Cursor(row: 0, col: 2));
        $this->assertSame('ABFGHIJ   ', $this->rowChars($buf, 0));
    }

    public function testDchClampsAtRightEdge(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('P'), [99], $buf, new Cursor(row: 0, col: 4));
        $this->assertSame('ABCD      ', $this->rowChars($buf, 0));
    }

    // ─── ICH (CSI @) ────────────────────────────────────────────────────────

    public function testIchInsertsBlanksAndShiftsRight(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('@'), [3], $buf, new Cursor(row: 0, col: 2));
        $this->assertSame('AB   CDEFG', $this->rowChars($buf, 0));
    }

    public function testIchClampsAtRightEdge(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGHIJ');
        (new EraseHandler())->apply(ord('@'), [99], $buf, new Cursor(row: 0, col: 5));
        $this->assertSame('ABCDE     ', $this->rowChars($buf, 0));
    }

    // ─── vterm-13: wide-pair fidelity (erase widens, shifts stay atomic) ───

    private function putWide(Buffer $buf, int $row, int $col): void
    {
        $base = new Cell(grapheme: '漢');
        $buf->put($row, $col, $base);
        $buf->put($row, $col + 1, Cell::continuation($base));
    }

    public function testElFromContinuationBlanksWholeGlyph(): void
    {
        // Pin ①: EL 0 from a pair's tail must take the base too — the old
        // naive span left the base painted (a visible no-op over the glyph).
        $buf = new Buffer(10, 1);
        $this->putWide($buf, 0, 0);
        $buf->put(0, 2, new Cell(grapheme: 'a'));
        (new EraseHandler())->apply(ord('K'), [0], $buf, new Cursor(row: 0, col: 1));
        $this->assertSame('          ', $this->rowChars($buf, 0));
    }

    public function testEdSpanEndingOnABaseTakesItsTail(): void
    {
        // Pin ②: ED 1 up to a base column extends over the continuation —
        // no orphaned tail survives at the span's right edge.
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFGH');
        $this->putWide($buf, 0, 8);
        (new EraseHandler())->apply(ord('J'), [1], $buf, new Cursor(row: 0, col: 8));
        $this->assertSame('          ', $this->rowChars($buf, 0));
    }

    public function testEchSingleCountErasesWholePair(): void
    {
        // Pin ③: ECH 1 at a wide base erases base AND tail (old: blanked
        // only the base column, orphaning the continuation).
        $buf = new Buffer(10, 1);
        $this->putWide($buf, 0, 0);
        $buf->put(0, 2, new Cell(grapheme: 'X'));
        $buf->put(0, 3, new Cell(grapheme: 'Y'));
        (new EraseHandler())->apply(ord('X'), [1], $buf, new Cursor(row: 0, col: 0));
        $this->assertSame('  XY      ', $this->rowChars($buf, 0));
    }

    public function testDchFromContinuationDeletesWholePair(): void
    {
        $buf = new Buffer(10, 1);
        $this->putWide($buf, 0, 0);
        $buf->put(0, 2, new Cell(grapheme: 'a'));
        $buf->put(0, 3, new Cell(grapheme: 'b'));
        (new EraseHandler())->apply(ord('P'), [1], $buf, new Cursor(row: 0, col: 1));
        // Whole pair is the deleted unit: 'ab' lands at 0-1, no gap.
        $this->assertSame('ab        ', $this->rowChars($buf, 0));
    }

    public function testIchBeforePairInsertsAheadOfTheBase(): void
    {
        $buf = new Buffer(10, 1);
        $this->putWide($buf, 0, 0);
        $buf->put(0, 2, new Cell(grapheme: 'a'));
        (new EraseHandler())->apply(ord('@'), [2], $buf, new Cursor(row: 0, col: 1));
        // Cursor on the tail: the insert happens before the base, and the
        // pair shifts right intact (multibyte-safe: assert cells, not bytes).
        $this->assertSame(' ', $buf->cell(0, 0)->grapheme);
        $this->assertFalse($buf->cell(0, 1)->continuation);
        $this->assertSame('漢', $buf->cell(0, 2)->grapheme);
        $this->assertTrue($buf->cell(0, 3)->continuation, 'pair shifted intact');
        $this->assertSame('a', $buf->cell(0, 4)->grapheme);
    }

    public function testIchClipsEdgeStraddlingPairLeavesNoOrphanBase(): void
    {
        $buf = new Buffer(10, 1);
        $this->fillRow($buf, 0, 'ABCDEFG');
        $this->putWide($buf, 0, 7); // base 7, tail 8, one blank at 9
        (new EraseHandler())->apply(ord('@'), [2], $buf, new Cursor(row: 0, col: 0));
        // Sources 8-9 fall off; the base would land at column 9 head-of-pair
        // minus its tail -> clipped to blank (resize boundary rule).
        $this->assertSame('  ABCDEFG ', $this->rowChars($buf, 0));
        $this->assertFalse($buf->cell(0, 9)->continuation);
    }
}
