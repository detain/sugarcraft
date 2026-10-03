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

        $zones = (new Scan())->parse($rendered);

        self::assertSame([33, 1, 36, 1], self::box($zones['z']));
    }

    public function testStraySentinelSpanningNewlinesKeepsRowTracking(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $rendered = "ab{$o}cd\nef\ngh{$c}xx\n" . Mark::zone('z', 'QQQQ');

        $zones = (new Scan())->parse($rendered);

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

        $zones = (new Scan())->parse($rendered);

        self::assertSame(['z'], array_keys($zones));
        self::assertSame([1, 1, 4, 1], self::box($zones['z']));
    }

    public function testOversizedIdFieldIsTreatedAsLoneSentinel(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        $long = str_repeat('a', Mark::MAX_ID_BYTES + 1);
        $rendered = $o . $long . $c . Mark::zone('z', 'Q');

        $zones = (new Scan())->parse($rendered);

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

        $zones = (new Scan())->parse($rendered);

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
        // 'AB' (2) + '/z z' (4) → 'C' paints at col 7.
        self::assertSame([7, 1, 7, 1], self::box($zones['y']));
    }

    // ─── line endings ───────────────────────────────────────────────────────

    public function testCrlfAdvancesRowLikeLf(): void
    {
        $crlf = (new Scan())->parse("AAAA\r\n" . Mark::zone('z', 'QQQQ'));
        $lf   = (new Scan())->parse("AAAA\n" . Mark::zone('z', 'QQQQ'));

        self::assertSame([1, 2, 4, 2], self::box($crlf['z']));
        self::assertSame(self::box($lf['z']), self::box($crlf['z']));
    }

    public function testCrlfInsideMultiRowZoneSpansBothRows(): void
    {
        $zones = (new Scan())->parse(Mark::zone('z', "ABC\r\nDE") . "\r\n" . Mark::zone('y', 'F'));

        self::assertSame([1, 1, 3, 2], self::box($zones['z']));
        self::assertSame([1, 3, 1, 3], self::box($zones['y']));
    }

    public function testLoneCarriageReturnResetsColumnOnSameRow(): void
    {
        $zones = (new Scan())->parse("AAAA\r" . Mark::zone('z', 'QQ'));

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
    }

    public function testZonePaintingAcrossLoneCarriageReturnCoversBothRuns(): void
    {
        // Opens at col 3, paints cols 3-6, CR, then paints col 1 again.
        $zones = (new Scan())->parse('XX' . Mark::zone('z', "ABCD\rQ"));

        self::assertSame([1, 1, 6, 1], self::box($zones['z']));
    }

    public function testZoneEndingWithLoneCarriageReturnKeepsItsExtent(): void
    {
        $zones = (new Scan())->parse('XX' . Mark::zone('z', "AB\r") . Mark::zone('y', 'Q'));

        self::assertSame([3, 1, 4, 1], self::box($zones['z']));
        self::assertSame([1, 1, 1, 1], self::box($zones['y']));
    }

    public function testZoneOpeningMidRowWithLeadingCarriageReturnHasNoPhantomStartCell(): void
    {
        // z opens at col 10 but paints nothing there: the CR sends it back
        // to col 1 and it paints cols 1-3 only.
        $zones = (new Scan())->parse('123456789' . Mark::zone('z', "\rabc"));

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
        $zones = (new Scan())->parse('ABCD' . Mark::zone('z', "ef\rgh\r"));

        self::assertSame([1, 1, 6, 1], self::box($zones['z']));
    }

    public function testRepeatedLeadingCarriageReturnsKeepOnlyPaintedExtent(): void
    {
        $zones = (new Scan())->parse('12345678' . Mark::zone('z', "\r\rab"));

        self::assertSame([1, 1, 2, 1], self::box($zones['z']));
    }

    public function testLeadingCarriageReturnThenNewlineSpansOnlyPaintedColumns(): void
    {
        $zones = (new Scan())->parse('123456789' . Mark::zone('z', "\rab\ncd"));

        self::assertSame([1, 1, 2, 2], self::box($zones['z']));
    }

    // ─── empty-content zones ────────────────────────────────────────────────

    public function testEmptyContentZoneIsNotEmitted(): void
    {
        $zones = (new Scan())->parse('AAAA' . Mark::zone('e', '') . 'B');

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
        $zones = (new Scan())->parse('AA' . Mark::zone('e', "\x1b[1m\x1b[0m") . 'B');

        self::assertSame([], $zones);
    }

    public function testDroppedEmptyZoneIdStillCountsForDuplicateGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'e'");
        (new Scan())->parse(Mark::zone('e', '') . Mark::zone('e', 'x'));
    }

    // ─── documented lenient cases ───────────────────────────────────────────

    public function testOrphanCloseIsIgnoredWithoutThrowing(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // A frame clipped below a zone's open tag still carries its close.
        $zones = (new Scan())->parse("AA{$o}/gone{$c}" . Mark::zone('z', 'Q'));

        self::assertSame([3, 1, 3, 1], self::box($zones['z']));
        self::assertCount(1, $zones);
    }

    public function testUnclosedOpenIsIgnoredWithoutThrowing(): void
    {
        $o = Sentinel::OPEN;
        $c = Sentinel::CLOSE;
        // A frame clipped above a zone's close tag still carries its open.
        $zones = (new Scan())->parse(Mark::zone('z', 'Q') . "{$o}cut{$c}BB");

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
        $scan = new Scan();
        try {
            $scan->parse(Mark::zone('d', 'A') . Mark::zone('d', 'B'));
            self::fail('duplicate id must throw');
        } catch (\InvalidArgumentException) {
        }

        $zones = $scan->parse(Mark::zone('d', 'A'));

        self::assertSame([1, 1, 1, 1], self::box($zones['d']));
    }
}
