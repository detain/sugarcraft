<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\Zones\DragTracker;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Msg\ZoneDragEndMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragMoveMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragStartMsg;
use PHPUnit\Framework\TestCase;

final class DragTrackerTest extends TestCase
{
    private function press(int $x, int $y, MouseButton $button = MouseButton::Left): MouseMsg
    {
        return new MouseMsg($x, $y, $button, MouseAction::Press);
    }

    private function move(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Motion);
    }

    private function release(int $x, int $y, MouseButton $button = MouseButton::Left): MouseMsg
    {
        return new MouseMsg($x, $y, $button, MouseAction::Release);
    }

    /**
     * A wheel report shaped as sugar-core's InputReader currently decodes it:
     * MouseWheelMsg with action Press hardcoded. A scroll must never start,
     * move, or end a drag.
     */
    private function wheel(int $x, int $y): MouseMsg
    {
        return new MouseWheelMsg($x, $y, MouseButton::WheelUp, MouseAction::Press);
    }

    /**
     * Helper: build a manager with two side-by-side zones 'A' and 'B'.
     * Zone A: cols 1-3, row 1. Zone B: cols 5-7, row 1.
     */
    private function buildManager(): Manager
    {
        $m = Manager::newUnprefixed();
        $m->scan($m->mark('a', 'AAA') . '|' . $m->mark('b', 'BBB'));
        return $m;
    }

    public function testPressOutsideZoneDoesNotStartDrag(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Press in empty area (col 50).
        [$next, $msg] = $tracker->update($this->press(50, 1));

        $this->assertNull($msg);
        $this->assertNull($next->originZoneId());
        $this->assertNull($next->currentZoneId());
    }

    public function testPressInZoneAStartsDragAndEmitsZoneDragStartMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Press inside zone A (col 2, row 1).
        [$next, $msg] = $tracker->update($this->press(2, 1));

        $this->assertInstanceOf(ZoneDragStartMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('a', $msg->currentZone->id);
        $this->assertSame('a', $next->originZoneId());
        $this->assertSame('a', $next->currentZoneId());
    }

    public function testMoveWithinSameZoneEmitsNoMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Start drag in zone A.
        [$tracker] = $tracker->update($this->press(2, 1));

        // Move within zone A (col 3, row 1).
        [$next, $msg] = $tracker->update($this->move(3, 1));

        $this->assertNull($msg);
        $this->assertSame('a', $next->originZoneId());
        $this->assertSame('a', $next->currentZoneId());
    }

    public function testMoveFromZoneAIntoZoneBEmitsOneMoveMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Start drag in zone A.
        [$tracker] = $tracker->update($this->press(2, 1));

        // Move from zone A (col 2) to zone B (col 6).
        // Emits one move msg with origin=A, current=B.
        [$tracker, $msg] = $tracker->update($this->move(6, 1));

        $this->assertInstanceOf(ZoneDragMoveMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('b', $msg->currentZone->id);
        // currentZoneId is updated immediately — no second call needed
        // since the message carries both zones.
        $this->assertSame('b', $tracker->currentZoneId());

        // Subsequent move in same zone emits no message.
        [$tracker, $msg] = $tracker->update($this->move(6, 1));
        $this->assertNull($msg);
    }

    public function testReleaseInZoneAEmitsDragEndWithOriginA(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Start drag in zone A and stay there.
        [$tracker] = $tracker->update($this->press(2, 1));
        [$tracker] = $tracker->update($this->move(3, 1));

        // Release in zone A.
        [$next, $msg] = $tracker->update($this->release(3, 1));

        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('a', $msg->currentZone->id);
        $this->assertNull($next->originZoneId());
        $this->assertNull($next->currentZoneId());
    }

    public function testPressInZoneAMoveToZoneBReleaseInZoneB(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Press in zone A (col 2).
        [$tracker] = $tracker->update($this->press(2, 1));

        // Move to zone B (col 6) — one update crosses the boundary.
        [$tracker] = $tracker->update($this->move(6, 1));

        // Release in zone B.
        [$next, $msg] = $tracker->update($this->release(6, 1));

        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('b', $msg->currentZone->id);
        $this->assertNull($next->originZoneId());
    }

    public function testOriginZoneReturnsZoneObject(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $this->assertNull($tracker->originZone());

        [$tracker] = $tracker->update($this->press(2, 1));

        $zone = $tracker->originZone();
        $this->assertInstanceOf(\SugarCraft\Mouse\Zones\Zone::class, $zone);
        $this->assertSame('a', $zone->id);
    }

    public function testCurrentZoneReturnsZoneObject(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $this->assertNull($tracker->currentZone());

        [$tracker] = $tracker->update($this->press(2, 1));

        $zone = $tracker->currentZone();
        $this->assertInstanceOf(\SugarCraft\Mouse\Zones\Zone::class, $zone);
        $this->assertSame('a', $zone->id);
    }

    public function testWithManagerReturnsNewInstance(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $m2 = Manager::newUnprefixed();
        $m2->scan($m2->mark('x', 'X'));
        $next = $tracker->withManager($m2);

        $this->assertNotSame($tracker, $next);
        $this->assertNotSame($m, $next->manager);
    }

    public function testWithZoneIdsReturnsNewInstance(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $next = $tracker->withZoneIds('a', 'b');

        $this->assertNotSame($tracker, $next);
        $this->assertSame('a', $next->originZoneId());
        $this->assertSame('b', $next->currentZoneId());
    }

    public function testMotionOutsideAllZonesDuringDragEmitsNoMsgButClearsCurrent(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Start drag in zone A.
        [$tracker] = $tracker->update($this->press(2, 1));

        // Move outside all zones (col 50).
        [$tracker, $msg] = $tracker->update($this->move(50, 1));

        // No drag message, but current zone is cleared.
        $this->assertNull($msg);
        $this->assertSame('a', $tracker->originZoneId());
        $this->assertNull($tracker->currentZoneId());
    }

    public function testDragStartMsgZoneCarriesCorrectBounds(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$tracker, $msg] = $tracker->update($this->press(2, 1));

        $this->assertInstanceOf(ZoneDragStartMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame(1, $msg->originZone->startCol);
        $this->assertSame(1, $msg->originZone->startRow);
        $this->assertSame(3, $msg->originZone->endCol);
        $this->assertSame(1, $msg->originZone->endRow);
    }

    public function testDragEndMsgCarriesCorrectOriginAndCurrent(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Start drag in zone A.
        [$tracker] = $tracker->update($this->press(2, 1));
        // Cross to zone B (one update suffices).
        [$tracker] = $tracker->update($this->move(6, 1));
        // Release in zone B.
        [$tracker, $msg] = $tracker->update($this->release(6, 1));

        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('b', $msg->currentZone->id);
    }

    /**
     * Regression: update() must not mutate the original instance.
     * Mirrors the failing state that existed before the mutate()-before-return fix.
     */
    public function testUpdateLeavesOriginalUnchanged(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Capture original state — both ids are null.
        $old = $tracker;

        // Press inside zone A: new instance has originZoneId='a', currentZoneId='a'.
        [$new,] = $tracker->update($this->press(2, 1));

        // Original must still be null/null.
        $this->assertNull($old->originZoneId());
        $this->assertNull($old->currentZoneId());
        // New instance has the hit ids.
        $this->assertSame('a', $new->originZoneId());
        $this->assertSame('a', $new->currentZoneId());
        $this->assertNotSame($old, $new);
    }

    /**
     * MAJOR-7: when a zone is removed from the manager mid-drag (e.g. a
     * re-render without the zone), release emits no message but MUST still
     * clear the tracker — the old behaviour kept the stale origin forever,
     * wedging the tracker so it swallowed every later release.
     */
    public function testReleaseAfterZoneRemovedFromManagerEmitsNoMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Press inside zone A to start drag.
        [$tracker] = $tracker->update($this->press(2, 1));
        $this->assertSame('a', $tracker->originZoneId());

        // Simulate zone being removed from manager (e.g. component re-rendered without zone).
        $m->clear('a');

        // Release emits nothing (origin unresolvable) but the drag state is dropped.
        [$next, $msg] = $tracker->update($this->release(2, 1));

        $this->assertNull($msg);
        $this->assertNull($next->originZoneId());
        $this->assertNull($next->currentZoneId());
        $this->assertNull($next->dragButton());

        // Re-registering the zone lets the next press start a fresh drag —
        // no wedge residue survived the silent end.
        $m->scan($m->mark('a', 'AAA') . '|' . $m->mark('b', 'BBB'));
        [$after, $msg2] = $next->update($this->press(2, 1));
        $this->assertInstanceOf(ZoneDragStartMsg::class, $msg2);
    }

    /**
     * m3: a Release of a button OTHER than the one that started the drag
     * neither ends the drag nor clears state.
     */
    public function testReleaseOfDifferentButtonDoesNotEndDrag(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$tracker] = $tracker->update($this->press(2, 1));
        $this->assertSame(MouseButton::Left, $tracker->dragButton());

        // Right-button release while the left drag is active: inert.
        [$stuck, $msg] = $tracker->update($this->release(2, 1, MouseButton::Right));
        $this->assertNull($msg);
        $this->assertSame('a', $stuck->originZoneId());
        $this->assertSame(MouseButton::Left, $stuck->dragButton());

        // The matching left release ends it normally.
        [$done, $msg2] = $stuck->update($this->release(2, 1));
        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg2);
        $this->assertNull($done->originZoneId());
    }

    /**
     * MAJOR-8: releasing outside every tracked zone emits End with a null
     * current zone (the old code fell back to the origin, lying about where
     * the release happened).
     */
    public function testReleaseOutsideAllZonesEmitsEndWithNullCurrent(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$tracker] = $tracker->update($this->press(2, 1));
        [$tracker] = $tracker->update($this->move(50, 1));

        [$next, $msg] = $tracker->update($this->release(50, 1));

        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertNull($msg->currentZone);
        $this->assertNull($next->originZoneId());
    }

    /**
     * MAJOR-8: the end message names the zone under the cursor AT RELEASE,
     * not the stale last-crossed zone (drag A→B then release back in A ends
     * with current A).
     */
    public function testEndMsgUsesZoneUnderCursorAtReleaseNotStaleCurrent(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$tracker] = $tracker->update($this->press(2, 1));
        [$tracker] = $tracker->update($this->move(6, 1));
        $this->assertSame('b', $tracker->currentZoneId());

        [, $msg] = $tracker->update($this->release(2, 1));

        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('a', $msg->currentZone?->id);
    }

    /**
     * Motion outside zones after having a currentZoneId set to null
     * (transition from zone → outside) should not emit a message.
     * This is the second motion-outside path in the update() method.
     */
    public function testSecondMotionOutsideZoneAfterAlreadyNullDoesNotEmit(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        // Press to start drag.
        [$tracker] = $tracker->update($this->press(2, 1));
        // Move outside - currentZoneId becomes null.
        [$tracker] = $tracker->update($this->move(50, 1));
        $this->assertNull($tracker->currentZoneId());

        // Move again outside (still null) - should emit no message.
        [$next, $msg] = $tracker->update($this->move(51, 1));

        $this->assertNull($msg);
        $this->assertNull($next->currentZoneId());
        $this->assertSame('a', $next->originZoneId());
    }

    /**
     * C1 regression: a wheel report (action Press on the core wire) must not
     * start a drag.
     */
    public function testWheelDoesNotStartDrag(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$next, $msg] = $tracker->update($this->wheel(2, 1));

        $this->assertNull($msg);
        $this->assertNull($next->originZoneId());
        $this->assertNull($next->currentZoneId());
    }

    /**
     * C1 regression: wheeling during a drag neither moves nor ends it — the
     * drag survives intact until a real Release.
     */
    public function testWheelDuringDragIsFullyInert(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        [$tracker] = $tracker->update($this->press(2, 1));

        // Wheel across the boundary into zone B — must not emit, must not move current.
        [$tracker, $msg] = $tracker->update($this->wheel(6, 1));
        $this->assertNull($msg);
        $this->assertSame('a', $tracker->originZoneId());
        $this->assertSame('a', $tracker->currentZoneId());

        // Wheel outside any zone — must not clear current, must not end the drag.
        [$tracker, $msg] = $tracker->update($this->wheel(50, 1));
        $this->assertNull($msg);
        $this->assertSame('a', $tracker->currentZoneId());

        // A real release still ends the drag.
        [$tracker, $msg] = $tracker->update($this->release(2, 1));
        $this->assertInstanceOf(ZoneDragEndMsg::class, $msg);
        $this->assertNull($tracker->originZoneId());
    }
}
