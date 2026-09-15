<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Charset;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Charset\Charsets;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * SCS designations (ESC ( ) * + / G0–G3), locking shifts SI/SO, SS2/SS3
 * and the DEC Special Graphics / UK mappings.
 *
 * Mirrors charmbracelet/x/vt handlers.go SCS block + utf8.go
 * handleGrapheme charset lookup, and charmbracelet/x/vt charset.go
 * SpecialDrawing/UK tables (via {@see Charsets}).
 *
 * @see https://vt100.net/docs/vt510-rm/SCS.html
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html#h2-CharSets
 */
final class CharsetsTest extends TestCase
{
    private function feed(string $bytes, int $cols = 12, int $rows = 3): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    private function line(ScreenHandler $h, int $row = 0): string
    {
        $line = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $line .= $h->buffer->cell($row, $c)->grapheme;
        }
        return rtrim($line);
    }

    public function testDecSpecialGraphicsIntoG0DrawsBoxFrames(): void
    {
        // The classic VT100 frame idiom: ESC ( 0 then "lqqqqk" → ┌────┐
        $h = $this->feed("\x1b(0lqqqqk");
        $this->assertSame('┌────┐', $this->line($h));
    }

    public function testDecSpecialGraphicsTableMatchesUpstreamSubset(): void
    {
        $h = $this->feed("\x1b(0`ajkmnuvxz{|}~", cols: 16);
        $this->assertSame('◆▒┘┐└┼┤┴│⩾π≠£·', $this->line($h));
    }

    public function testDesignatingBRestoresAscii(): void
    {
        $h = $this->feed("\x1b(0\x1b(Bn");
        $this->assertSame('n', $this->line($h));
    }

    public function testUnitedKingdomSetMapsPound(): void
    {
        $h = $this->feed("\x1b(Aa#b");
        $this->assertSame('a£b', $this->line($h));
    }

    public function testSoSiSelectG1IntoGl(): void
    {
        // G0 stays ASCII; G1 gets the graphics set; SO (0x0E) maps G1
        // into GL, SI (0x0F) maps G0 back (ECMA-48 LS1/LS0).
        $h = $this->feed("\x1b)0\x1b[?7la" . "\x0E" . 'lq' . "\x0F" . 'lz');
        // a=ASCII, SO→lq=graphics, SI→lz=ASCII (z unmapped in G0-ASCII).
        $this->assertSame('a┌─lz', $this->line($h));
    }

    public function testSs2ShiftsG2ForExactlyOneGraphic(): void
    {
        // SS2 (C1 0x8E) selects G2 for the next graphic character only;
        // the designation after it reverts to the GL set.
        $h = $this->feed("\x1b*0" . "\x8E" . 'l' . 'l');
        $this->assertSame('┌l', $this->line($h));
    }

    public function testSs3ShiftsG3ForExactlyOneGraphic(): void
    {
        $h = $this->feed("\x1b+0" . "\x8F" . 'k' . 'k');
        $this->assertSame('┐k', $this->line($h));
    }

    public function testStarAndPlusDesignateG2G3Slots(): void
    {
        $h = $this->feed("\x1b*0\x1b+U");
        $this->assertSame(['B', 'B', '0', 'U'], $h->charsets);
    }

    public function testLs2Ls3LockingShifts(): void
    {
        // ESC n = LS2 (G2 into GL), ESC o = LS3 (G3 into GL).
        $h = $this->feed("\x1b*0\x1bn" . 'l');
        $this->assertSame(2, $h->gl);
        $this->assertSame('┌', $this->line($h));

        $h = $this->feed("\x1b+0\x1bo" . 'k');
        $this->assertSame(3, $h->gl);
        $this->assertSame('┐', $this->line($h));
    }

    public function testUnknownDesignationFinalKeepsPreviousSet(): void
    {
        // charmbracelet/x/vt registers only A/B/0; anything else leaves
        // the slot alone (handler returns false, fallthrough logs).
        $h = $this->feed("\x1b(0\x1b(4" . 'l');
        $this->assertSame('0', $h->charsets[0]);
        $this->assertSame('┌', $this->line($h));
    }

    public function testMultiByteRunesBypassCharsetTranslation(): void
    {
        // A UTF-8 rune is Unicode already; even the graphics set must
        // pass it through untouched (utf8.go only maps 1-byte content).
        $h = $this->feed("\x1b(0" . "é");
        $this->assertSame('é', $this->line($h));
    }

    public function testWideCharWidthUnaffectedByGraphicsMapping(): void
    {
        // Box glyphs are width-1 even when substituted from ASCII bytes.
        $h = $this->feed("\x1b(0q");
        $this->assertSame('─', $this->line($h));
        $this->assertSame(1, $h->cursor->col);
    }

    public function testDeferredWrapStillAppliesUnderGraphicsSet(): void
    {
        // A graphic print consumes the phantom cell exactly like ASCII —
        // the wrap happens first, then the new rune is mapped through the
        // (just-designated) charset: 'e' lands at (1,0) rendered as U+240A.
        $h = $this->feed("\x1b[?7habcd\x1b(0e", cols: 4);
        $this->assertSame('abcd', $this->line($h, 0));
        $this->assertSame("\u{240A}", $this->line($h, 1));
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
    }

    public function testScsEscapeAloneDoesNotConsumePhantomCell(): void
    {
        // The designation is not a graphic: parking on the phantom cell,
        // sending ESC ( 0 must NOT wrap by itself.
        $h = $this->feed("\x1b[?7habcd\x1b(0", cols: 4);
        $this->assertTrue($h->wrapPending);
        $this->assertSame(0, $h->cursor->row);
    }
}
