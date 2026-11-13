<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction as CoreMouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Mouse\ClickResult;
use SugarCraft\Mouse\MouseAction;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Mouse\Zones\DragTracker;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Msg\DoubleClickMsg;
use SugarCraft\Mouse\Zones\Msg\TripleClickMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragEndMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragMoveMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragStartMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneEnterMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneExitMsg;
use SugarCraft\Mouse\Zones\MsgZoneInBounds;
use SugarCraft\Mouse\Zones\MultiClickTracker;
use SugarCraft\Mouse\Zones\Zone as ZonesZone;
use SugarCraft\Mouse\Zones\ZoneHoverTracker;
use SugarCraft\Mouse\Zone as PrimitiveZone;

/**
 * SM-S31 (gate G12 house-convention half): every public class that
 * exposes a multi-arg constructor now also exposes the canonical
 * `::new()` factory with an identical signature. The constructor
 * stays public for BC; these pins prove the factories forward every
 * argument faithfully.
 */
final class FactorySurfaceTest extends TestCase
{
    public function testPrimitiveFactoriesMirrorConstructors(): void
    {
        $zone = PrimitiveZone::new('btn', 1, 2, 5, 2);
        $this->assertSame('btn', $zone->id);
        $this->assertSame([1, 2, 5, 2], [$zone->startCol, $zone->startRow, $zone->endCol, $zone->endRow]);

        $event = \SugarCraft\Mouse\MouseEvent::new(7, 3, 1, MouseAction::Press);
        $this->assertSame([7, 3, 1], [$event->x, $event->y, $event->button]);
        $this->assertSame(MouseAction::Press, $event->action);

        $result = ClickResult::new($zone, 1);
        $this->assertSame($zone, $result->zone);
        $this->assertSame(1, $result->button);

        $tracker = \SugarCraft\Mouse\ZoneClickTracker::new();
        $this->assertInstanceOf(\SugarCraft\Mouse\ZoneClickTracker::class, $tracker);
    }

    public function testZonesZoneAndMsgFactoriesMirrorConstructors(): void
    {
        $zone = ZonesZone::new('z', 1, 1, 6, 2);
        $this->assertSame('z', $zone->id);
        $this->assertSame([1, 1, 6, 2], [$zone->startCol, $zone->startRow, $zone->endCol, $zone->endRow]);

        $mouse = new MouseMsg(2, 1, MouseButton::Left, CoreMouseAction::Press);
        $inBounds = MsgZoneInBounds::new($zone, $mouse);
        $this->assertSame($zone, $inBounds->zone);
        $this->assertSame($mouse, $inBounds->mouse);

        foreach ([
            ZoneEnterMsg::new($zone),
            ZoneExitMsg::new($zone),
            DoubleClickMsg::new($zone),
            TripleClickMsg::new($zone),
        ] as $msg) {
            $this->assertSame($zone, $msg->zone);
        }

        $other = ZonesZone::new('other', 8, 1, 9, 1);
        $start = ZoneDragStartMsg::new($zone, $other);
        $this->assertSame($zone, $start->originZone);
        $this->assertSame($other, $start->currentZone);
        $move = ZoneDragMoveMsg::new($zone, $other);
        $this->assertSame([$zone, $other], [$move->originZone, $move->currentZone]);
        $end = ZoneDragEndMsg::new($zone, null);
        $this->assertSame($zone, $end->originZone);
        $this->assertNull($end->currentZone);
    }

    public function testTrackerFactoriesForwardEveryArgument(): void
    {
        $manager = new Manager();
        $manager->scan($manager->mark('btn', 'OK'));

        $drag = DragTracker::new($manager, 'a', 'b', MouseButton::Right);
        $this->assertSame($manager, $drag->manager);
        $this->assertSame('a', $drag->originZoneId());
        $this->assertSame('b', $drag->currentZoneId());
        $this->assertSame(MouseButton::Right, $drag->dragButton());

        $hover = ZoneHoverTracker::new($manager, 'btn');
        $this->assertSame('btn', $hover->currentZoneId());
        $this->assertNull($hover->pendingEnter());

        $clock = static fn (): float => 123.5;
        $counter = MultiClickTracker::new($manager, 900, $clock);
        $this->assertSame(900, $counter->clickIntervalMs);
        $this->assertSame($manager, $counter->manager);
    }

    public function testFactoryDefaultsMatchConstructorDefaults(): void
    {
        $manager = new Manager();

        $drag = DragTracker::new($manager);
        $this->assertNull($drag->originZoneId());
        $this->assertNull($drag->currentZoneId());
        $this->assertNull($drag->dragButton());

        $hover = ZoneHoverTracker::new($manager);
        $this->assertNull($hover->currentZoneId());

        $counter = MultiClickTracker::new($manager);
        $this->assertSame(500, $counter->clickIntervalMs);
    }
}
