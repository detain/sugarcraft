<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScrollHandler;

final class ScrollHandlerTest extends TestCase
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

    private function fillBuffer(Buffer $buf, array $rows): void
    {
        foreach ($rows as $r => $text) {
            $this->fillRow($buf, $r, $text);
        }
    }

    // ─── SU (scroll up) ─────────────────────────────────────────────────────

    public function testScrollUpDropsTopRows(): void
    {
        $buf = new Buffer(3, 4);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC', 'DDD']);
        (new ScrollHandler())->scrollUp($buf, 2);
        $this->assertSame('CCC', $this->rowChars($buf, 0));
        $this->assertSame('DDD', $this->rowChars($buf, 1));
        $this->assertSame('   ', $this->rowChars($buf, 2));
        $this->assertSame('   ', $this->rowChars($buf, 3));
    }

    public function testScrollUpByScreenSizeBlanksAll(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        (new ScrollHandler())->scrollUp($buf, 3);
        $this->assertSame('   ', $this->rowChars($buf, 0));
        $this->assertSame('   ', $this->rowChars($buf, 2));
    }

    public function testScrollUpClampsAtScreenSize(): void
    {
        $buf = new Buffer(3, 2);
        $this->fillBuffer($buf, ['AAA', 'BBB']);
        (new ScrollHandler())->scrollUp($buf, 99);
        $this->assertSame('   ', $this->rowChars($buf, 0));
        $this->assertSame('   ', $this->rowChars($buf, 1));
    }

    // ─── SD (scroll down) ───────────────────────────────────────────────────

    public function testScrollDownDropsBottomRows(): void
    {
        $buf = new Buffer(3, 4);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC', 'DDD']);
        (new ScrollHandler())->scrollDown($buf, 2);
        $this->assertSame('   ', $this->rowChars($buf, 0));
        $this->assertSame('   ', $this->rowChars($buf, 1));
        $this->assertSame('AAA', $this->rowChars($buf, 2));
        $this->assertSame('BBB', $this->rowChars($buf, 3));
    }

    // ─── CSI dispatch ───────────────────────────────────────────────────────

    public function testApplyCsiSScrollsUp(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        (new ScrollHandler())->applyCsi(ord('S'), [1], $buf);
        $this->assertSame('BBB', $this->rowChars($buf, 0));
        $this->assertSame('CCC', $this->rowChars($buf, 1));
        $this->assertSame('   ', $this->rowChars($buf, 2));
    }

    public function testApplyCsiTScrollsDown(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        (new ScrollHandler())->applyCsi(ord('T'), [1], $buf);
        $this->assertSame('   ', $this->rowChars($buf, 0));
        $this->assertSame('AAA', $this->rowChars($buf, 1));
        $this->assertSame('BBB', $this->rowChars($buf, 2));
    }

    public function testApplyCsiSDefaultParamScrollsByOne(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        (new ScrollHandler())->applyCsi(ord('S'), [], $buf);
        $this->assertSame('BBB', $this->rowChars($buf, 0));
    }

    // ─── IND (index) ────────────────────────────────────────────────────────

    public function testIndexMovesCursorDownWhenNotAtBottom(): void
    {
        $buf = new Buffer(3, 4);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC', 'DDD']);
        $cursor = (new ScrollHandler())->index($buf, new Cursor(row: 1, col: 2));
        $this->assertSame(2, $cursor->row);
        $this->assertSame(2, $cursor->col);
        // Buffer unchanged.
        $this->assertSame('AAA', $this->rowChars($buf, 0));
    }

    public function testIndexAtBottomScrollsUp(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        $cursor = (new ScrollHandler())->index($buf, new Cursor(row: 2, col: 1));
        $this->assertSame(2, $cursor->row); // stays at last row
        $this->assertSame(1, $cursor->col);
        $this->assertSame('BBB', $this->rowChars($buf, 0));
        $this->assertSame('   ', $this->rowChars($buf, 2));
    }

    // ─── RI (reverse index) ─────────────────────────────────────────────────

    public function testReverseIndexMovesCursorUpWhenNotAtTop(): void
    {
        $buf = new Buffer(3, 4);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC', 'DDD']);
        $cursor = (new ScrollHandler())->reverseIndex($buf, new Cursor(row: 2, col: 1));
        $this->assertSame(1, $cursor->row);
        $this->assertSame('AAA', $this->rowChars($buf, 0));
    }

    public function testReverseIndexAtTopScrollsDown(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        $cursor = (new ScrollHandler())->reverseIndex($buf, new Cursor(row: 0, col: 1));
        $this->assertSame(0, $cursor->row); // stays at top
        $this->assertSame('   ', $this->rowChars($buf, 0));
        $this->assertSame('AAA', $this->rowChars($buf, 1));
    }

    // ─── NEL (next line) ────────────────────────────────────────────────────

    public function testNextLineMovesCursorToColZeroAndDown(): void
    {
        $buf = new Buffer(3, 4);
        $cursor = (new ScrollHandler())->nextLine($buf, new Cursor(row: 1, col: 2));
        $this->assertSame(2, $cursor->row);
        $this->assertSame(0, $cursor->col);
    }

    public function testNextLineAtBottomScrollsAndKeepsCursorAtBottom(): void
    {
        $buf = new Buffer(3, 3);
        $this->fillBuffer($buf, ['AAA', 'BBB', 'CCC']);
        $cursor = (new ScrollHandler())->nextLine($buf, new Cursor(row: 2, col: 2));
        $this->assertSame(2, $cursor->row);
        $this->assertSame(0, $cursor->col);
        $this->assertSame('BBB', $this->rowChars($buf, 0));
    }
}
