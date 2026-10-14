<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Flip\AnsiArt;
use SugarCraft\Flip\AnsiCell;

final class AnsiArtTest extends TestCase
{
    private const RESET = "\x1b[0m";

    public function testNewCarriesGlyphAndSgrVerbatim(): void
    {
        $cell = AnsiCell::new('█', "\x1b[31m");
        self::assertSame('█', $cell->glyph);
        self::assertSame("\x1b[31m", $cell->sgr);
        self::assertSame('', AnsiCell::new('x')->sgr);
    }

    public function testStaticFileParsesRowsColumnsAndPerCellSgr(): void
    {
        $art = AnsiArt::fromString("\x1b[31mAB" . self::RESET . "\n\x1b[32mC" . self::RESET . "\n");

        self::assertSame(1, $art->frameCount());
        self::assertFalse($art->isAnimated());
        self::assertSame(2, $art->height());
        self::assertSame(2, $art->width());

        $cells = $art->frameCells(0);
        self::assertSame("\x1b[31m", $cells[0][0]->sgr);
        self::assertSame('A', $cells[0][0]->glyph);
        self::assertSame("\x1b[31m", $cells[0][1]->sgr, 'run stays active for the next glyph');
        self::assertSame('B', $cells[0][1]->glyph);
        self::assertSame("\x1b[32m", $cells[1][0]->sgr);
        self::assertSame('C', $cells[1][0]->glyph);
    }

    public function testResetAndBareResetNormaliseToEmptyPrefix(): void
    {
        $art = AnsiArt::fromString("\x1b[31mA" . self::RESET . "B\x1b[mC");

        $row = $art->frameCells(0)[0];
        self::assertSame("\x1b[31m", $row[0]->sgr);
        self::assertSame('', $row[1]->sgr, 'glyph after \\x1b[0m is default again');
        self::assertSame('', $row[2]->sgr, 'bare \\x1b[m is a reset too');
        self::assertSame('C', $row[2]->glyph);
    }

    public function testAnimatedFrameCountMatchesCursorUpHeaders(): void
    {
        $bytes = "\x1b[?25l"
            . "\x1b[31mA\x1b[32mB" . self::RESET . "\n"
            . "\x1b[2A\r\x1b[91ma\x1b[92mb" . self::RESET . "\n"
            . "\x1b[2A\r\x1b[33mz" . self::RESET . "\n"
            . "\x1b[?25h";
        $art = AnsiArt::fromString($bytes);

        self::assertSame(3, $art->frameCount());
        self::assertSame(3, substr_count($bytes, "\x1b[2A\r") + 1, 'frames = cursor-ups + frame 0');
        self::assertTrue($art->isAnimated());
        self::assertSame(1, $art->height());
        self::assertSame('a', $art->frameCells(1)[0][0]->glyph);
        self::assertSame("\x1b[91m", $art->frameCells(1)[0][0]->sgr);
        self::assertSame('z', $art->frameCells(2)[0][0]->glyph);
    }

    public function testCursorShowHideStrippedAnywhere(): void
    {
        $art = AnsiArt::fromString("\x1b[?25l\x1b[31mA" . self::RESET . "\x1b[?25h");
        self::assertSame('A', $art->frameCells(0)[0][0]->glyph);
        self::assertCount(1, $art->frameCells(0)[0]);
    }

    public function testRaggedRowsKeepNaturalLengths(): void
    {
        $art = AnsiArt::fromString("ABCDE\nXY\n");
        $cells = $art->frameCells(0);
        self::assertCount(5, $cells[0]);
        self::assertCount(2, $cells[1], 'short rows are not padded');
        self::assertSame(5, $art->width(), 'width is the max across rows');
    }

    public function testTruecolorAndBoxGlyphsParseCodepointWise(): void
    {
        $art = AnsiArt::fromString("\x1b[38;2;10;20;30m▄▀█");
        $row = $art->frameCells(0)[0];
        self::assertCount(3, $row, 'half-blocks are single cells (no clustering)');
        self::assertSame('▄▀█', $row[0]->glyph . $row[1]->glyph . $row[2]->glyph);
        self::assertSame("\x1b[38;2;10;20;30m", $row[0]->sgr);
    }

    public function testUnknownCsiAndStrayCarriageReturnDropped(): void
    {
        $art = AnsiArt::fromString("\x1b[2JA\x1b[H B\rC");
        $row = $art->frameCells(0)[0];
        self::assertSame(['A', ' ', 'B', 'C'], array_map(static fn (AnsiCell $c): string => $c->glyph, $row));
        self::assertSame('', $row[0]->sgr, 'erased-screen sequence does not become a cell prefix');
    }

    public function testOrphanEscapeDropped(): void
    {
        $art = AnsiArt::fromString("\x1bA");
        self::assertSame('A', $art->frameCells(0)[0][0]->glyph);
        self::assertCount(1, $art->frameCells(0)[0]);
    }

    public function testWellFormedOscSequencesDropped(): void
    {
        // BEL-terminated hyperlink OSCs (open + close) and an ST-terminated
        // window title: none of their printable payloads may re-enter the
        // model as phantom glyph cells.
        $art = AnsiArt::fromString("\x1b[31m\x1b]8;;https://example.com\x07A\x1b]8;;\x07B\x1b]0;title\x1b\\C");
        $row = $art->frameCells(0)[0];
        self::assertSame(
            ['A', 'B', 'C'],
            array_map(static fn (AnsiCell $c): string => $c->glyph, $row),
            'OSC payloads leak nothing; the active SGR run survives',
        );
        self::assertSame("\x1b[31m", $row[0]->sgr);
    }

    public function testTrailingPartialFrameStillCountsIfItRendersARow(): void
    {
        $art = AnsiArt::fromString("A\n\x1b[1A\rB");
        self::assertSame(2, $art->frameCount());
        self::assertSame('B', $art->frameCells(1)[0][0]->glyph);
    }

    public function testEmptyAndWhitespaceOnlyBytesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AnsiArt::fromString('');
    }

    public function testBlankLinesOnlyThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AnsiArt::fromString("\n\n\n");
    }

    public function testFrameIndexOutOfRange(): void
    {
        $art = AnsiArt::fromString('A');
        $this->expectException(OutOfRangeException::class);
        $art->frameCells(1);
    }

    public function testNegativeFrameIndexOutOfRange(): void
    {
        $art = AnsiArt::fromString('A');
        $this->expectException(OutOfRangeException::class);
        $art->frameCells(-1);
    }

    public function testSgrTextOutOfRange(): void
    {
        $art = AnsiArt::fromString('A');
        $this->expectException(OutOfRangeException::class);
        $art->sgrText(5);
    }

    public function testSgrTextReEmitsOnlyStateTransitionsAndClosesRowsDefault(): void
    {
        $art = AnsiArt::fromString("\x1b[31mAB" . self::RESET . "\n\x1b[32mC" . self::RESET . "\n");
        // Same run collapses to one prefix; the post-run reset rides the row
        // tail, and the blob ends in an explicit reset.
        self::assertSame("\x1b[31mAB" . self::RESET . "\n\x1b[32mC" . self::RESET, $art->sgrText(0));
    }

    public function testSgrTextReparsesToIdenticalCells(): void
    {
        $bytes = "\x1b[?25l\x1b[31mA\x1b[0mB" . self::RESET . "\n\x1b[38;5;196m█▄" . self::RESET . "\n"
            . "\x1b[2A\r\x1b[92mX Y" . self::RESET . "\n\x1b[94mZ" . self::RESET . "\n\x1b[?25h";
        $art = AnsiArt::fromString($bytes);

        foreach ([0, 1] as $frame) {
            self::assertSame(
                self::cellsDump($art->frameCells($frame)),
                self::cellsDump(AnsiArt::fromString($art->sgrText($frame))->frameCells(0)),
                "frame {$frame} round-trips through sgrText",
            );
        }
    }

    // ---- cumulative SGR state (ECMA-48 whole-document carry) ----

    public function testStyleAccumulatesAcrossRunsWithinRow(): void
    {
        // ESC[B is a cursor-down CSI (dropped, state-neutral), then a plain
        // fg-only run: C must come back red-on-BLACK, bg untouched.
        $art = AnsiArt::fromString("\x1b[91;40mA\x1b[97m B\x1b[B\x1b[91mC");
        $row = $art->frameCells(0)[0];

        self::assertSame("\x1b[91;40m", $row[0]->sgr, 'A: red-on-black');
        self::assertSame("\x1b[97;40m", $row[2]->sgr, 'B: fg re-set, background SURVIVES the run');
        self::assertSame("\x1b[91;40m", $row[3]->sgr, 'C: fg re-set again, bg still inherited');
    }

    public function testStyleCarriesAcrossRowNewlinesAndFrames(): void
    {
        // Frame 0 never resets, so frame 1 (a cursor-up redraw) inherits the
        // carried bold+bg exactly like a terminal receiving \r + cursor-up.
        $bytes = "\x1b[1;40mA\nB\n"
            . "\x1b[2A\rC\x1b[31mD" . self::RESET . "\n";
        $art = AnsiArt::fromString($bytes);

        $f0 = $art->frameCells(0);
        self::assertSame("\x1b[1;40m", $f0[0][0]->sgr, 'bold + bg resolve together');
        self::assertSame("\x1b[1;40m", $f0[1][0]->sgr, 'style survives the newline into row 2');

        $f1 = $art->frameCells(1);
        self::assertSame("\x1b[1;40m", $f1[0][0]->sgr, 'frame 2 inherits bold and bg across the frame header');
        self::assertSame("\x1b[1;31;40m", $f1[0][1]->sgr, 'in-row run adds fg, keeps inherited attrs/bg');
    }

    public function testForegroundBackgroundDefaultsResetIndependently(): void
    {
        $art = AnsiArt::fromString("\x1b[31;44mA\x1b[39mB\x1b[49mC\x1b[0mD");
        $row = $art->frameCells(0)[0];

        self::assertSame("\x1b[31;44m", $row[0]->sgr);
        self::assertSame("\x1b[44m", $row[1]->sgr, '39 drops only the foreground');
        self::assertSame('', $row[2]->sgr, '49 then leaves full default');
        self::assertSame('', $row[3]->sgr, '0 after default stays default');
    }

    public function testTruecolorMidRowThenFullReset(): void
    {
        $art = AnsiArt::fromString("\x1b[38;2;10;20;30;48;2;1;2;3mA\x1b[0mB");
        $row = $art->frameCells(0)[0];

        self::assertSame("\x1b[38;2;10;20;30;48;2;1;2;3m", $row[0]->sgr, 'rgb fg before rgb bg, canonical order');
        self::assertSame('', $row[1]->sgr, 'ESC[0m mid-row clears both colours');
    }

    public function testIndexedColorFoldsToCanonicalPaletteForms(): void
    {
        // 38;5;n with n<16 canonicalises to the short 3X/9X/4X/10X code;
        // deeper indices keep the 38;5;n spelling.
        $art = AnsiArt::fromString("\x1b[38;5;9;48;5;4mA\x1b[0m\x1b[38;5;196mB");
        $row = $art->frameCells(0)[0];

        self::assertSame("\x1b[91;44m", $row[0]->sgr);
        self::assertSame("\x1b[38;5;196m", $row[1]->sgr);
    }

    public function testColonFormTruecolorSkipsTheColorspaceIdentifier(): void
    {
        // ITU T.416 colon syntax is 38:2:Cs:r:g:b (Cs usually empty) — the
        // colourspace byte is NOT red; reading it as the first component
        // lands every colon colour one slot early. A real emulator shows
        // rgb(12,34,56) for '38:2::12:34:56', canonicalised to the 38;2 form.
        $art = AnsiArt::fromString("\x1b[38:2::12:34:56mA\x1b[0m\x1b[48:2:0:8:9:10mB");
        $row = $art->frameCells(0)[0];
        self::assertSame("\x1b[38;2;12;34;56m", $row[0]->sgr, 'empty Cs skipped, triple taken from the next three sub-params');
        self::assertSame("\x1b[48;2;8;9;10m", $row[1]->sgr, 'the 48 colon path carries its own Cs byte too');
    }

    public function testColonFormNonEmptyColorspaceStillResolvesTheRemainingTriple(): void
    {
        // 38:2:Cs:R:G:B with an explicit Cs=1 → rgb(2,3,4), not rgb(1,2,3).
        $art = AnsiArt::fromString("\x1b[38:2:1:2:3:4mA");
        self::assertSame("\x1b[38;2;2;3;4m", $art->frameCells(0)[0][0]->sgr);
    }

    public function testColonIndexedKeepsDirectIndexAndShortColonRgbIsDropped(): void
    {
        // Colon kind 5 has NO colourspace slot — n is the index directly.
        // The 5-field colon rgb form '38:2:r:g:b' leaves the triple ambiguous
        // (is 1 the Cs or the red?) and is dropped whole, never re-read.
        $art = AnsiArt::fromString("\x1b[38:5:196mA\x1b[0m\x1b[38:2:1:2:3mB");
        $row = $art->frameCells(0)[0];
        self::assertSame("\x1b[38;5;196m", $row[0]->sgr);
        self::assertSame('', $row[1]->sgr, 'colon rgb requires the Cs slot: short form ignored');
    }

    public function testTruncatedExtendedColorConsumesTheRestOfTheParamList(): void
    {
        // 'ESC[38;5m' ends the parameters mid-colour-spec: terminals bind
        // every following value to the dropped 38 request, so the stray 5
        // must never resurface as a standalone blink code.
        $art = AnsiArt::fromString("\x1b[38;5mX");
        self::assertSame('', $art->frameCells(0)[0][0]->sgr, 'truncated colour applies nothing, not blink');

        // A prior run's bold survives; the 5 adds no second attribute.
        $art = AnsiArt::fromString("\x1b[1mY\x1b[38;5mZ");
        $row = $art->frameCells(0)[0];
        self::assertSame("\x1b[1m", $row[0]->sgr);
        self::assertSame("\x1b[1m", $row[1]->sgr, 'Y and Z share the bold-only style');

        // Consumption stops at the spec: codes AFTER a COMPLETE 38;5;n apply.
        $art = AnsiArt::fromString("\x1b[38;5;196;1mA");
        self::assertSame("\x1b[1;38;5;196m", $art->frameCells(0)[0][0]->sgr);
    }

    public function testCanonicalSerializationOrderIsStable(): void
    {
        // attrs ascending, then fg, then bg — one exact string, order-fixed.
        $art = AnsiArt::fromString("\x1b[44;9;107;4;97;1mA");
        self::assertSame("\x1b[1;4;9;97;107m", $art->frameCells(0)[0][0]->sgr);
    }

    public function testUnknownAndTruncatedSgrParamsIgnoredGracefully(): void
    {
        // 20 (fraktur) and 70 are unknown standalone codes: ignored. The
        // invalid extended-colour selector 9 in `38;9` binds the REST of the
        // parameter list to the dropped 38 request (terminal behaviour), so
        // neither the 9 (strikethrough) nor the 32 (green) is re-read as a
        // standalone code — the whole run applies nothing; state never corrupts.
        $art = AnsiArt::fromString("\x1b[20;70;38;9;32mA");
        self::assertSame('', $art->frameCells(0)[0][0]->sgr);
    }

    public function testSgrTextTransitionsResetBeforeWholeStateSwap(): void
    {
        // The source explicitly drops bold (ESC[0;32m), so B's whole-state
        // run must not repaint onto A's carried state: replay resets first.
        $art = AnsiArt::fromString("\x1b[1;31mA\x1b[0;32mB");
        self::assertSame("\x1b[1;31mA\x1b[0m\x1b[32mB\x1b[0m", $art->sgrText(0));
    }

    public function testFromFileRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ansiert') . '.ansi';
        file_put_contents($path, "\x1b[35mOK" . self::RESET . "\n");
        try {
            $art = AnsiArt::fromFile($path);
            self::assertSame('OK', $art->frameCells(0)[0][0]->glyph . $art->frameCells(0)[0][1]->glyph);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileMissingThrows(): void
    {
        $this->expectException(RuntimeException::class);
        AnsiArt::fromFile('/definitely/not/here/art.ansi');
    }

    public function testFromFileDirectoryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        AnsiArt::fromFile(sys_get_temp_dir());
    }

    /** Deterministic structural dump for the round-trip comparison. */
    private static function cellsDump(array $rows): string
    {
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                self::assertInstanceOf(AnsiCell::class, $cell);
                $line[] = $cell->sgr . "\0" . $cell->glyph;
            }
            $out[] = implode('|', $line);
        }

        return implode('/', $out);
    }
}
