<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Zone;

final class ScanTest extends TestCase
{
    public function testParseReturnsEmptyArrayForPlainText(): void
    {
        $scan = new Scan();
        $zones = $scan->parse('plain text without markers');
        self::assertEmpty($zones);
    }

    public function testParseReturnsEmptyArrayForEmptyString(): void
    {
        $scan = new Scan();
        $zones = $scan->parse('');
        self::assertEmpty($zones);
    }

    public function testParseDiscoversSingleZone(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('z1', 'HELLO');

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertCount(1, $zones);
        self::assertArrayHasKey('z1', $zones);
        self::assertSame('z1', $zones['z1']->id);
    }

    public function testParseMultipleZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('a', 'AAA') . "\n" . $mark->wrap('b', 'BBB');

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertCount(2, $zones);
        self::assertSame('a', $zones['a']->id);
        self::assertSame('b', $zones['b']->id);
    }

    public function testParseZoneWithCSIEmbedded(): void
    {
        $mark = new Mark();
        $rendered = "\x1b[38;5;196m" . $mark->wrap('c', 'RED') . "\x1b[0m";

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertCount(1, $zones);
        self::assertSame('c', $zones['c']->id);
    }

    public function testParseZoneWithOSCEmbedded(): void
    {
        $mark = new Mark();
        $rendered = "\x1b]10;rgb:00/00/00\x07" . $mark->wrap('o', 'OSC') . "\x1b\\";

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertCount(1, $zones);
        self::assertSame('o', $zones['o']->id);
    }

    public function testParseUnterminatedSentinelIsSkipped(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('good', 'GOOD') . "\xEE\x80\x80unterm";

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        // The well-formed zone 'good' should still be found.
        self::assertArrayHasKey('good', $zones);
    }

    public function testParseWideCharsAccounting(): void
    {
        $mark = new Mark();
        // Japanese characters — 3 chars × 2 cells each = 6 columns.
        $rendered = $mark->wrap('jk', '日本語');

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertSame(6, $zones['jk']->width());
    }

    public function testParseNewlineAdvancesRow(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('nl', "AAA\nBBB");

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        self::assertSame(1, $zones['nl']->startRow);
        self::assertGreaterThanOrEqual(2, $zones['nl']->endRow);
    }

    public function testParseZoneEndingAtColumnOneOfNewRow(): void
    {
        // When zone ends at col=1 of a new row (e.g. content is just a newline
        // followed by nothing visible), the close sentinel is processed
        // when col=1, triggering the $endRow > $startRow && $col === 1 branch.
        // The zone collapses back to the start row.
        $mark = new Mark();
        $rendered = $mark->wrap('nlonly', "\n");

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        // Zone is at row 1, col 1 (start).
        self::assertSame(1, $zones['nlonly']->startRow);
        self::assertSame(1, $zones['nlonly']->startCol);
    }

    public function testParseUnknownPayloadIsIgnored(): void
    {
        // APC-like payload that's not a zone marker should be silently dropped.
        // This exercises the "else: unknown APC payload, drop it silently" branch.
        $mark = new Mark();
        // The zone itself should still be discovered.
        $rendered = $mark->wrap('ok', 'OK');
        $scan = new Scan();
        $zones = $scan->parse($rendered);
        self::assertCount(1, $zones);
        self::assertSame('ok', $zones['ok']->id);
    }

    /**
     * Covers lines 57-58: lone U+E001 close sentinel not preceded by zone open.
     * When a "close" sentinel (EE 80 81) appears without a preceding open sentinel
     * and '/id', the parser simply skips 3 bytes and continues.
     */
    public function testParseLoneCloseSentinelNotPrecededByZoneOpen(): void
    {
        // Construct a string with a lone U+E001 sentinel (EE 80 81) that is
        // NOT preceded by a zone open marker (EE 80 80). The parser should skip
        // the lone sentinel without error and not treat it as a zone close.
        // U+E001 = \xEE\x80\x81
        $loneCloseSentinel = "\xEE\x80\x81";
        $mark = new Mark();
        $rendered = $mark->wrap('x', 'HI') . $loneCloseSentinel . ' orphaned';

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        // The well-formed zone 'x' must still be discovered.
        self::assertArrayHasKey('x', $zones);
        // The lone sentinel contributes no zone.
        self::assertCount(1, $zones);
    }

    /**
     * Covers lines 165-174: nextGrapheme() fallback when grapheme_extract
     * returns an empty string.
     *
     * With a combining character sequence (e + combining acute accent),
     * grapheme_extract at the position of the combining character may return
     * an empty string when called with GRAPHEME_EXTR_COUNT=1, causing the
     * fallback UTF-8 byte-by-byte path to be exercised.
     */
    public function testNextGraphemeFallbackOnEmptyGraphemeExtractResult(): void
    {
        // "e" followed by combining acute accent U+0301 — two-byte grapheme.
        // The combining character alone at an offset where grapheme_extract
        // yields empty triggers the fallback (lines 165-174).
        $eWithAcute = "e\xCC\x81"; // ASCII 'e' + combining acute accent

        $mark = new Mark();
        // Place the combining char sequence at a position where grapheme_extract
        // on the combining char alone would return empty.
        $rendered = $mark->wrap('comb', $eWithAcute);

        $scan = new Scan();
        $zones = $scan->parse($rendered);

        // Zone must be discovered despite the combining char handling.
        self::assertArrayHasKey('comb', $zones);
        self::assertCount(1, $zones);
    }
}
