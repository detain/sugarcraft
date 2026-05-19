<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\Msg\ZoneEnterMsg;
use SugarCraft\Zone\Msg\ZoneExitMsg;
use SugarCraft\Zone\Zone;
use SugarCraft\Zone\ZoneHoverTracker;
use PHPUnit\Framework\TestCase;

final class ZoneHoverTrackerTest extends TestCase
{
    private function move(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::None, MouseAction::Motion);
    }

    private function press(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
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

    public function testEnterZoneEmitsZoneEnterMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Move cursor into zone A (col 2, row 1).
        [$next, $msg] = $tracker->update($this->move(2, 1));

        $this->assertInstanceOf(ZoneEnterMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame('a', $next->currentZoneId());
    }

    public function testStayInSameZoneEmitsNoMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Enter zone A first.
        [$tracker] = $tracker->update($this->move(2, 1));

        // Move within the same zone.
        [$next, $msg] = $tracker->update($this->move(3, 1));

        $this->assertNull($msg);
        $this->assertSame('a', $next->currentZoneId());
    }

    public function testExitZoneToNullEmitsZoneExitMsg(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Enter zone A first.
        [$tracker] = $tracker->update($this->move(2, 1));

        // Move cursor to a position with no zone (col 50).
        [$next, $msg] = $tracker->update($this->move(50, 1));

        $this->assertInstanceOf(ZoneExitMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertNull($next->currentZoneId());
    }

    public function testMoveFromZoneAToZoneBEmitsExitThenEnter(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Enter zone A first.
        [$tracker] = $tracker->update($this->move(2, 1));
        $this->assertSame('a', $tracker->currentZoneId());

        // Move cursor from zone A (col 2) to zone B (col 6).
        // First update: exit zone A.
        [$tracker, $msg] = $tracker->update($this->move(6, 1));

        $this->assertInstanceOf(ZoneExitMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        // After exit, currentZoneId is cleared — caller calls update()
        // again to receive the enter for the new zone.
        $this->assertNull($tracker->currentZoneId());

        // Second update: enter zone B.
        [$tracker, $msg] = $tracker->update($this->move(6, 1));

        $this->assertInstanceOf(ZoneEnterMsg::class, $msg);
        $this->assertSame('b', $msg->zone->id);
        $this->assertSame('b', $tracker->currentZoneId());
    }

    public function testCursorInNoZoneStaysNull(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Move cursor to empty area without ever entering a zone.
        [$next, $msg] = $tracker->update($this->move(50, 1));

        $this->assertNull($msg);
        $this->assertNull($next->currentZoneId());
    }

    public function testCurrentZoneReturnsZoneObject(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        $this->assertNull($tracker->currentZone());

        [$tracker] = $tracker->update($this->move(2, 1));

        $zone = $tracker->currentZone();
        $this->assertInstanceOf(Zone::class, $zone);
        $this->assertSame('a', $zone->id);
    }

    public function testCurrentZoneReturnsNullWhenNotInZone(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        [$tracker] = $tracker->update($this->move(2, 1));
        $this->assertNotNull($tracker->currentZone());

        [$tracker] = $tracker->update($this->move(50, 1));
        $this->assertNull($tracker->currentZone());
    }

    public function testWithManagerReturnsNewInstanceWithDifferentManager(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        $m2 = Manager::newGlobal();
        $m2->scan($m2->mark('x', 'X'));
        $next = $tracker->withManager($m2);

        $this->assertNotSame($tracker, $next);
        $this->assertSame($m2, $next->manager);
        $this->assertNull($next->currentZoneId());
    }

    public function testWithCurrentZoneIdReturnsNewInstance(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        $next = $tracker->withCurrentZoneId('b');

        $this->assertNotSame($tracker, $next);
        $this->assertSame('b', $next->currentZoneId());
    }

    public function testUpdateWithNonMouseMsgReturnsNoTransition(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Enter a zone first.
        [$tracker] = $tracker->update($this->move(2, 1));

        // Send a non-motion mouse message (a press).
        [$next, $msg] = $tracker->update($this->press(2, 1));

        // No hover transition — press is not a motion event,
        // but the manager's anyInBounds still matches, so we get null
        // (same zone hit, no transition).
        $this->assertNull($msg);
        $this->assertSame('a', $next->currentZoneId());
    }

    public function testEmptyManagerReturnsNullHit(): void
    {
        $m = Manager::newGlobal();
        $tracker = new ZoneHoverTracker($m);

        // No zones registered at all.
        [$next, $msg] = $tracker->update($this->move(1, 1));

        $this->assertNull($msg);
        $this->assertNull($next->currentZoneId());
    }

    public function testEnterMsgZoneCarriesCorrectBounds(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        [$tracker, $msg] = $tracker->update($this->move(2, 1));

        $this->assertInstanceOf(ZoneEnterMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(1, $msg->zone->startCol);
        $this->assertSame(1, $msg->zone->startRow);
        $this->assertSame(3, $msg->zone->endCol);
        $this->assertSame(1, $msg->zone->endRow);
    }

    public function testExitMsgZoneCarriesCorrectBounds(): void
    {
        $m = $this->buildManager();
        $tracker = new ZoneHoverTracker($m);

        // Enter zone first.
        [$tracker] = $tracker->update($this->move(2, 1));
        // Move to empty space.
        [$tracker, $msg] = $tracker->update($this->move(50, 1));

        $this->assertInstanceOf(ZoneExitMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(1, $msg->zone->startCol);
        $this->assertSame(1, $msg->zone->startRow);
        $this->assertSame(3, $msg->zone->endCol);
        $this->assertSame(1, $msg->zone->endRow);
    }
}
