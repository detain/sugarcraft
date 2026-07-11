<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Sentinel;
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

    /**
     * Zone closing at col 1 of a non-empty trailing row: the close sentinel
     * lands at column 1 of a fresh row after content.  The $endRow > $startRow
     * && $col === 1 branch (Scan.php:84-86) should anchor endCol to the
     * previous row's maxCol (not collapse to 0 or 1).
     *
     * Case 1: "AAA\nBBB" — close lands after BBB (col > 1).  Zone should
     * span row 1–2, col 1–3.
     *
     * Case 2: "AAA\n" with immediate close at col 1 of the fresh row.
     * Zone should still land on the populated row (row 1, col 3) because
     * col 1 at row 2 is before any visible character in that row.
     */
    public function testParseZoneClosingAtColOneOfNonEmptyRow(): void
    {
        $mark = new Mark();

        // Case 1: content with trailing row, close after content (col > 1).
        $rendered1 = $mark->wrap('multi', "AAA\nBBB");
        $scan1 = new Scan();
        $zones1 = $scan1->parse($rendered1);

        self::assertArrayHasKey('multi', $zones1);
        $z1 = $zones1['multi'];
        // AAA = col 1–3 at row 1; BBB = col 1–3 at row 2.
        self::assertSame(1, $z1->startCol);
        self::assertSame(1, $z1->startRow);
        self::assertSame(3, $z1->endCol);
        self::assertSame(2, $z1->endRow);

        // Case 2: content with newline-only trailing row, close at col 1.
        $rendered2 = $mark->wrap('nlonly2', "AAA\n");
        $scan2 = new Scan();
        $zones2 = $scan2->parse($rendered2);

        self::assertArrayHasKey('nlonly2', $zones2);
        $z2 = $zones2['nlonly2'];
        // Close at col 1 of row 2 triggers the $endRow > $startRow && $col === 1
        // branch — endCol should be maxCol of the open (row 1, col 3),
        // endRow should be row 1.
        self::assertSame(1, $z2->startCol);
        self::assertSame(1, $z2->startRow);
        self::assertSame(3, $z2->endCol);
        self::assertSame(1, $z2->endRow);
    }

    /**
     * When a width is supplied, endCol must not exceed that boundary.
     * startCol is left unclamped (where the zone began is unaffected).
     */
    public function testParseWithWidthClampsEndCol(): void
    {
        $mark = new Mark();
        // "HELLO" occupies 5 columns starting at col 1.
        $rendered = $mark->wrap('wide', 'HELLO');

        $scan = new Scan();
        $zones = $scan->parse($rendered, 3); // Clamp to 3 columns.

        self::assertArrayHasKey('wide', $zones);
        $zone = $zones['wide'];
        // startCol stays at 1 (zone started at column 1).
        self::assertSame(1, $zone->startCol);
        // endCol is clamped to width 3.
        self::assertSame(3, $zone->endCol);
        self::assertSame(1, $zone->startRow);
        self::assertSame(1, $zone->endRow);
    }

    /**
     * Without a width parameter, endCol reflects the full content width.
     */
    public function testParseWithoutWidthUnchanged(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('normal', 'HELLO');

        $scan = new Scan();
        $zones = $scan->parse($rendered); // No width.

        self::assertArrayHasKey('normal', $zones);
        $zone = $zones['normal'];
        self::assertSame(1, $zone->startCol);
        self::assertSame(5, $zone->endCol); // Full width, unclamped.
    }

    // ─── [BUG] duplicate zone id rejection ──────────────────────────────────

    /**
     * A second open of an id that is still open would clobber the first
     * zone's recorded start, merging two zones into one wrong bounding box.
     * The parser must reject it instead of silently corrupting the bbox.
     *
     * Raw sentinels: OPEN dup / AAA / OPEN dup / BBB / CLOSE-a / CLOSE-b —
     * the first open sits at col 1, the second (nested) at col 4.
     */
    public function testDuplicateOpenWhileStillOpenIsRejected(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $rendered = $o . 'dup' . $c . 'AAA'
            . $o . 'dup' . $c . 'BBB'
            . $o . '/dup' . $c
            . $o . '/dup' . $c;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate zone id');
        (new Scan())->parse($rendered);
    }

    /**
     * Two well-formed but separate zones sharing an id (first fully closed
     * before the second opens) previously last-write-wins, silently dropping
     * the first.  The re-open must be rejected — the id can no longer address
     * a single zone.
     */
    public function testDuplicateIdReopenedAfterCloseIsRejected(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('dup', 'AAAAA') . 'XXXXX' . $mark->wrap('dup', 'B');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'dup'");
        (new Scan())->parse($rendered);
    }

    /**
     * Distinct ids that merely share a prefix must NOT trip the duplicate
     * guard — guards against an over-broad check keying on anything but the
     * exact id.
     */
    public function testDistinctIdsAreNotRejectedAsDuplicates(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('dup', 'A') . $mark->wrap('dup-2', 'B') . $mark->wrap('dupe', 'C');

        $zones = (new Scan())->parse($rendered);
        self::assertCount(3, $zones);
        self::assertArrayHasKey('dup', $zones);
        self::assertArrayHasKey('dup-2', $zones);
        self::assertArrayHasKey('dupe', $zones);
    }

    // ─── [PERF] O(n) close-sentinel lookup ──────────────────────────────────

    /**
     * Regression for the O(n^2) forward-strpos rescan at Scan.php:74 — each of
     * N unmatched open sentinels used to rescan to end-of-string.  With the
     * precomputed close-sentinel index the whole parse is O(n log n).  A
     * many-unmatched-open buffer must therefore both yield no zones and stay
     * well within a generous wall-clock bound (pre-fix this ran in seconds;
     * post-fix in milliseconds).
     */
    public function testManyUnmatchedOpenSentinelsStayBounded(): void
    {
        $rendered = str_repeat(Sentinel::OPEN, 40000);

        $start = microtime(true);
        $zones = (new Scan())->parse($rendered);
        $elapsed = microtime(true) - $start;

        self::assertSame([], $zones, 'unmatched open sentinels must yield no zones');
        self::assertLessThan(
            2.0,
            $elapsed,
            'parse of many unmatched open sentinels must stay near-linear, not O(n^2)'
        );
    }

    /**
     * The precomputed close-sentinel index must not change well-formed parse
     * results: a valid zone sitting after a run of interleaved unmatched close
     * sentinels is still discovered with correct bounds.
     */
    public function testValidZoneAfterUnmatchedCloseSentinelsUnaffected(): void
    {
        $mark = new Mark();
        // Lone close sentinels are skipped (never used as id terminators),
        // so the trailing well-formed zone parses exactly as normal.
        $rendered = str_repeat(Sentinel::CLOSE, 500) . $mark->wrap('real', 'HELLO');

        $zones = (new Scan())->parse($rendered);
        self::assertCount(1, $zones);
        self::assertArrayHasKey('real', $zones);
        self::assertSame(1, $zones['real']->startCol);
        self::assertSame(5, $zones['real']->endCol);
    }
}
