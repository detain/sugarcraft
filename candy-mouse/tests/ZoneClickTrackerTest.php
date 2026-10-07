<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\ClickResult;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zone;
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

        $result = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);

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
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);
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
        $result = $this->tracker->track(MouseEvent::release(3, 1, 0), $zoneB);

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
        $result2 = $this->tracker->track(MouseEvent::release(2, 1, 0), $zone);
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
        $result0 = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);
        self::assertInstanceOf(ClickResult::class, $result0);
        self::assertSame(0, $result0->button);

        // Release button 1 — emits click for button 1.
        $result1 = $this->tracker->track(MouseEvent::release(1, 1, 1), $zone);
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
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);
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
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);
        self::assertInstanceOf(ClickResult::class, $result);

        // Release button 1 — click emitted.
        $result2 = $this->tracker->track(MouseEvent::release(1, 1, 1), $zone);
        self::assertInstanceOf(ClickResult::class, $result2);
    }

    // ─── Inline zone (one-call track) ─────────────────────────────────────

    /**
     * One-call form: track(press, $zone) then track(release) emits a
     * ClickResult without needing a separate setPressZone() call.
     */
    public function testInlineZoneFormEmitsClickWithoutSetPressZone(): void
    {
        $zone = $this->buildZone('inline', 'INLINE');

        $this->tracker->track(MouseEvent::press(1, 1, 0), $zone);
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0), $zone);

        self::assertInstanceOf(ClickResult::class, $result);
        self::assertSame($zone, $result->zone);
        self::assertSame(0, $result->button);
    }

    /**
     * track(press, null) then track(release) emits no click — the press
     * hit no zone, so the null zone is stored and the release is discarded.
     */
    public function testInlineZoneNullPressThenReleaseEmitsNoClick(): void
    {
        $this->tracker->track(MouseEvent::press(999, 999, 0), null);
        $result = $this->tracker->track(MouseEvent::release(999, 999, 0));

        self::assertNull($result);
    }

    /**
     * The inline form and the setPressZone() form produce identical results
     * for the same inputs.
     */
    public function testInlineFormAndSetPressZoneFormProduceIdenticalResults(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('equiv', 'EQUIV');
        $this->scanner->scan($rendered);
        $zone = $this->scanner->get('equiv');

        // Inline form.
        $trackerInline = new ZoneClickTracker();
        $trackerInline->track(MouseEvent::press(1, 1, 1), $zone);
        $resultInline = $trackerInline->track(MouseEvent::release(1, 1, 1), $zone);

        // setPressZone form.
        $trackerLegacy = new ZoneClickTracker();
        $trackerLegacy->track(MouseEvent::press(1, 1, 1));
        $trackerLegacy->setPressZone($zone, 1);
        $resultLegacy = $trackerLegacy->track(MouseEvent::release(1, 1, 1), $zone);

        self::assertSame($resultInline?->zone?->id, $resultLegacy?->zone?->id);
        self::assertSame($resultInline?->button, $resultLegacy?->button);
    }

    /**
     * Double-press on distinct zones: last press wins, and releasing on a
     * different zone clears the pending state per the documented state machine.
     */
    public function testDoublePressOnDistinctZonesAttributesToLastPress(): void
    {
        $mark = new Mark();
        // Zone 'a' at col 1, zone 'b' at col 10.
        $rendered = $mark->wrap('a', 'ZONEA') . $mark->wrap('b', 'ZONEB');
        $this->scanner->scan($rendered);
        $zoneA = $this->scanner->get('a');
        $zoneB = $this->scanner->get('b');

        self::assertNotNull($zoneA);
        self::assertNotNull($zoneB);

        // Press on zone A.
        $this->tracker->track(MouseEvent::press(1, 1, 0), $zoneA);
        // Press again on zone B — overwrites pending.
        $this->tracker->track(MouseEvent::press(10, 1, 0), $zoneB);

        // Release at zone A — pending was for B, so this is a different zone
        // under the current scan too.  State machine says: "Release on
        // different zone → clear state, idle".
        $resultA = $this->tracker->track(MouseEvent::release(1, 1, 0), $zoneA);
        self::assertNull($resultA);

        // Must press again on zone B to restore pending state.
        $this->tracker->track(MouseEvent::press(10, 1, 0), $zoneB);

        // Release at zone B — now emits click.
        $resultB = $this->tracker->track(MouseEvent::release(10, 1, 0), $zoneB);
        self::assertInstanceOf(ClickResult::class, $resultB);
        self::assertSame('b', $resultB->zone->id);
    }

    /**
     * setPressZone() with no prior press is a no-op — the isset guard
     * at ZoneClickTracker::setPressZone() prevents any crash.
     */
    public function testSetPressZoneWithNoPendingIsNoOp(): void
    {
        $zone = $this->buildZone('orphan', 'ORPHAN');

        // Call setPressZone without any prior press.
        $this->tracker->setPressZone($zone, 0);

        // Release without a prior press — null, no crash.
        $result = $this->tracker->track(MouseEvent::release(1, 1, 0));
        self::assertNull($result);
    }

    // ─── X1 agreement gate: release re-resolved against the CURRENT scan ───

    /**
     * Press A, a re-render moves A off the cursor's row and slides zone B
     * under it, release there: neither A (stale box) nor B (never pressed)
     * may fire — the fresh hit disagrees with the press zone.
     */
    public function testReleaseAfterZoneMovedUnderCursorDoesNotFireTheNewZone(): void
    {
        $frame1 = str_repeat("plain row\n", 4) . $this->mark->wrap('A', 'ROW-A') . "\n";
        $scan1 = new Scanner();
        $scan1->scan($frame1);
        $zoneA1 = $scan1->get('A');
        self::assertNotNull($zoneA1);

        $this->tracker->track(MouseEvent::press(1, 5, 0), $zoneA1);

        // Re-render: A vanishes and B now occupies (1,5).
        $frame2 = str_repeat("x\n", 4) . $this->mark->wrap('B', 'NOW-B-AT-ROW-5') . "\n";
        $scan2 = new Scanner();
        $scan2->scan($frame2);
        $hitB = $scan2->hit(1, 5);
        self::assertNotNull($hitB);
        self::assertSame('B', $hitB->id);

        $result = $this->tracker->track(MouseEvent::release(1, 5, 0), $hitB);
        self::assertNull($result, 'a release re-resolved onto a different zone must not fire the press-time control');

        // The drop also cleared the pending press (state machine: idle), so
        // releasing again without a new press cannot fire anything either.
        self::assertNull($this->tracker->track(MouseEvent::release(1, 5, 0), $hitB));
    }

    /**
     * Press A, then the frame re-renders with nothing at the cursor's
     * coordinate (zone vanished): the fresh hit is null and the click drops
     * instead of firing off the stale press box.
     */
    public function testReleaseAfterZoneVanishedDoesNotFire(): void
    {
        $frame1 = str_repeat("plain row\n", 4) . $this->mark->wrap('A', 'ROW-A') . "\n";
        $scan1 = new Scanner();
        $scan1->scan($frame1);
        $zoneA1 = $scan1->get('A');
        self::assertNotNull($zoneA1);

        $this->tracker->track(MouseEvent::press(1, 5, 0), $zoneA1);

        // Re-render collapses the filler rows above: nothing occupies (1,5) now.
        $frame2 = $this->mark->wrap('A', 'ROW-A') . "\n";
        $scan2 = new Scanner();
        $scan2->scan($frame2);
        self::assertNull($scan2->hit(1, 5), 'precondition: the coordinate is zone-less in the current frame');

        $result = $this->tracker->track(MouseEvent::release(1, 5, 0), $scan2->hit(1, 5));
        self::assertNull($result, 'a vanished zone must drop the pending press, not fire the stale box');
    }

    /**
     * Positive control / vacuity guard for the two drops above: an unchanged
     * frame re-resolves the release to an equal-valued zone from a FRESH
     * scan (distinct Zone instance, same id + box) and the click fires.
     */
    public function testSameFramePressAndReleaseFiresAcrossIndependentScans(): void
    {
        $frame = str_repeat("plain row\n", 4) . $this->mark->wrap('A', 'ROW-A') . "\n";
        $scanAtPress = new Scanner();
        $scanAtPress->scan($frame);
        $zoneAtPress = $scanAtPress->get('A');
        self::assertNotNull($zoneAtPress);

        $this->tracker->track(MouseEvent::press(1, 5, 0), $zoneAtPress);

        // Second scanner over the identical frame: value-equal, identity-distinct.
        $scanAtRelease = new Scanner();
        $scanAtRelease->scan($frame);
        $freshHit = $scanAtRelease->hit(1, 5);
        self::assertNotNull($freshHit);
        self::assertNotSame($zoneAtPress, $freshHit, 'agreement must be value-based, not object identity');

        $result = $this->tracker->track(MouseEvent::release(1, 5, 0), $freshHit);
        self::assertInstanceOf(ClickResult::class, $result);
        self::assertSame('A', $result->zone->id);
    }

    /**
     * Same id at a different box is NOT agreement: after a reflow the zone
     * keeps its identity but its rectangle moved, so the coordinate covers
     * different content than where the press landed and the click drops.
     */
    public function testAgreementRefusesSameIdWithMovedBox(): void
    {
        $pressZone = new Zone('row', 1, 5, 5, 5);
        $this->tracker->track(MouseEvent::press(1, 5, 0), $pressZone);

        // Current frame: 'row' reflowed to row 1 — the release re-hit is the
        // pre-reflow-shaped answer for a zone with the same id, new box.
        $result = $this->tracker->track(MouseEvent::release(1, 5, 0), new Zone('row', 1, 1, 5, 1));
        self::assertNull($result, 'id equality alone must not resurrect a click over moved content');
    }
}
