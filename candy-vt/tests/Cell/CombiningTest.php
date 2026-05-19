<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Cell;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Buffer\Buffer;

/**
 * Tests for Unicode combining character composition — combining marks
 * (U+0300–U+036F) attach to the prior base cell's $combining field rather
 * than occupying a new cell.
 *
 * Mirrors charmbracelet/x/vt ScreenHandler.printChar combining tests.
 */
final class CombiningTest extends TestCase
{
    /** Helper: build a ScreenHandler on a 10x2 buffer. */
    private function makeHandler(): ScreenHandler
    {
        return new ScreenHandler(new Buffer(10, 2));
    }

    // ─── Cell::$combining field ─────────────────────────────────────────────────

    public function testCellWithCombiningStoresMarks(): void
    {
        $base = new Cell(grapheme: 'A');
        $combined = $base->withCombining("\xcc\x81"); // U+0301 COMBINING ACUTE
        $this->assertSame('A', $combined->grapheme);
        $this->assertSame("\xcc\x81", $combined->combining);
    }

    public function testCellWithCombiningAccumulatesMultipleMarks(): void
    {
        $base = new Cell(grapheme: 'A');
        $c1 = $base->withCombining("\xcc\x80"); // U+0300 COMBINING GRAVE
        $c2 = $c1->withCombining("\xcc\x81"); // U+0301 COMBINING ACUTE
        $this->assertSame("\xcc\x80\xcc\x81", $c2->combining);
        $this->assertSame(2, mb_strlen($c2->combining, 'UTF-8'));
    }

    public function testCellEqualsComparesCombining(): void
    {
        $a = new Cell(grapheme: 'A', combining: "\xcc\x81");
        $b = new Cell(grapheme: 'A', combining: "\xcc\x81");
        $c = new Cell(grapheme: 'A', combining: "\xcc\x80");
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testCellEmptyHasEmptyCombining(): void
    {
        $empty = Cell::empty();
        $this->assertSame('', $empty->combining);
    }

    public function testCellContinuationInheritsNoCombining(): void
    {
        $prev = new Cell(grapheme: 'A', combining: "\xcc\x81");
        $cont = Cell::continuation($prev);
        // Continuation cell only inherits sgr and hyperlink, not combining.
        $this->assertSame('', $cont->combining);
    }

    // ─── isCombiningChar helper ────────────────────────────────────────────────

    public function testCombiningAcuteIsDetected(): void
    {
        $h = $this->makeHandler();
        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('isCombiningChar');
        $meth->setAccessible(true);
        // U+0301 COMBINING ACUTE ACCENT
        $this->assertTrue($meth->invoke($h, "\xcc\x81"));
    }

    public function testCombiningGraveIsDetected(): void
    {
        $h = $this->makeHandler();
        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('isCombiningChar');
        $meth->setAccessible(true);
        // U+0300 COMBINING GRAVE ACCENT
        $this->assertTrue($meth->invoke($h, "\xcc\x80"));
    }

    public function testAsciiLetterIsNotCombining(): void
    {
        $h = $this->makeHandler();
        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('isCombiningChar');
        $meth->setAccessible(true);
        $this->assertFalse($meth->invoke($h, 'A'));
        $this->assertFalse($meth->invoke($h, 'z'));
    }

    public function testOtherUnicodeIsNotCombining(): void
    {
        $h = $this->makeHandler();
        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('isCombiningChar');
        $meth->setAccessible(true);
        // CJK extension
        $this->assertFalse($meth->invoke($h, "\xe4\xb8\xad")); // U+4E2D
        // Emoji
        $this->assertFalse($meth->invoke($h, "\xf0\x9f\x98\x80")); // U+1F600
    }

    // ─── attachCombiningChar ───────────────────────────────────────────────────

    public function testCombiningAttachesToPreviousCell(): void
    {
        $buf = new Buffer(5, 1);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $h = new ScreenHandler($buf);
        // Advance cursor to col 1 so that col-1=0 is the cell with 'A'.
        $h->cursor = $h->cursor->withCol(1);

        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('attachCombiningChar');
        $meth->setAccessible(true);
        $meth->invoke($h, "\xcc\x81"); // U+0301

        $cell = $buf->cell(0, 0);
        $this->assertSame('A', $cell->grapheme);
        $this->assertSame("\xcc\x81", $cell->combining);
    }

    public function testCombiningAtColumnZeroIsDropped(): void
    {
        $buf = new Buffer(5, 1);
        $h = new ScreenHandler($buf);
        $h->cursor = $h->cursor->withCol(0);

        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('attachCombiningChar');
        $meth->setAccessible(true);
        $meth->invoke($h, "\xcc\x81");

        // Nothing to attach to; cursor is at column 0.
        $this->assertSame(' ', $buf->cell(0, 0)->grapheme);
        $this->assertSame('', $buf->cell(0, 0)->combining);
    }

    public function testCombiningOnContinuationCellIsDropped(): void
    {
        $buf = new Buffer(5, 1);
        $base = new Cell(grapheme: "\xe4\xb8\x96", sgr: Sgr::empty()); // wide char
        $buf->put(0, 0, $base);
        $buf->put(0, 1, Cell::continuation($base));
        $h = new ScreenHandler($buf);
        $h->cursor = $h->cursor->withCol(2);

        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('attachCombiningChar');
        $meth->setAccessible(true);
        $meth->invoke($h, "\xcc\x81");

        // Continuation cell is skipped; combining is dropped.
        $this->assertSame('', $buf->cell(0, 0)->combining);
        $this->assertSame('', $buf->cell(0, 1)->combining);
    }

    public function testMultipleCombiningMarksAccumulateOnSameCell(): void
    {
        $buf = new Buffer(5, 1);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $h = new ScreenHandler($buf);
        $h->cursor = $h->cursor->withCol(1); // col 1 so col-1=0 (the 'A' cell)

        $ref = new \ReflectionClass($h);
        $meth = $ref->getMethod('attachCombiningChar');
        $meth->setAccessible(true);
        $meth->invoke($h, "\xcc\x80"); // grave
        $meth->invoke($h, "\xcc\x81"); // acute

        $cell = $buf->cell(0, 0);
        $this->assertSame("\xcc\x80\xcc\x81", $cell->combining);
    }

    // ─── printChar combining integration ─────────────────────────────────────

    public function testPrintCharAcceptsCombiningAfterBaseChar(): void
    {
        $h = $this->makeHandler();
        // Print base character 'e'.
        $h->printChar('e');
        $this->assertSame('e', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('', $h->buffer->cell(0, 0)->combining);

        // Advance cursor and send combining acute.
        $h->cursor = $h->cursor->withCol(1);
        $h->printChar("\xcc\x81"); // U+0301

        $cell = $h->buffer->cell(0, 0);
        $this->assertSame('e', $cell->grapheme);
        $this->assertSame("\xcc\x81", $cell->combining);
    }

    public function testPrintCharCombiningWithSgrCarriesStyle(): void
    {
        $h = $this->makeHandler();
        $h->sgr = Sgr::empty()->withForeground(Color::indexed16(1));
        $h->printChar('A');
        $h->cursor = $h->cursor->withCol(1);
        $h->printChar("\xcc\x81");

        $cell = $h->buffer->cell(0, 0);
        $this->assertSame('A', $cell->grapheme);
        $this->assertSame("\xcc\x81", $cell->combining);
        $this->assertTrue($cell->foreground()?->equals(Color::indexed16(1)) ?? false);
    }
}
