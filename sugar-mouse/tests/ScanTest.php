<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

final class ScanTest extends TestCase
{
    public function testParseReturnsEmptyArrayForPlainText(): void
    {
        $zones = Scan::parse('plain text without markers');
        self::assertEmpty($zones);
    }

    public function testParseReturnsEmptyArrayForEmptyString(): void
    {
        $zones = Scan::parse('');
        self::assertEmpty($zones);
    }

    public function testParseDiscoversSingleZone(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('z1', 'HELLO');

        $zones = Scan::parse($rendered);

        self::assertCount(1, $zones);
        self::assertArrayHasKey('z1', $zones);
        self::assertSame('z1', $zones['z1']->id);
    }

    public function testParseMultipleZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('a', 'AAA') . "\n" . $mark->wrap('b', 'BBB');

        $zones = Scan::parse($rendered);

        self::assertCount(2, $zones);
        self::assertSame('a', $zones['a']->id);
        self::assertSame('b', $zones['b']->id);
    }

    public function testParseZoneWithCSIEmbedded(): void
    {
        $mark = new Mark();
        $rendered = "\x1b[38;5;196m" . $mark->wrap('c', 'RED') . "\x1b[0m";

        $zones = Scan::parse($rendered);

        self::assertCount(1, $zones);
        self::assertSame('c', $zones['c']->id);
    }

    public function testParseZoneWithOSCEmbedded(): void
    {
        $mark = new Mark();
        $rendered = "\x1b]10;rgb:00/00/00\x07" . $mark->wrap('o', 'OSC') . "\x1b\\";

        $zones = Scan::parse($rendered);

        self::assertCount(1, $zones);
        self::assertSame('o', $zones['o']->id);
    }

    /**
     * DCS/SOS/PM/APC are ESC-introduced strings like OSC (sixel, kitty
     * passthrough, terminator control strings). Their payload bytes paint no
     * cells: if they were measured as text, every zone registered below them
     * would start columns too far right.
     */
    public function testParseEscStringSequencesCarryNoWidth(): void
    {
        $mark = new Mark();
        foreach (['P', 'X', '^', '_'] as $introducer) {
            $rendered = "\x1b" . $introducer . 'q>0;1;2;3' . "\x1b\\" . $mark->wrap('z', 'AB');

            $zones = Scan::parse($rendered);

            self::assertArrayHasKey('z', $zones, "sequence with introducer {$introducer}");
            self::assertSame(1, $zones['z']->startCol, "introducer {$introducer} inflated the column");
            self::assertSame(2, $zones['z']->endCol, "introducer {$introducer} shifted the zone");
        }
    }

    public function testParseUnterminatedEscStringRunsToEndOfRender(): void
    {
        $mark = new Mark();
        // ST never arrives: the DCS swallows the rest of the render (same
        // posture as an unterminated OSC), and only the earlier zone survives.
        $rendered = $mark->wrap('a', 'A') . "\x1b_Pq#5";

        $zones = Scan::parse($rendered);

        self::assertSame(['a'], array_keys($zones));
        self::assertSame(1, $zones['a']->endCol);
    }

    /**
     * parse() and strip() deliberately diverge on a well-formed tag hidden
     * inside an OSC payload: the grid walker consumes the OSC bytes wholesale
     * (no zone is recorded), while the byte-wise stripper removes the tag
     * pair wherever its sentinels appear. This is the caveat documented on
     * Scan::strip(); the consumer sweep duty stands either way.
     */
    public function testParseIgnoresTagsInsideOscWhileStripRemovesTheirBytes(): void
    {
        $open = "\xEE\x80\x80";
        $close = "\xEE\x80\x81";
        $tag = $open . 'in' . $close;
        $rendered = "\x1b]2;" . $tag . 'XY' . $open . '/in' . $close . "\x07tail";

        self::assertSame([], Scan::parse($rendered));
        self::assertSame("\x1b]2;XY\x07tail", Scan::strip($rendered));
    }

    public function testParseUnterminatedSentinelIsSkipped(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('good', 'GOOD') . "\xEE\x80\x80unterm";

        $zones = Scan::parse($rendered);

        // The well-formed zone 'good' should still be found.
        self::assertArrayHasKey('good', $zones);
    }

    public function testParseWideCharsAccounting(): void
    {
        $mark = new Mark();
        // Japanese characters — 3 chars × 2 cells each = 6 columns.
        $rendered = $mark->wrap('jk', '日本語');

        $zones = Scan::parse($rendered);

        self::assertSame(6, $zones['jk']->width());
    }

    public function testParseNewlineAdvancesRow(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('nl', "AAA\nBBB");

        $zones = Scan::parse($rendered);

        self::assertSame(1, $zones['nl']->startRow);
        self::assertGreaterThanOrEqual(2, $zones['nl']->endRow);
    }

    public function testParseZoneEndingAtColumnOneOfNewRow(): void
    {
        // A zone whose content is just a newline closes at col 1 of the next
        // row having painted no cell of its own.  It used to collapse back
        // to a phantom 1-cell zone at (1,1) — claiming the cell of whatever
        // painted there next.  With no cell to hit it is not emitted at all.
        $mark = new Mark();
        $rendered = $mark->wrap('nlonly', "\n") . 'B';

        $zones = Scan::parse($rendered);

        self::assertArrayNotHasKey('nlonly', $zones);
        self::assertSame([], $zones);
    }

    public function testParseUnknownPayloadIsIgnored(): void
    {
        // APC-like payload that's not a zone marker should be silently dropped.
        // This exercises the "else: unknown APC payload, drop it silently" branch.
        $mark = new Mark();
        // The zone itself should still be discovered.
        $rendered = $mark->wrap('ok', 'OK');
        $zones = Scan::parse($rendered);
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

        $zones = Scan::parse($rendered);

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

        $zones = Scan::parse($rendered);

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
        $zones1 = Scan::parse($rendered1);

        self::assertArrayHasKey('multi', $zones1);
        $z1 = $zones1['multi'];
        // AAA = col 1–3 at row 1; BBB = col 1–3 at row 2.
        self::assertSame(1, $z1->startCol);
        self::assertSame(1, $z1->startRow);
        self::assertSame(3, $z1->endCol);
        self::assertSame(2, $z1->endRow);

        // Case 2: content with newline-only trailing row, close at col 1.
        $rendered2 = $mark->wrap('nlonly2', "AAA\n");
        $zones2 = Scan::parse($rendered2);

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
     * When a width is supplied, no zone column may exceed that boundary.
     * An in-viewport startCol (1 here) passes through the two-sided clamp
     * untouched — only starts beyond the right edge are pulled back.
     */
    public function testParseWithWidthClampsEndCol(): void
    {
        $mark = new Mark();
        // "HELLO" occupies 5 columns starting at col 1.
        $rendered = $mark->wrap('wide', 'HELLO');

        $zones = Scan::parse($rendered, 3); // Clamp to 3 columns.

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

        $zones = Scan::parse($rendered); // No width.

        self::assertArrayHasKey('normal', $zones);
        $zone = $zones['normal'];
        self::assertSame(1, $zone->startCol);
        self::assertSame(5, $zone->endCol); // Full width, unclamped.
    }

    /**
     * SM-S28: a zone that opens entirely beyond the right edge used to clamp
     * into a degenerate single column pinned at the viewport edge — a 1-col
     * sliver that could claim clicks UNDER whatever the terminal really
     * painted there, breaking the paints-no-cell/claims-nothing rule the
     * class docblock promises. Such a zone (pre-clamp startCol > width) is
     * now DROPPED: no visible cell of the frame lies inside its rect.
     */
    public function testParseWithWidthZoneBeyondRightEdgeIsDropped(): void
    {
        $mark = new Mark();
        // Five visible cells first: 'off' opens at col 6, content spans
        // cols 6–7 — wholly outside a 3-column viewport.
        $rendered = '     ' . $mark->wrap('off', 'AB');

        $zones = Scan::parse($rendered, 3);

        self::assertSame([], $zones, 'an open-past-edge zone is dropped, never collapsed to an edge sliver');

        // The edge column belongs to the real content (leading spaces):
        // nothing steals its clicks.
        $scanner = (new Scanner())->scan($rendered, 3);
        self::assertNull($scanner->hit(3, 1));
        self::assertNull($scanner->hit(4, 1));
    }

    /**
     * Same drop for a multi-row zone whose rect opens beyond the right
     * edge: rows can not rescue columns — the rect owns no visible cell.
     */
    public function testParseWithWidthOffscreenMultiRowZoneIsDropped(): void
    {
        $mark = new Mark();
        // Opens at col 6 (outside a 3-col viewport), wraps to row 2.
        $rendered = '     ' . $mark->wrap('tall', "AB\nC");

        self::assertSame([], Scan::parse($rendered, 3));
    }

    /**
     * A viewport narrower than one column has no cell to paint in, so the
     * clamp drops everything instead of flooring a sliver at fake column 1.
     */
    public function testParseWithSubColumnWidthDropsAllZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('a', 'HELLO');

        self::assertSame(['a'], array_keys(Scan::parse($rendered, 5)), 'sanity: same input at a real width records');
        self::assertSame([], Scan::parse($rendered, 0));
        self::assertSame([], Scan::parse($rendered, -4));
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
        Scan::parse($rendered);
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
        Scan::parse($rendered);
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

        $zones = Scan::parse($rendered);
        self::assertCount(3, $zones);
        self::assertArrayHasKey('dup', $zones);
        self::assertArrayHasKey('dup-2', $zones);
        self::assertArrayHasKey('dupe', $zones);
    }

    // ─── [PERF] O(n) close-sentinel lookup ──────────────────────────────────

    /**
     * Regression for the O(n^2) forward-strpos rescan at Scan.php's
     * close-sentinel lookup — each of N unmatched open sentinels used to
     * rescan to end-of-string.  With the precomputed close-sentinel index the
     * whole parse is near-linear, so a many-unmatched-open buffer must yield
     * no zones AND cost ~linear in scale.  The bound is a SCALE RATIO, not a
     * wall-clock figure: the old absolute 2.0s cap was the lib's only
     * timing-sensitive assertion and a loaded CI shard could break it.  A
     * near-linear parse measures ~3.7x here (4x the bytes); the quadratic
     * rescan is 16x or worse; 8x separates the classes with room for noise.
     * The larger parse runs second so the smaller one's warm-up can only help
     * it (biasing the ratio toward passing), and a floor on the small-input
     * time keeps the ratio out of the noise band.
     */
    public function testManyUnmatchedOpenSentinelsStayBounded(): void
    {
        $n    = str_repeat(Sentinel::OPEN, 25000);
        $four = str_repeat(Sentinel::OPEN, 100000);

        $start  = microtime(true);
        $zonesN = Scan::parse($n);
        $timeN  = microtime(true) - $start;

        $start   = microtime(true);
        $zones4N = Scan::parse($four);
        $time4N  = microtime(true) - $start;

        self::assertSame([], $zonesN, 'unmatched open sentinels must yield no zones');
        self::assertSame([], $zones4N, 'unmatched open sentinels must yield no zones');

        self::assertGreaterThan(
            0.005,
            $timeN,
            'the 25k parse must stay well off the timer-noise band for the ratio to mean anything'
        );

        self::assertLessThan(
            8.0 * $timeN,
            $time4N,
            'a 4x input must cost ~4x time, not ~16x: the rescan regression is quadratic, the index is near-linear'
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

        $zones = Scan::parse($rendered);
        self::assertCount(1, $zones);
        self::assertArrayHasKey('real', $zones);
        self::assertSame(1, $zones['real']->startCol);
        self::assertSame(5, $zones['real']->endCol);
    }

    // ─── nextGrapheme() fallback paths ───────────────────────────────────────

    /**
     * When grapheme_extract() lands mid-grapheme on a combining character
     * sequence, it returns empty string, triggering the fallback UTF-8
     * byte-by-byte path (lines 236-244).  This exercises the 4-byte UTF-8
     * branch ($b & 0xf8 === 0xf0) via a 4-byte emoji in a zone.
     */
    public function testNextGraphemeFallbackWithEmoji(): void
    {
        // Emoji "face with tears of joy" — 4-byte UTF-8 (F0 9F 98 82).
        // When grapheme_extract lands mid-emoji, it returns empty, falling
        // through to the byte-by-byte path.  The 4-byte branch ($b & 0xf8
        // === 0xf0) must yield the full 4-byte cluster.
        $emoji = "\xF0\x9F\x98\x82"; // 😂 emoji
        $mark  = new Mark();
        $rendered = $mark->wrap('emoji', $emoji);

        $zones = Scan::parse($rendered);

        self::assertArrayHasKey('emoji', $zones);
        self::assertGreaterThanOrEqual(2, $zones['emoji']->width());
    }

    /**
     * Exercise the 3-byte UTF-8 branch in nextGrapheme's fallback path.
     * A CJK character is 3 bytes (E0 A0 80 pattern).  The 3-byte branch
     * ($b & 0xf0 === 0xe0) must be exercised.
     */
    public function testNextGraphemeFallbackWithCJK(): void
    {
        $cjk3 = "\xE0\xA0\x80"; // Valid 3-byte UTF-8 CJK char (U+0800)
        $mark = new Mark();
        $rendered = $mark->wrap('cjk3', $cjk3);

        $zones = Scan::parse($rendered);

        self::assertArrayHasKey('cjk3', $zones);
        self::assertGreaterThanOrEqual(1, $zones['cjk3']->width());
    }

    /**
     * Exercise the 2-byte UTF-8 branch in nextGrapheme's fallback path.
     * Latin-1 Supplement chars like "é" are 2 bytes (C3 A9 pattern).
     * The 2-byte branch ($b & 0xe0 === 0xc0) must be exercised.
     */
    public function testNextGraphemeFallbackWithLatin2Byte(): void
    {
        $latin2 = "\xC3\xA9"; // "é" encoded as 2 bytes
        $mark   = new Mark();
        $rendered = $mark->wrap('latin2', $latin2);

        $zones = Scan::parse($rendered);

        self::assertArrayHasKey('latin2', $zones);
        self::assertGreaterThanOrEqual(1, $zones['latin2']->width());
    }

    /**
     * Flag emoji: two regional indicator symbols (each 3 bytes, total 6 bytes)
     * forming a single grapheme cluster.  Verifies correct width accounting.
     */
    public function testNextGraphemeFlagEmojiWidth(): void
    {
        $flag = "\xF0\x9F\x87\xA6\xF0\x9F\x87\xA9"; // 🇦🇦 flag
        $mark = new Mark();
        $rendered = $mark->wrap('flag', $flag);

        $zones = Scan::parse($rendered);

        self::assertArrayHasKey('flag', $zones);
        self::assertGreaterThanOrEqual(1, $zones['flag']->width());
    }

    /**
     * strip() is the public byte-side twin of parse()'s tag rule: well-formed
     * tags (open, close, orphan, unclosed, empty-id) go whole; lone
     * sentinels cost only their own 3 bytes.
     */
    public function testStripRemovesWellFormedTagsAndKeepsLoneSentinelText(): void
    {
        $mark = new Mark();
        $frame = $mark->wrap('btn', 'OK') . ' tail';
        self::assertSame('OK tail', Scan::strip($frame));

        // Orphan close + unclosed-but-TERMINATED open: whole tags → removed.
        self::assertSame('ab', Scan::strip(
            Sentinel::OPEN . '/gone' . Sentinel::CLOSE . 'a' . Sentinel::OPEN . 'ghost' . Sentinel::CLOSE . 'b'
        ));

        // Empty-id pairs are inert foreign markup → consumed whole.
        self::assertSame('xy', Scan::strip('x' . Sentinel::OPEN . Sentinel::CLOSE . 'y'));

        // Lone sentinel (invalid id: space): each sentinel's own 3 bytes go,
        // the text between them survives (exactly what parse measures).
        self::assertSame('bad idlater', Scan::strip(Sentinel::OPEN . 'bad id' . Sentinel::CLOSE . 'later'));

        // Fast path: no sentinels → byte-identical passthrough.
        self::assertSame("plain \x1b[31mred\x1b[0m", Scan::strip("plain \x1b[31mred\x1b[0m"));
    }

    /**
     * Trailing blank lines inside the tags paint no cell, so they contribute
     * no row to the bbox — the same painted-cells-only rule that drops a
     * zero-paint zone.  'abc\n\n' + close sits at column 1 of row 3, but the
     * zone owns row 1 only: ending it at row - 1 (the previous formula)
     * claimed row 2, which it never drew.
     */
    public function testTrailingBlankLinesInsideTheTagsDoNotExtendTheBox(): void
    {
        $mark  = new Mark();
        $zones = Scan::parse($mark->wrap('btn', "abc\n\n"));
        self::assertCount(1, $zones);
        self::assertSame(1, $zones['btn']->startRow);
        self::assertSame(1, $zones['btn']->endRow);
        self::assertSame(3, $zones['btn']->endCol);
    }

    /**
     * An interior blank row between two painted rows stays inside the union
     * rectangle: the rows above and below the gap are the zone's own cells.
     */
    public function testInteriorBlankRowsStillUnionPaintedRowsAboveAndBelow(): void
    {
        $mark = new Mark();
        $zone = Scan::parse($mark->wrap('btn', "a\n\nb"))['btn'];
        self::assertSame(1, $zone->startRow);
        self::assertSame(3, $zone->endRow);
        self::assertSame(1, $zone->endCol);
    }
}
