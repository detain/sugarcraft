<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\RawMsg;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Theme;
use SugarCraft\Forms\ItemList\LoadMoreMsg;

/**
 * E744 WS4/WS5: the session picker's adopted ItemList model — the load-more
 * edge, mouse forwarding, page growth, and the pure zone-line map the click
 * registry is derived from.
 *
 * The picker's OWN behaviour (actions, chrome, filters, scroll window) is
 * pinned by {@see SessionPickerTest}; this file pins only what the widget
 * adoption added or changed underneath that surface.
 *
 * @internal
 */
final class SessionPickerWidgetTest extends TestCase
{
    /** @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> */
    private static function rows(int $count): array
    {
        $sessions = [];
        for ($i = 0; $i < $count; $i++) {
            $sessions[] = [
                'sessionId' => "session-$i",
                'sessionName' => "Session $i",
                'summary' => "Summary $i",
                'gitBranch' => null,
                'lastActivity' => '2024-01-01T00:00:00Z',
            ];
        }

        return $sessions;
    }

    // =========================================================================
    // The load-more edge (WS5)
    // =========================================================================

    public function testArrivingOnTheLastRowRaisesTheLoadMoreCmd(): void
    {
        $picker = SessionPicker::new(self::rows(3), 3, moreInStore: true);

        [$atEnd, $action, $edge] = $picker->handleKey('down');
        self::assertSame(1, $atEnd->selectedIndex());
        self::assertNull($edge, 'mid-list navigation raises nothing');

        [$last, , $edge2] = $atEnd->handleKey('down');
        self::assertSame('browse', $action);
        self::assertSame(2, $last->selectedIndex(), 'arrived on the last loaded row');
        self::assertNotNull($edge2, 'while more exist upstream');
        self::assertInstanceOf(LoadMoreMsg::class, $edge2());

        [$away, , $quiet] = $last->handleKey('up');
        self::assertSame(1, $away->selectedIndex());
        self::assertNull($quiet, 'leaving the end is quiet');
    }

    public function testRestingOnTheLastRowStaysQuiet(): void
    {
        $picker = SessionPicker::new(self::rows(3), 3, moreInStore: true)->withSelectedIndex(2);

        [$same, , $cmd] = $picker->handleKey('down');
        self::assertSame(2, $same->selectedIndex(), 'clamped at the end');
        self::assertNull($cmd, 'the edge is an ARRIVAL signal, and a clamped press never arrived anywhere');
    }

    public function testEdgeIsQuietWhenTheStoreIsExhausted(): void
    {
        $picker = SessionPicker::new(self::rows(3));
        self::assertFalse($picker->needsStoreFetch());
        [$p1] = $picker->handleKey('down');
        [$p2] = $p1->handleKey('down');
        [, , $cmd] = $p2->handleKey('down');
        self::assertNull($cmd, 'hasMore=false: the last row is simply the end of the list');
    }

    // =========================================================================
    // Page growth (WS5)
    // =========================================================================

    public function testWithFetchedRowsKeepsTheCursorAndRearmsTheEdge(): void
    {
        $picker = SessionPicker::new(self::rows(3), 3, moreInStore: true);
        [$p1] = $picker->handleKey('down');
        [$atEnd, , $edge] = $p1->handleKey('down');
        self::assertNotNull($edge, 'fixture: arrived on the end of the 3-row page');

        $grew = $atEnd->withFetchedRows(self::rows(5), 5, false);
        self::assertSame(2, $grew->selectedIndex(), 'growth is not navigation: the cursor stays underfoot');
        self::assertSame(5, $grew->count());
        self::assertSame(5, $grew->fetchLimit());
        self::assertFalse($grew->needsStoreFetch(), 'a short fetch closes the edge forever');

        [, , $cmd] = $grew->handleKey('down');
        self::assertSame(3, $grew->handleKey('down')[0]->selectedIndex());
        self::assertNull($cmd, 'and walking on never refires it');
    }

    public function testNextFetchLimitWidensByOnePage(): void
    {
        $picker = SessionPicker::new(self::rows(2), SessionPicker::PAGE_SIZE, true);
        self::assertSame(SessionPicker::PAGE_SIZE + SessionPicker::PAGE_SIZE, $picker->nextFetchLimit());
    }

    // =========================================================================
    // Mouse forwarding (WS4)
    // =========================================================================

    public function testClickSelectsTheRowNamedAndMovesNothingElse(): void
    {
        $picker = SessionPicker::new(self::rows(4));
        [$clicked, $cmd] = $picker->updateClick(3);
        self::assertSame(3, $clicked->selectedIndex());
        self::assertNull($cmd, 'clicks arrive mid-list: no edge on a jump');

        [$same, $noCmd] = $picker->updateClick(9);
        self::assertSame($picker, $same, 'a row beyond the list selects nothing');
        self::assertNull($noCmd);

        [$also, ] = $picker->updateClick(-1);
        self::assertSame($picker, $also);
    }

    public function testClickOnTheLastLoadedRowFiresTheEdgeToo(): void
    {
        $picker = SessionPicker::new(self::rows(3), 3, moreInStore: true);
        [$clicked, $cmd] = $picker->updateClick(2);
        self::assertSame(2, $clicked->selectedIndex());
        self::assertNotNull($cmd, 'the widget funnels its mouse arm through the same load-more epilogue');
        self::assertInstanceOf(LoadMoreMsg::class, $cmd());
    }

    public function testWheelStepsTheSelectionAndFiresTheEdge(): void
    {
        $picker = SessionPicker::new(self::rows(2), 2, moreInStore: true);
        [$down, $cmd] = $picker->updateWheel(MouseButton::WheelDown);
        self::assertSame(1, $down->selectedIndex());
        self::assertInstanceOf(LoadMoreMsg::class, $cmd());

        [$up, $quiet] = $picker->updateWheel(MouseButton::WheelUp);
        self::assertSame(0, $up->selectedIndex());
        self::assertNull($quiet, 'the first row is not an edge');
    }

    // =========================================================================
    // Zone-line map + geometry (WS4)
    // =========================================================================

    public function testRowZoneLinesAreThePaintedRowsKeyedByFilteredRow(): void
    {
        $theme = Theme::byName('dark');
        $picker = SessionPicker::new(self::rows(3));

        $lines = $picker->rowZoneLines(60, 20, $theme);
        self::assertSame([0, 1, 2], array_keys($lines), 'keys are absolute filtered indices, in paint order');
        $rendered = $picker->render(60, 20, $theme);
        foreach ($lines as $row => $line) {
            self::assertStringContainsString(
                $line,
                $rendered,
                "zone line for row {$row} must appear verbatim in the painted overlay",
            );
            self::assertStringContainsString("Session {$row}", $line, 'and must be the right row');
        }
    }

    public function testRowZoneLinesTrackTheBranchFilterWindow(): void
    {
        $theme = Theme::byName('dark');
        $rows = self::rows(4);
        $rows[0]['gitBranch'] = 'main';
        $rows[3]['gitBranch'] = 'main';
        $picker = SessionPicker::new($rows)->withBranchFilter('main');

        self::assertSame([0, 1], array_keys($picker->rowZoneLines(60, 20, $theme)));
    }

    public function testEmptyPickerPaintsNoZoneLines(): void
    {
        $theme = Theme::byName('dark');
        $picker = SessionPicker::new([]);
        self::assertSame([], $picker->rowZoneLines(60, 20, $theme));
        self::assertStringContainsString('(no sessions)', $picker->render(60, 20, $theme));
    }

    public function testOverlayGeometrySizes(): void
    {
        self::assertSame([76, 36], SessionPicker::overlayGeometry(100, 40, 6));
        // Both floors: a 30-column terminal cannot shrink the box under its
        // 20-cell floor, and a 10-row terminal stops at the 8-row floor.
        self::assertSame([20, 8], SessionPicker::overlayGeometry(30, 10, 6));
    }

    // =========================================================================
    // Relay-shape sanity: the edge Cmd's Msg is NOT a RawMsg, so the WS1
    // clipboard cap passes it through (the frame depends on this to route it).
    // =========================================================================

    public function testLoadMoreCmdFrameIsNotAnOscClipboardWrite(): void
    {
        $picker = SessionPicker::new(self::rows(1), 1, moreInStore: true);
        [$moved, , $edge] = $picker->handleKey('down');
        self::assertSame(0, $moved->selectedIndex());
        self::assertNull($edge, 'fixture: single-row list cannot ARRIVE at its end from row zero');

        $two = SessionPicker::new(self::rows(2), 2, moreInStore: true);
        [, , $cmd] = $two->handleKey('down');
        self::assertInstanceOf(LoadMoreMsg::class, $cmd());
        self::assertFalse($cmd() instanceof RawMsg);
    }
}
