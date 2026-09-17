<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Theme;

/**
 * @internal
 */
final class SessionPickerTest extends TestCase
{
    /** @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> */
    private function makeSessions(int $count = 3): array
    {
        $sessions = [];
        for ($i = 0; $i < $count; $i++) {
            $sessions[] = [
                'sessionId' => "session-$i",
                'sessionName' => "Session $i",
                'summary' => "Summary $i",
                'gitBranch' => $i % 2 === 0 ? 'main' : 'feature-x',
                'lastActivity' => '2024-01-01T00:00:00Z',
            ];
        }
        return $sessions;
    }

    public function testNew(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertNull($picker->branchFilter());
    }

    public function testFilteredSessionsWithNoFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertSame($sessions, $picker->filteredSessions());
    }

    public function testFilteredSessionsWithBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $filtered = $picker->filteredSessions();
        $this->assertCount(2, $filtered);
        foreach ($filtered as $s) {
            $this->assertSame('main', $s['gitBranch']);
        }
    }

    public function testSelectedSession(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        $this->assertSame('session-1', $picker->selectedSession()['sessionId']);
    }

    public function testSelectedSessionReturnsNullWhenEmpty(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertNull($picker->selectedSession());
    }

    public function testSelectedSessionReturnsNullWhenIndexOutOfBounds(): void
    {
        // E744 WS5 removed the hand-poked invalid cursor this test used to
        // build by reflection: the selection index now lives on the ItemList
        // model, which CLAMPS, so a cursor beyond the last row is no longer
        // constructible — it can only be reasoned about. The bound the poke
        // forced is now reachable solely through the empty row set (cursor
        // parked at zero over zero rows), which selectedSession() still
        // answers with null.
        $picker = SessionPicker::new($this->makeSessions())->withBranchFilter('no-such-branch');
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertCount(0, $picker->filteredSessions());
        $this->assertNull($picker->selectedSession());
    }

    public function testSelectedIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2);
        $this->assertSame(2, $picker->selectedIndex());
    }

    public function testBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('feature-x');
        $this->assertSame('feature-x', $picker->branchFilter());
    }

    public function testWithSelectedIndexClampsToZero(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(-1);
        $this->assertSame(0, $picker->selectedIndex());
    }

    public function testWithSelectedIndexClampsToMax(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(99);
        $this->assertSame(2, $picker->selectedIndex());
    }

    public function testWithBranchFilterResetsIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2)->withBranchFilter('main');
        // Filter reduces to 2 items (indices 0 and 2 originally), but index resets to 0
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertSame('main', $picker->branchFilter());
    }

    public function testWithSessionsResetsIndex(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2)->withSessions($this->makeSessions(5));
        $this->assertSame(0, $picker->selectedIndex());
    }

    public function testWithSessionsPreservesBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('feature-x')->withSessions($this->makeSessions(5));
        $this->assertSame('feature-x', $picker->branchFilter());
    }

    public function testRenderReturnsString(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $output = $picker->render(80, 24, Theme::byName('dark'));
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testRenderWithNoSessions(): void
    {
        $picker = SessionPicker::new([]);
        $output = $picker->render(80, 24, Theme::byName('dark'));
        $this->assertIsString($output);
        $this->assertStringContainsString('(no sessions)', $output);
    }

    public function testHandleKeyUp(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        [$newPicker, $action] = $picker->handleKey('up');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex());
    }

    public function testHandleKeyDown(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('down');
        $this->assertSame('browse', $action);
        $this->assertSame(1, $newPicker->selectedIndex());
    }

    public function testHandleKeyDownClampsAtLastRow(): void
    {
        // E744 WS5 divergence, disclosed in the picker docblock: the adopted
        // ItemList CLAMPS at the end where the hand-rolled picker WRAPPED —
        // arriving on the last row is the load-more signal (pinned in
        // SessionPickerWidgetTest), never a jump back to row zero. A picker
        // with no more pages upstream answers the end press quietly: the
        // widened tuple's third slot is the widget's navigation Cmd, and
        // clamped-and-unchanged raises nothing.
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(2); // last index
        [$newPicker, $action, $cmd] = $picker->handleKey('down');
        $this->assertSame('browse', $action);
        $this->assertSame(2, $newPicker->selectedIndex()); // stays clamped
        $this->assertNull($cmd);
    }

    public function testHandleKeyUpClampsAtFirstRow(): void
    {
        // E744 WS5: clamp, not wrap — see testHandleKeyDownClampsAtLastRow.
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0); // first index
        [$newPicker, $action, $cmd] = $picker->handleKey('up');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex()); // stays clamped
        $this->assertNull($cmd);
    }

    public function testHandleKeyEnterWithSelection(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0);
        [$newPicker, $action] = $picker->handleKey('enter');
        $this->assertSame('resume', $action);
        $this->assertSame($picker, $newPicker);
    }

    public function testHandleKeyEnterWithNoSelection(): void
    {
        $picker = SessionPicker::new([]);
        [$newPicker, $action] = $picker->handleKey('enter');
        $this->assertNull($action);
    }

    public function testHandleKeySpace(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(0);
        [$newPicker, $action] = $picker->handleKey(' ');
        $this->assertSame('preview', $action);
    }

    public function testHandleKeyEscape(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('escape');
        $this->assertSame('close', $action);
    }

    public function testHandleKeyUnrecognized(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('unknown');
        $this->assertNull($action);
        $this->assertSame($picker, $newPicker);
    }

    public function testIsEmptyWhenNoSessions(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertTrue($picker->isEmpty());
    }

    public function testIsEmptyWhenHasSessions(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $this->assertFalse($picker->isEmpty());
    }

    public function testIsEmptyWithBranchFilterHidingAll(): void
    {
        $sessions = $this->makeSessions();
        // All sessions have gitBranch, so filtering by a non-existent branch should be empty
        $picker = SessionPicker::new($sessions)->withBranchFilter('nonexistent-branch');
        $this->assertTrue($picker->isEmpty());
    }

    public function testCount(): void
    {
        $sessions = $this->makeSessions(5);
        $picker = SessionPicker::new($sessions);
        $this->assertSame(5, $picker->count());
    }

    public function testCountWithBranchFilter(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $this->assertSame(2, $picker->count()); // 2 sessions with 'main' branch
    }

    public function testCountWhenEmpty(): void
    {
        $picker = SessionPicker::new([]);
        $this->assertSame(0, $picker->count());
    }

    public function testImmutability(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        $picker2 = $picker->withSelectedIndex(1);
        $this->assertNotSame($picker, $picker2);
        $this->assertSame(0, $picker->selectedIndex());
        $this->assertSame(1, $picker2->selectedIndex());
    }

    public function testHandleKeyK(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions)->withSelectedIndex(1);
        [$newPicker, $action] = $picker->handleKey('k');
        $this->assertSame('browse', $action);
        $this->assertSame(0, $newPicker->selectedIndex());
    }

    public function testHandleKeyJ(): void
    {
        $sessions = $this->makeSessions();
        $picker = SessionPicker::new($sessions);
        [$newPicker, $action] = $picker->handleKey('j');
        $this->assertSame('browse', $action);
        $this->assertSame(1, $newPicker->selectedIndex());
    }

    public function testHandleKeyCtrlB(): void
    {
        $sessions = $this->makeSessions();

        // When branch filter is already set, ctrl+b clears it (toggle off)
        $picker = SessionPicker::new($sessions)->withBranchFilter('main');
        $this->assertSame('main', $picker->branchFilter());
        [$newPicker, $action] = $picker->handleKey('ctrl+b');
        $this->assertSame('browse', $action);
        $this->assertNull($newPicker->branchFilter());
    }
}
