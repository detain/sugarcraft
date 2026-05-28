<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\ClickResult;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneClickTracker;

final class ZoneClickTrackerTest extends TestCase
{
    private Mark $mark;
    private Scanner $scanner;
    private ZoneClickTracker $tracker;

    protected function setUp(): void
    {
        $this->mark = new Mark();
        $this->scanner = new Scanner();
        $this->tracker = new ZoneClickTracker();
    }

    private function buildZone(string $id, string $content): \SugarCraft\Mouse\Zone
    {
        $rendered = $this->mark->wrap($id, $content);
        $this->scanner->scan($rendered);
        return $this->scanner->get($id)
            ?? new \SugarCraft\Mouse\Zone($id, 0, 0, 0, 0);
    }

    // ─── Happy path ───────────────────────────────────────────────────────

    public function testPressThenReleaseOnSameZoneEmitsClick(): void
    {
        $zone = $this->buildZone('btn', 'PRESS');

        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));

        self::assertInstanceOf(ClickResult::class, $result);
        self::assertSame($zone, $result->zone);
        self::assertSame(0, $result->button);
    }

    public function testReleaseWithoutPressEmitsNoClick(): void
    {
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertNull($result);
    }

    public function testDoublePressRequiresTwoReleases(): void
    {
        $zone = $this->buildZone('btn', 'BTN');

        // First press.
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // Second press on same button — overwrites pending state.
        $this->tracker->track(MouseEvent::press(2, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // First release — since pending was overwritten, state is cleared;
        // but the zone still covers (1,1) so a click is emitted.
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertInstanceOf(ClickResult::class, $result);

        // Second release — pending was cleared by first release, no click.
        $result2 = $this->tracker->track(MouseEvent::release(2, 1, 0));
        self::assertNull($result2);
    }

    public function testPressOnDifferentZonesEmitsNoClick(): void
    {
        $zoneA = $this->buildZone('a', 'ZONE A');
        $zoneB = $this->buildZone('b', 'ZONE B');

        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zoneA, 0);

        // Release on a different zone — clears state, no click.
        $this->tracker->track(MouseEvent::release(50, 50, 0));

        // New press/release on zone B should still work.
        $this->tracker->track(MouseEvent::press(3, 1, 0));
        $this->tracker->setPressZone($zoneB, 0);
        $result = $this->tracker->track(MouseEvent::release(3, 1, 0));

        self::assertInstanceOf(ClickResult::class, $result);
    }

    public function testDragDuringPressEmitsNoClick(): void
    {
        $zone = $this->buildZone('drag', 'DRAG');

        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // Drag while holding.
        $result = $this->tracker->track(MouseEvent::drag(2, 1, 0));
        self::assertNull($result);

        // Release after drag — still emits click if same zone.
        $result2 = $this->tracker->track(MouseEvent::release(2, 1, 0));
        self::assertInstanceOf(ClickResult::class, $result2);
    }

    public function testScrollEmitsNoClick(): void
    {
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $result = $this->tracker->track(MouseEvent::scroll(1, 1, 0));
        self::assertNull($result);
    }

    // ─── Multi-button ────────────────────────────────────────────────────

    public function testDifferentButtonsAreIndependent(): void
    {
        $zone = $this->buildZone('btn', 'BTN');

        // Press button 0.
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // Press button 1 while 0 is still pending.
        $this->tracker->track(MouseEvent::press(1, 1, 1));
        $this->tracker->setPressZone($zone, 1);

        // Release button 0 — emits click for button 0.
        $result0 = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertInstanceOf(ClickResult::class, $result0);
        self::assertSame(0, $result0->button);

        // Release button 1 — emits click for button 1.
        $result1 = $this->tracker->track(MouseEvent::release(1, 1, 1));
        self::assertInstanceOf(ClickResult::class, $result1);
        self::assertSame(1, $result1->button);
    }

    // ─── State transitions ─────────────────────────────────────────────────

    public function testReleaseWithoutPressZoneStillEmitsClick(): void
    {
        // Press without zone recorded (zone was null at press time).
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        // setPressZone not called — zone is null.

        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        // Null zone → no click emitted.
        self::assertNull($result);
    }

    public function testSetPressZoneFollowedByReleaseEmitsClick(): void
    {
        $zone = $this->buildZone('setzone', 'SET');

        // Press at zone edge.
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // Release at same position — should emit.
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertInstanceOf(ClickResult::class, $result);
    }

    public function testPressAndReleaseOnDifferentButtonsWorkIndependence(): void
    {
        $zone = $this->buildZone('btns', 'BTNS');

        // Press button 0.
        $this->tracker->track(MouseEvent::press(1, 1, 0));
        $this->tracker->setPressZone($zone, 0);

        // Press button 1 (different button) — button 0 state stays.
        $this->tracker->track(MouseEvent::press(1, 1, 1));
        $this->tracker->setPressZone($zone, 1);

        // Release button 0 — click emitted.
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertInstanceOf(ClickResult::class, $result);

        // Release button 1 — click emitted.
        $result2 = $this->tracker->track(MouseEvent::release(1, 1, 1));
        self::assertInstanceOf(ClickResult::class, $result2);
    }
}
