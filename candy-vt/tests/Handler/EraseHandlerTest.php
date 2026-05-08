<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\EraseHandler;

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
}
