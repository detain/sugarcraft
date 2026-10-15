<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Msg\DoubleClickMsg;
use SugarCraft\Zone\Msg\TripleClickMsg;
use SugarCraft\Zone\Msg\ZoneDragEndMsg;
use SugarCraft\Zone\Msg\ZoneDragMoveMsg;
use SugarCraft\Zone\Msg\ZoneDragStartMsg;
use SugarCraft\Zone\Msg\ZoneEnterMsg;
use SugarCraft\Zone\Msg\ZoneExitMsg;
use SugarCraft\Zone\MsgZoneInBounds;
use SugarCraft\Zone\Zone;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the plain value-object Msg types emitted by the zone trackers.
 * Each Msg implements SugarCraft\Core\Msg and carries zone data.
 */
final class MsgTypesTest extends TestCase
{
    private function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    public function testDoubleClickMsgExposesZone(): void
    {
        $zone = new Zone('btn', 1, 1, 5, 5);
        $msg = new DoubleClickMsg($zone);

        $this->assertSame($zone, $msg->zone);
        $this->assertSame('btn', $msg->zone->id);
    }

    public function testTripleClickMsgExposesZone(): void
    {
        $zone = new Zone('btn', 1, 1, 5, 5);
        $msg = new TripleClickMsg($zone);

        $this->assertSame($zone, $msg->zone);
        $this->assertSame('btn', $msg->zone->id);
    }

    public function testZoneEnterMsgExposesZone(): void
    {
        $zone = new Zone('hover-zone', 3, 2, 8, 4);
        $msg = new ZoneEnterMsg($zone);

        $this->assertSame($zone, $msg->zone);
        $this->assertSame('hover-zone', $msg->zone->id);
    }

    public function testZoneExitMsgExposesZone(): void
    {
        $zone = new Zone('hover-zone', 3, 2, 8, 4);
        $msg = new ZoneExitMsg($zone);

        $this->assertSame($zone, $msg->zone);
        $this->assertSame('hover-zone', $msg->zone->id);
    }

    public function testZoneDragStartMsgExposesOriginAndCurrent(): void
    {
        $origin = new Zone('start', 1, 1, 3, 1);
        $current = new Zone('start', 1, 1, 3, 1);
        $msg = new ZoneDragStartMsg($origin, $current);

        $this->assertSame($origin, $msg->originZone);
        $this->assertSame($current, $msg->currentZone);
        $this->assertSame('start', $msg->originZone->id);
    }

    public function testZoneDragMoveMsgExposesOriginAndCurrent(): void
    {
        $origin = new Zone('start', 1, 1, 3, 1);
        $current = new Zone('crossed', 5, 1, 7, 1);
        $msg = new ZoneDragMoveMsg($origin, $current);

        $this->assertSame($origin, $msg->originZone);
        $this->assertSame($current, $msg->currentZone);
        $this->assertSame('start', $msg->originZone->id);
        $this->assertSame('crossed', $msg->currentZone->id);
    }

    public function testZoneDragEndMsgExposesOriginAndCurrent(): void
    {
        $origin = new Zone('start', 1, 1, 3, 1);
        $current = new Zone('end', 5, 1, 7, 1);
        $msg = new ZoneDragEndMsg($origin, $current);

        $this->assertSame($origin, $msg->originZone);
        $this->assertSame($current, $msg->currentZone);
        $this->assertSame('start', $msg->originZone->id);
        $this->assertSame('end', $msg->currentZone->id);
    }

    public function testMsgZoneInBoundsExposesZoneAndMouse(): void
    {
        $zone = new Zone('target', 2, 2, 6, 4);
        $mouse = $this->click(3, 3);
        $msg = new MsgZoneInBounds($zone, $mouse);

        $this->assertSame($zone, $msg->zone);
        $this->assertSame($mouse, $msg->mouse);
        $this->assertSame('target', $msg->zone->id);
        $this->assertSame(3, $msg->mouse->x);
        $this->assertSame(3, $msg->mouse->y);
    }

    /**
     * ZoneDragStartMsg carries distinct origin/current when cursor has already
     * crossed to a different zone at drag-start (unlikely but valid).
     */
    public function testZoneDragStartMsgWithDifferentOriginAndCurrent(): void
    {
        $origin = new Zone('a', 1, 1, 3, 1);
        $current = new Zone('b', 5, 1, 7, 1);
        $msg = new ZoneDragStartMsg($origin, $current);

        $this->assertNotSame($msg->originZone, $msg->currentZone);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('b', $msg->currentZone->id);
    }

    /**
     * ZoneDragEndMsg origin/current can differ when drag started in one zone
     * and ended in another.
     */
    public function testZoneDragEndMsgWithDifferentOriginAndCurrent(): void
    {
        $origin = new Zone('a', 1, 1, 3, 1);
        $current = new Zone('b', 5, 1, 7, 1);
        $msg = new ZoneDragEndMsg($origin, $current);

        $this->assertNotSame($msg->originZone, $msg->currentZone);
        $this->assertSame('a', $msg->originZone->id);
        $this->assertSame('b', $msg->currentZone->id);
    }
}
