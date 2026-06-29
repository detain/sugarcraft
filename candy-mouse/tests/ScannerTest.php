<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;

final class ScannerTest extends TestCase
{
    // ─── Basic zone discovery ──────────────────────────────────────────────

    public function testScanDiscoversSingleZone(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('btn', 'hello');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('btn');

        self::assertNotNull($zone);
        self::assertSame('btn', $zone->id);
    }

    public function testScanSetsCorrectBounds(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('box', 'ABC');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('box');

        self::assertNotNull($zone);
        self::assertSame(1, $zone->startCol);
        self::assertSame(1, $zone->startRow);
        // Width = 3 columns for 'ABC'.
        self::assertSame(3, $zone->width());
    }

    public function testScanHandlesMultipleZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('a', 'first') . "\n" . $mark->wrap('b', 'second');

        $scanner = Scanner::new()->scan($rendered);
        $all = $scanner->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('a', $all);
        self::assertArrayHasKey('b', $all);
    }

    public function testScanClearsPreviousZones(): void
    {
        $mark = new Mark();
        $first = $mark->wrap('zone', 'one');

        $scanner = Scanner::new()->scan($first);
        self::assertNotNull($scanner->get('zone'));

        $scanner->scan($mark->wrap('other', 'two'));
        self::assertNull($scanner->get('zone'));
    }

    // ─── Newline and positioning ──────────────────────────────────────────

    public function testScanAdvancesRowOnNewline(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('cell', "L1\nL2");

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('cell');

        self::assertNotNull($zone);
        self::assertSame(1, $zone->startRow);
        self::assertGreaterThanOrEqual(2, $zone->endRow);
    }

    public function testScanPositionsAfterNewline(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('after', "line1\n     after");

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('after');

        self::assertNotNull($zone);
        // The zone starts where the opening marker was placed (row 1, col 1).
        // The zone ENDS at row 2 (after processing the content through newline+spaces).
        self::assertGreaterThanOrEqual(2, $zone->endRow);
    }

    // ─── hit() — reverse lookup ───────────────────────────────────────────

    public function testHitReturnsZoneForCoordinatesInside(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('btn', 'HELLO');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->hit(2, 1);

        self::assertNotNull($zone);
        self::assertSame('btn', $zone->id);
    }

    public function testHitReturnsNullForCoordinatesOutside(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('btn', 'X');

        $scanner = Scanner::new()->scan($rendered);
        // Way outside the zone.
        $zone = $scanner->hit(999, 999);

        self::assertNull($zone);
    }

    public function testHitReturnsNullForEmptyScanner(): void
    {
        $scanner = Scanner::new();
        self::assertNull($scanner->hit(1, 1));
    }

    public function testHitPrefersFirstZoneWhenOverlapping(): void
    {
        $mark = new Mark();
        // Two zones that overlap slightly.
        $rendered = $mark->wrap('first', 'OVERLAP') . $mark->wrap('second', 'LAP');

        $scanner = Scanner::new()->scan($rendered);
        // Both zones contain the 'O' region — first registered should win.
        $zone = $scanner->hit(1, 1);
        self::assertSame('first', $zone?->id);
    }

    // ─── Wide characters (CJK) ──────────────────────────────────────────────

    public function testScanAccountsForWideChars(): void
    {
        $mark = new Mark();
        // CJK character is 2 cells wide.
        $rendered = $mark->wrap('wide', '日本語');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('wide');

        self::assertNotNull($zone);
        // 3 CJK chars × 2 cells each = 6 columns.
        self::assertSame(6, $zone->width());
    }

    // ─── all() ─────────────────────────────────────────────────────────────

    public function testAllReturnsAllZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('a', 'A') . $mark->wrap('b', 'B') . $mark->wrap('c', 'C');

        $scanner = Scanner::new()->scan($rendered);
        $all = $scanner->all();

        self::assertCount(3, $all);
    }

    // ─── prefixed() ─────────────────────────────────────────────────────────

    public function testPrefixedReturnsMatchingZones(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('item-0', 'FIRST')
            . $mark->wrap('item-1', 'SECOND')
            . $mark->wrap('btn-ok', 'OK');

        $scanner = Scanner::new()->scan($rendered);
        $prefixed = $scanner->prefixed('item-');

        self::assertCount(2, $prefixed);
        self::assertArrayHasKey('item-0', $prefixed);
        self::assertArrayHasKey('item-1', $prefixed);
        self::assertArrayNotHasKey('btn-ok', $prefixed);
    }

    public function testPrefixedReturnsEmptyArrayForNoMatch(): void
    {
        $mark = new Mark();
        $scanner = Scanner::new()->scan($mark->wrap('item-0', 'X'));

        $result = $scanner->prefixed('none-');
        self::assertSame([], $result);
    }

    public function testPrefixedReturnsEmptyArrayForEmptyScanner(): void
    {
        $scanner = Scanner::new();
        self::assertSame([], $scanner->prefixed('item-'));
    }

    // ─── clear() ────────────────────────────────────────────────────────────

    public function testClearEmptiesZoneRegistry(): void
    {
        $mark = new Mark();
        $scanner = Scanner::new()->scan($mark->wrap('z', 'test'));
        self::assertNotNull($scanner->get('z'));

        $scanner->clear();
        self::assertNull($scanner->get('z'));
    }

    /**
     * After clear(), a new scan() call produces fresh zones and the old
     * zone ids are gone.  This differs from the empty-after-clear test
     * which only asserts the registry is empty — this verifies the
     * replacement content is actually present.
     */
    public function testClearThenRescanProducesFreshZones(): void
    {
        $mark = new Mark();

        $scanner = Scanner::new();
        $scanner->scan($mark->wrap('first', 'FIRST'));
        self::assertNotNull($scanner->get('first'));

        $scanner->clear();
        $scanner->scan($mark->wrap('second', 'SECOND'));
        // Old zone must be gone.
        self::assertNull($scanner->get('first'));
        // New zone must be present.
        $zone = $scanner->get('second');
        self::assertNotNull($zone);
        self::assertSame('second', $zone->id);
    }

    // ─── Zone helpers ──────────────────────────────────────────────────────

    public function testZoneWidthAndHeight(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('box', "ABC\nDEF");

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('box');

        self::assertNotNull($zone);
        self::assertGreaterThanOrEqual(3, $zone->width());
        self::assertGreaterThanOrEqual(2, $zone->height());
    }

    public function testZonePosRelative(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('r', 'XYZ');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('r');

        self::assertNotNull($zone);
        $event = MouseEvent::press(3, 1);
        [$col, $row] = $zone->pos($event);
        self::assertSame(2, $col); // 3 - startCol(1) = 2
        self::assertSame(0, $row); // 1 - startRow(1) = 0
    }

    public function testZoneInBoundsTrue(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('b', 'TEST');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('b');

        self::assertNotNull($zone);
        $event = MouseEvent::press(2, 1);
        self::assertTrue($zone->inBounds($event));
    }

    public function testZoneInBoundsFalse(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('b', 'TEST');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('b');

        self::assertNotNull($zone);
        $event = MouseEvent::press(999, 999);
        self::assertFalse($zone->inBounds($event));
    }

    public function testZoneIsZeroFalseForRealZone(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('z', 'x');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('z');

        self::assertNotNull($zone);
        self::assertFalse($zone->isZero());
    }

    /**
     * A manually-constructed zero-valued zone (all coords = 0) reports
     * isZero() === true.  This is upstream-parity only — scanned zones
     * are always 1-based and never have isZero() === true.
     */
    public function testIsZeroTrueForZeroValuedZone(): void
    {
        $zone = new Zone('zero', 0, 0, 0, 0);
        self::assertTrue($zone->isZero());
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $mark = new Mark();
        $scanner = Scanner::new()->scan($mark->wrap('a', 'x'));

        self::assertNull($scanner->get('nonexistent'));
    }

    // ─── Scan class coverage — edge cases ───────────────────────────────────

    public function testScanParsesZoneWithEmbeddedCSI(): void
    {
        $mark = new Mark();
        $rendered = "\x1b[31m" . $mark->wrap('red', 'RED') . "\x1b[0m";

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('red');

        self::assertNotNull($zone);
        self::assertSame('red', $zone->id);
    }

    public function testScanParsesZoneWithEmbeddedOSC(): void
    {
        $mark = new Mark();
        $rendered = "\x1b]5;1\x07" . $mark->wrap('osc', 'OSC') . "\x1b\\";

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('osc');

        self::assertNotNull($zone);
        self::assertSame('osc', $zone->id);
    }

    public function testScanHandlesUnterminatedSentinel(): void
    {
        $mark = new Mark();
        // Intentionally malformed: missing closing U+E001.
        $rendered = $mark->wrap('bad', 'BAD') . "\xEE\x80\x80unclosed";

        $scanner = Scanner::new()->scan($rendered);
        // The well-formed zone should still be discovered.
        self::assertNotNull($scanner->get('bad'));
    }

    public function testScanWideCharsWideColumnAccounting(): void
    {
        $mark = new Mark();
        // CJK: each char is 2 columns; 3 chars = 6 columns.
        $rendered = $mark->wrap('cjk', '日本語');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('cjk');

        self::assertNotNull($zone);
        self::assertSame(6, $zone->width());
    }

    public function testScannerAllReturnsEmptyForNoZones(): void
    {
        $scanner = Scanner::new();
        self::assertEmpty($scanner->all());
    }

    public function testScanEmptyStringReturnsNoZones(): void
    {
        $scanner = Scanner::new()->scan('');
        self::assertEmpty($scanner->all());
    }

    public function testScanNoMarkersReturnsEmpty(): void
    {
        $scanner = Scanner::new()->scan('plain text with no markers');
        self::assertEmpty($scanner->all());
    }

    public function testScanTrailingPartialSentinelIsIgnored(): void
    {
        $mark = new Mark();
        // U+E000 without following id and close marker.
        $rendered = $mark->wrap('partial', 'PARTIAL') . "\xEE\x80";

        $scanner = Scanner::new()->scan($rendered);
        // The well-formed zone should still be discovered.
        $zone = $scanner->get('partial');
        self::assertNotNull($zone);
    }

    public function testScanCSIPassThroughMiddleOfZone(): void
    {
        $mark = new Mark();
        // CSI in the middle of zone content.
        $rendered = $mark->wrap('mixed', "AB\x1b[31mCD\x1b[0mEF");
        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('mixed');

        self::assertNotNull($zone);
        // Full content "AB[CSI]CDEF" has 6 visible chars (A,B,C,D,E,F).
        // CSI \x1b[31m and \x1b[0m have 0 width.
        self::assertSame(6, $zone->width());
    }

    public function testScanOSCPassThrough(): void
    {
        $mark = new Mark();
        // OSC sequence before zone content.
        $rendered = "\x1b]4;0;rgb:00/00/00\x07" . $mark->wrap('osc2', 'OSC2');
        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('osc2');

        self::assertNotNull($zone);
    }

    public function testScanNewlineAdvancesRowAndResetsCol(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('nl', "ROW1\nROW2");
        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('nl');

        self::assertNotNull($zone);
        // ROW1 has 4 chars, newline, ROW2 has 4 chars.
        self::assertSame(1, $zone->startRow);
        self::assertSame(2, $zone->endRow);
    }

    public function testScanMultipleNewlines(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('multinl', "A\nB\nC");
        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('multinl');

        self::assertNotNull($zone);
        // Should cover rows 1 through 3.
        self::assertGreaterThanOrEqual(3, $zone->endRow);
    }
}
