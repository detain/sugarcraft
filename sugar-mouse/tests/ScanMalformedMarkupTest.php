<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * Coordinate-exactness of {@see Scan::parse()} on markup Mark never emits
 * (stray sentinels, invalid ids), on CR/CRLF line endings, and on
 * empty-content zones — each of which used to register zones at the wrong
 * cell, which is worse than failing: clicks fire the wrong zone.
 */
final class ScanMalformedMarkupTest extends TestCase
{
    /** @return array{int,int,int,int} [startCol, startRow, endCol, endRow] */
    private static function box(Zone $z): array
    {
        return [$z->startCol, $z->startRow, $z->endCol, $z->endRow];
    }

    // ─── stray sentinels / invalid ids ──────────────────────────────────────

    public function testStraySentinelPairDoesNotSwallowTextColumns(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // 'ab' + 'cd efgh ij' (10) + 20 x = 32 visible cells before the zone.
        // The would-be id 'cd efgh ij' contains spaces, so the U+E000 is a
        // lone sentinel and the text after it is measured, not skipped.
        $rendered = "ab{$o}cd efgh ij{$c}" . str_repeat('x', 20) . Mark::zone('z', 'QQQQ');

        $zones = Scan::parse($rendered);

        self::assertSame([33, 1, 36, 1], self::box($zones['z']));
    }

    public function testStraySentinelSpanningNewlinesKeepsRowTracking(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $rendered = "ab{$o}cd\nef\ngh{$c}xx\n" . Mark::zone('z', 'QQQQ');

        $zones = Scan::parse($rendered);

        self::assertSame([1, 4, 4, 4], self::box($zones['z']));
    }

    public function testEmptyIdSentinelPairsAreInertAndDoNotThrow(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // Two bare open+close pairs — exactly what a U+E000-based image marker
        // pair emits — used to open two empty-id zones and trip the duplicate
        // guard, losing every zone in the frame.
        $rendered = "{$o}{$c}{$o}{$c}{$o}/{$c}" . Mark::zone('z', 'QQQQ');

        $zones = Scan::parse($rendered);

        self::assertSame(['z'], array_keys($zones));
        self::assertSame([1, 1, 4, 1], self::box($zones['z']));
    }

    public function testOversizedIdFieldIsTreatedAsLoneSentinel(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $long = str_repeat('a', Mark::MAX_ID_BYTES + 1);
        $rendered = $o . $long . $c . Mark::zone('z', 'Q');

        $zones = Scan::parse($rendered);

        self::assertSame(['z'], array_keys($zones));
        self::assertSame(Mark::MAX_ID_BYTES + 2, $zones['z']->startCol);
    }

    public function testInvalidIdCloseTagDoesNotCloseAZone(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // A forged close whose id has a space must not be read as the close
        // of anything, and its text is measured as visible cells.
        $rendered = Mark::zone('z', 'AB') . "{$o}/z z{$c}" . Mark::zone('y', 'C');

        $zones = Scan::parse($rendered);

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
        // 'AB' (2) + '/z z' (4) → 'C' paints at col 7.
        self::assertSame([7, 1, 7, 1], self::box($zones['y']));
    }

    // ─── line endings ───────────────────────────────────────────────────────

    public function testCrlfAdvancesRowLikeLf(): void
    {
        $crlf = Scan::parse("AAAA\r\n" . Mark::zone('z', 'QQQQ'));
        $lf   = Scan::parse("AAAA\n" . Mark::zone('z', 'QQQQ'));

        self::assertSame([1, 2, 4, 2], self::box($crlf['z']));
        self::assertSame(self::box($lf['z']), self::box($crlf['z']));
    }

    public function testCrlfInsideMultiRowZoneSpansBothRows(): void
    {
        $zones = Scan::parse(Mark::zone('z', "ABC\r\nDE") . "\r\n" . Mark::zone('y', 'F'));

        self::assertSame([1, 1, 3, 2], self::box($zones['z']));
        self::assertSame([1, 3, 1, 3], self::box($zones['y']));
    }

    public function testLoneCarriageReturnResetsColumnOnSameRow(): void
    {
        $zones = Scan::parse("AAAA\r" . Mark::zone('z', 'QQ'));

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
    }

    public function testZonePaintingAcrossLoneCarriageReturnCoversBothRuns(): void
    {
        // Opens at col 3, paints cols 3-6, CR, then paints col 1 again.
        $zones = Scan::parse('XX' . Mark::zone('z', "ABCD\rQ"));

        self::assertSame([1, 1, 6, 1], self::box($zones['z']));
    }

    public function testZoneEndingWithLoneCarriageReturnKeepsItsExtent(): void
    {
        $zones = Scan::parse('XX' . Mark::zone('z', "AB\r") . Mark::zone('y', 'Q'));

        self::assertSame([3, 1, 4, 1], self::box($zones['z']));
        self::assertSame([1, 1, 1, 1], self::box($zones['y']));
    }

    public function testZoneOpeningMidRowWithLeadingCarriageReturnHasNoPhantomStartCell(): void
    {
        // z opens at col 10 but paints nothing there: the CR sends it back
        // to col 1 and it paints cols 1-3 only.
        $zones = Scan::parse('123456789' . Mark::zone('z', "\rabc"));

        self::assertSame([1, 1, 3, 1], self::box($zones['z']));
    }

    public function testLeadingCarriageReturnZoneDoesNotStealNeighbourCells(): void
    {
        $scanner = Scanner::new()->scan(
            Mark::zone('n', '123456789') . Mark::zone('z', "\rabc") . 'X'
        );

        self::assertNull($scanner->hit(10, 1), 'col 10 is painted by the trailing X, not z');
        self::assertSame('n', $scanner->hit(5, 1)?->id, 'cols 4-9 are n alone');
        self::assertSame([1, 1, 3, 1], self::box($scanner->get('z')));
    }

    public function testZonePaintingOnlyBeforeAnEarlierCarriageReturnStillStartsAtColumnOne(): void
    {
        // Opens at col 5, paints 5-6, CR, paints 1-2, CR, paints nothing:
        // the run after the FIRST CR still landed from col 1.
        $zones = Scan::parse('ABCD' . Mark::zone('z', "ef\rgh\r"));

        self::assertSame([1, 1, 6, 1], self::box($zones['z']));
    }

    public function testRepeatedLeadingCarriageReturnsKeepOnlyPaintedExtent(): void
    {
        $zones = Scan::parse('12345678' . Mark::zone('z', "\r\rab"));

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
    }

    public function testLeadingCarriageReturnThenNewlineSpansOnlyPaintedColumns(): void
    {
        $zones = Scan::parse('123456789' . Mark::zone('z', "\rab\ncd"));

        self::assertSame([1, 1, 2, 2], self::box($zones['z']));
    }

    public function testZoneOpeningMidRowWithLeadingNewlineCoversOnlyItsPaintedRow(): void
    {
        // z opens at col 10 of row 1 but paints nothing there: its first cell
        // is col 1 of row 2.  It used to keep the open column, giving a
        // 1-col sliver at col 10 of rows 1-2 that missed 'abc' entirely.
        $zones = Scan::parse('123456789' . Mark::zone('z', "\nabc"));

        self::assertSame([1, 2, 3, 2], self::box($zones['z']));
    }

    public function testLeadingNewlineZoneOpenedShortOfItsContentWidthCoversOnlyItsPaintedRow(): void
    {
        // Opened at col 3 of row 1, content wider than that on row 2.  The
        // open column used to survive as startCol (cols 3-6, rows 1-2 —
        // bubblezone's Start(2,0) End(5,1)), claiming unpainted row-1 cells
        // and missing the painted 'ab' at cols 1-2 of row 2.
        $scanner = Scanner::new()->scan(Mark::zone('n', '12') . Mark::zone('z', "\nabcdef"));

        self::assertSame([1, 2, 6, 2], self::box($scanner->get('z')));
        self::assertNull($scanner->hit(3, 1), 'z painted nothing on row 1');
        self::assertSame('z', $scanner->hit(1, 2)?->id);
    }

    public function testLeadingNewlineZoneOpenedAtColumnOneCoversOnlyItsPaintedRow(): void
    {
        // The re-anchor is not limited to mid-row opens: opened at column 1,
        // z still paints nothing on row 1.  It used to span rows 1-2 (cols
        // 1-3 — bubblezone's Start(0,0) End(2,1)), claiming blank row-1 cells.
        $zones = Scan::parse(Mark::zone('z', "\nabc"));
        self::assertSame([1, 2, 3, 2], self::box($zones['z']));

        // Column 1 of a later row, behind SGR: same rule.
        $zones = Scan::parse("XY\n" . Mark::zone('z', "\x1b[1m\nabc"));
        self::assertSame([1, 3, 3, 3], self::box($zones['z']));
    }

    public function testLeadingNewlineZoneMatchesZoneOpenedWhereItsContentLands(): void
    {
        // Moving the zero-width open tag across the paint-free "\n" must not
        // change the bbox — the anchor is the first painted cell.
        $leading = Scan::parse('123456789' . Mark::zone('z', "\nabc"));
        $moved   = Scan::parse("123456789\n" . Mark::zone('z', 'abc'));

        self::assertSame(self::box($moved['z']), self::box($leading['z']));
    }

    public function testLeadingNewlineZoneDoesNotStealNeighbourCells(): void
    {
        $scanner = Scanner::new()->scan(
            Mark::zone('n', '123456789') . Mark::zone('z', "\nabc") . 'X'
        );

        self::assertSame('n', $scanner->hit(9, 1)?->id);
        self::assertNull($scanner->hit(10, 1), 'z painted nothing on row 1');
        self::assertNull($scanner->hit(10, 2), "col 10 of row 2 is beyond 'abc'");
        self::assertSame('z', $scanner->hit(2, 2)?->id);
        self::assertNull($scanner->hit(4, 2), 'col 4 of row 2 is the trailing X');
    }

    public function testSeveralLeadingNewlinesAndZeroWidthBytesSkipToFirstPaintedRow(): void
    {
        // SGR and blank rows paint nothing; the zone starts on row 3.
        $zones = Scan::parse('12345' . Mark::zone('z', "\x1b[1m\n\nab\ncdef\x1b[0m"));

        self::assertSame([1, 3, 4, 4], self::box($zones['z']));
    }

    public function testLeadingNewlineZoneOpenedPastViewportEdgeStaysInside(): void
    {
        $zones = Scan::parse('123456789' . Mark::zone('z', "\nab"), 5);

        self::assertSame([1, 2, 2, 2], self::box($zones['z']));
    }

    public function testLeadingNewlineOnlyZoneIsStillNotEmitted(): void
    {
        $zones = Scan::parse('12345' . Mark::zone('z', "\n\n") . 'B');

        self::assertSame([], $zones);
    }

    // ─── empty-content zones ────────────────────────────────────────────────

    public function testEmptyContentZoneIsNotEmitted(): void
    {
        $zones = Scan::parse('AAAA' . Mark::zone('e', '') . 'B');

        self::assertSame([], $zones);
    }

    public function testEmptyContentZoneDoesNotStealNeighbourClick(): void
    {
        $scanner = Scanner::new()->scan('AAAA' . Mark::zone('e', '') . Mark::zone('b', 'B'));

        self::assertSame('b', $scanner->hit(5, 1)?->id);
        self::assertNull($scanner->get('e'));
    }

    public function testZeroWidthOnlyContentZoneIsNotEmitted(): void
    {
        // An SGR-only payload paints no cell either.
        $zones = Scan::parse('AA' . Mark::zone('e', "\x1b[1m\x1b[0m") . 'B');

        self::assertSame([], $zones);
    }

    public function testDroppedEmptyZoneIdStillCountsForDuplicateGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'e'");
        Scan::parse(Mark::zone('e', '') . Mark::zone('e', 'x'));
    }

    // ─── documented lenient cases ───────────────────────────────────────────

    public function testOrphanCloseIsIgnoredWithoutThrowing(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // A frame clipped below a zone's open tag still carries its close.
        $zones = Scan::parse("AA{$o}/gone{$c}" . Mark::zone('z', 'Q'));

        self::assertSame([3, 1, 3, 1], self::box($zones['z']));
        self::assertCount(1, $zones);
    }

    public function testUnclosedOpenIsIgnoredWithoutThrowing(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // A frame clipped above a zone's close tag still carries its open.
        $zones = Scan::parse(Mark::zone('z', 'Q') . "{$o}cut{$c}BB");

        self::assertSame(['z'], array_keys($zones));
    }

    // ─── statelessness ──────────────────────────────────────────────────────

    public function testScanCarriesNoInstanceState(): void
    {
        $properties = (new \ReflectionClass(Scan::class))->getProperties();

        self::assertSame([], $properties, 'Scan::parse() must keep all scan state in locals (reentrant).');
    }

    public function testOneInstanceIsReusableAfterAThrow(): void
    {
        try {
            Scan::parse(Mark::zone('d', 'A') . Mark::zone('d', 'B'));
            self::fail('duplicate id must throw');
        } catch (\InvalidArgumentException) {
        }

        $zones = Scan::parse(Mark::zone('d', 'A'));

        self::assertSame([1, 1, 1, 1], self::box($zones['d']));
    }

    // ─── escape-sequence resync (ESC cancel / C0 distribution) ──────────────

    public function testEscMidCsiCancelsAndNextSequenceIsRead(): void
    {
        // A truncated CSI (params but no final byte) hit by a fresh ESC: the
        // old walk swallowed the ESC and terminated on the next '[' — the
        // rest of the cancel-introducing sequence then painted as text and
        // the zone registered two phantom columns.
        $zones = Scan::parse('12345' . Mark::zone('z', "\x1b[3\x1b[0mAB"));

        self::assertSame([6, 1, 7, 1], self::box($zones['z']), 'AB paints cols 6-7; "\x1b[0m" is consumed as a sequence');
    }

    public function testCrMidOscEndsSequenceAndIsDistributed(): void
    {
        // A malformed OSC (no BEL/ST before a CR): the walk must stop before
        // the CR so the carriage-return branch resets the column, and the
        // text after it must be measured, not swallowed to end of render.
        $zones = Scan::parse("AA\x1b]0;title\rBB" . Mark::zone('z', 'Q'));

        self::assertSame(['z'], array_keys($zones), 'the CR must not keep the OSC walk alive into the zone tag');
        self::assertSame([3, 1, 3, 1], self::box($zones['z']), 'CR distributed: BB is back at cols 1-2, so z paints col 3');
    }

    public function testLfMidOscEndsSequenceAndAdvancesRow(): void
    {
        $zones = Scan::parse("AA\x1b]0;t\nBB" . Mark::zone('z', 'Q'));

        self::assertSame([3, 2, 3, 2], self::box($zones['z']), 'the LF is distributed: BB lands on row 2, z follows it');
    }

    public function testEscMidDcsCancelsAndNextSequenceIsRead(): void
    {
        $zones = Scan::parse('XY' . "\x1bPq#0;2\x1b[0m" . Mark::zone('z', 'Q'));

        self::assertSame([3, 1, 3, 1], self::box($zones['z']), 'ESC cancels the DCS; the SGR reset is then consumed as a sequence, not text');
    }

    public function testCrlfInsideDcsPayloadStayConsumed(): void
    {
        // Deliberate divergence from the OSC behaviour: CR/LF inside a DCS
        // string are legitimate sixel line-control bytes (this is exactly the
        // payload the branch exists to pass through).  Ending the walk on one
        // would measure the graphics data as visible text and drift every
        // zone below the image.
        $zones = Scan::parse('AB' . "\x1bPq#0;2;0\r\n?1;1;0,64#1;2;0,64\x1b\\" . Mark::zone('z', 'Q'));

        self::assertSame([3, 1, 3, 1], self::box($zones['z']), 'a sixel with CRLF payload stays zero-width');
    }

    public function testWellFormedSequencesUnchangedByResync(): void
    {
        // Benign-input guard: final-byte CSI, BEL-terminated OSC and ST-
        // terminated OSC 8 hyperlink all walk exactly as before.
        $frame = "AB\x1b[1m\x1b]0;title\x07\x1b]8;;http://example.com\x1b\\CD" . Mark::zone('z', 'Q');

        $zones = Scan::parse($frame);

        self::assertSame([5, 1, 5, 1], self::box($zones['z']), 'ABCD paints cols 1-4; z paints col 5');
    }
}
