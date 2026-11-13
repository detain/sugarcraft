<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\MouseAction as GeometryAction;
use SugarCraft\Mouse\Zones\Zone;
use PHPUnit\Framework\TestCase;

final class ZoneTest extends TestCase
{
    private function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    public function testInBoundsTrueAtCorners(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertTrue($z->inBounds($this->click(5, 2)));
        $this->assertTrue($z->inBounds($this->click(10, 2)));
        $this->assertTrue($z->inBounds($this->click(5, 4)));
        $this->assertTrue($z->inBounds($this->click(10, 4)));
    }

    public function testInBoundsTrueInteriorPoint(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertTrue($z->inBounds($this->click(7, 3)));
    }

    public function testInBoundsFalseOutsideOnEachAxis(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertFalse($z->inBounds($this->click(4, 3)));   // x too small
        $this->assertFalse($z->inBounds($this->click(11, 3)));  // x too large
        $this->assertFalse($z->inBounds($this->click(7, 1)));   // y too small
        $this->assertFalse($z->inBounds($this->click(7, 5)));   // y too large
    }

    public function testPosReturnsZeroBasedOffset(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertSame([0, 0], $z->pos($this->click(5, 2)));
        $this->assertSame([2, 1], $z->pos($this->click(7, 3)));
        $this->assertSame([5, 2], $z->pos($this->click(10, 4)));
    }

    public function testPosNegativeOutsideZone(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        [$col, $row] = $z->pos($this->click(2, 1));
        $this->assertSame(-3, $col);
        $this->assertSame(-1, $row);
    }

    public function testWidthAndHeight(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertSame(6, $z->width());
        $this->assertSame(3, $z->height());
    }

    public function testSingleCellZone(): void
    {
        $z = new Zone('px', 1, 1, 1, 1);
        $this->assertSame(1, $z->width());
        $this->assertSame(1, $z->height());
        $this->assertTrue($z->inBounds($this->click(1, 1)));
        $this->assertFalse($z->inBounds($this->click(2, 1)));
    }

    public function testIdIsExposed(): void
    {
        $z = new Zone('myButton', 0, 0, 5, 5);
        $this->assertSame('myButton', $z->id);
    }

    public function testIsZeroDetectsDegenerateZone(): void
    {
        $this->assertTrue((new Zone('x', 0, 0, 0, 0))->isZero());
        $this->assertFalse((new Zone('x', 1, 1, 5, 5))->isZero());
        $this->assertFalse((new Zone('x', 0, 0, 0, 1))->isZero());
    }

    public function testContainsInnerZoneReturnsTrue(): void
    {
        $outer = new Zone('outer', 1, 1, 10, 10);
        $inner = new Zone('inner', 3, 3, 7, 7);
        $this->assertTrue($outer->contains($inner));
    }

    public function testContainsOuterZoneReturnsFalse(): void
    {
        $outer = new Zone('outer', 1, 1, 10, 10);
        $inner = new Zone('inner', 3, 3, 7, 7);
        $this->assertFalse($inner->contains($outer));
    }

    public function testContainsOverlappingZoneReturnsFalse(): void
    {
        $a = new Zone('a', 1, 1, 5, 5);
        $b = new Zone('b', 3, 3, 7, 7);
        $this->assertFalse($a->contains($b));
        $this->assertFalse($b->contains($a));
    }

    public function testContainsEqualZoneReturnsTrue(): void
    {
        $zone = new Zone('zone', 2, 2, 8, 8);
        $this->assertTrue($zone->contains($zone));
    }

    public function testContainsAdjacentZoneReturnsFalse(): void
    {
        $a = new Zone('a', 1, 1, 5, 5);
        $b = new Zone('b', 6, 1, 10, 5);
        $this->assertFalse($a->contains($b));
        $this->assertFalse($b->contains($a));
    }

    public function testToMouseEventMapsActionsAndButtons(): void
    {
        $press = Zone::toMouseEvent(new MouseMsg(4, 7, MouseButton::Left, MouseAction::Press));
        $this->assertSame(4, $press->x);
        $this->assertSame(7, $press->y);
        $this->assertSame(GeometryAction::Press, $press->action);
        $this->assertSame(GeometryAction::BUTTON_LEFT, $press->button);

        $release = Zone::toMouseEvent(new MouseMsg(1, 1, MouseButton::Right, MouseAction::Release));
        $this->assertSame(GeometryAction::Release, $release->action);
        $this->assertSame(GeometryAction::BUTTON_RIGHT, $release->button);

        $middle = Zone::toMouseEvent(new MouseMsg(1, 1, MouseButton::Middle, MouseAction::Press));
        $this->assertSame(GeometryAction::BUTTON_MIDDLE, $middle->button);

        $motion = Zone::toMouseEvent(new MouseMsg(9, 2, MouseButton::Left, MouseAction::Motion));
        $this->assertSame(GeometryAction::Drag, $motion->action);
    }

    public function testWheelMsgTranslatesToScrollNotPress(): void
    {
        // Core's InputReader hardcodes Press on wheel reports; the message
        // TYPE must win the translation, not the action field.
        $wheel = Zone::toMouseEvent(new MouseWheelMsg(2, 3, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame(GeometryAction::Scroll, $wheel->action);
        $this->assertSame(GeometryAction::BUTTON_WHEEL_DOWN, $wheel->button);

        $up = Zone::toMouseEvent(new MouseWheelMsg(2, 3, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(GeometryAction::Scroll, $up->action);
        $this->assertSame(GeometryAction::BUTTON_WHEEL_UP, $up->button);

        // Wire values pinned separately: the consts ARE the X10 vocabulary,
        // so a bridge that reads them proves nothing unless their bytes are
        // nailed too (sugar-mouse-049).
        $this->assertSame(64, GeometryAction::BUTTON_WHEEL_UP);
        $this->assertSame(65, GeometryAction::BUTTON_WHEEL_DOWN);
    }

    public function testInBoundsUnchangedByRicherBridge(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertTrue($z->inBounds(new MouseMsg(6, 3, MouseButton::Right, MouseAction::Release)));
        $this->assertFalse($z->inBounds(new MouseMsg(11, 3, MouseButton::Left, MouseAction::Motion)));
    }
}
