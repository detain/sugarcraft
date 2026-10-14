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
