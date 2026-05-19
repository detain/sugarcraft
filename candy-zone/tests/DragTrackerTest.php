<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\DragTracker;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\Msg\ZoneDragEndMsg;
use SugarCraft\Zone\Msg\ZoneDragMoveMsg;
use SugarCraft\Zone\Msg\ZoneDragStartMsg;
use PHPUnit\Framework\TestCase;

final class DragTrackerTest extends TestCase
{
    private function press(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    private function move(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Motion);
    }

    private function release(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Release);
    }

    /**
     * Helper: build a manager with two side-by-side zones 'A' and 'B'.
     * Zone A: cols 1-3, row 1. Zone B: cols 5-7, row 1.
     */
    private function buildManager(): Manager
    {
        $m = Manager::newGlobal();
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
        $this->assertInstanceOf(\SugarCraft\Zone\Zone::class, $zone);
        $this->assertSame('a', $zone->id);
    }

    public function testCurrentZoneReturnsZoneObject(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $this->assertNull($tracker->currentZone());

        [$tracker] = $tracker->update($this->press(2, 1));

        $zone = $tracker->currentZone();
        $this->assertInstanceOf(\SugarCraft\Zone\Zone::class, $zone);
        $this->assertSame('a', $zone->id);
    }

    public function testWithManagerReturnsNewInstance(): void
    {
        $m = $this->buildManager();
        $tracker = new DragTracker($m);

        $m2 = Manager::newGlobal();
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
}
