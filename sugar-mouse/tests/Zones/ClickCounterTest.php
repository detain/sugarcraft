<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\Zones\ClickCounter;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\Msg\DoubleClickMsg;
use SugarCraft\Mouse\Zones\Msg\TripleClickMsg;
use PHPUnit\Framework\TestCase;

final class ClickCounterTest extends TestCase
{
    private function press(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    private function move(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::None, MouseAction::Motion);
    }

    /**
     * A wheel report shaped exactly as sugar-core's InputReader currently
     * decodes it: a MouseWheelMsg with action Press hardcoded. The tracker
     * must gate it by TYPE (a scroll across a zone is not a click).
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

    public function testSingleClickEmitsNoMsg(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        [$next, $msg] = $counter->update($this->press(2, 1));

        $this->assertNull($msg);
        $this->assertSame(1, $next->clickCount());
    }

    public function testSecondClickInSameZoneWithinIntervalEmitsDoubleClickMsg(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // First click.
        [$counter] = $counter->update($this->press(2, 1));
        // Second click within 500ms (same zone).
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(2, $counter->clickCount());
    }

    public function testThirdClickInSameZoneWithinIntervalEmitsTripleClickMsg(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // First click.
        [$counter] = $counter->update($this->press(2, 1));
        // Second click.
        [$counter] = $counter->update($this->press(2, 1));
        // Third click.
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertInstanceOf(TripleClickMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(3, $counter->clickCount());
    }

    public function testFourthClickEmitsNoMsg(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        [$counter] = $counter->update($this->press(2, 1));
        [$counter] = $counter->update($this->press(2, 1));
        [$counter] = $counter->update($this->press(2, 1));
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertNull($msg);
        $this->assertSame(4, $counter->clickCount());
    }

    public function testClickOutsideZoneDoesNotStartStreak(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // Click in empty area.
        [$counter] = $counter->update($this->press(50, 1));
        // Now click in zone A.
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());
    }

    public function testZoneChangeResetsStreak(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // Click in zone A.
        [$counter] = $counter->update($this->press(2, 1));
        // Click in zone B — streak resets (different zone).
        [$counter, $msg] = $counter->update($this->press(6, 1));

        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());
    }

    public function testMotionEventIsNoOp(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        [$counter] = $counter->update($this->press(2, 1));
        // Motion — should not affect streak.
        [$counter, $msg] = $counter->update($this->move(3, 1));

        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());
    }

    public function testClickCountReturnsZeroInitially(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        $this->assertSame(0, $counter->clickCount());
    }

    public function testWithManagerReturnsNewInstance(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        $m2 = Manager::newUnprefixed();
        $m2->scan($m2->mark('x', 'X'));
        $next = $counter->withManager($m2);

        $this->assertNotSame($counter, $next);
        $this->assertSame($m2, $next->manager);
    }

    public function testDoubleClickAfterZoneChangeToSameZoneEmitsDoubleClick(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // Click in zone A.
        [$counter] = $counter->update($this->press(2, 1));
        // Move to zone B — streak resets.
        [$counter] = $counter->update($this->press(6, 1));
        // Back to zone A — new streak of 1.
        [$counter] = $counter->update($this->press(2, 1));
        // Second click in zone A within interval.
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(2, $counter->clickCount());
    }

    public function testCustomClickIntervalIsRespected(): void
    {
        $m = $this->buildManager();
        // Very short interval (1ms) to force expiry.
        $counter = new ClickCounter($m, 1);

        // First click.
        [$counter] = $counter->update($this->press(2, 1));
        // Simulate time passing by directly setting via reflection is tricky;
        // instead we test that the interval field is stored correctly.
        $this->assertSame(1, $counter->clickIntervalMs);
    }

    public function testIntervalExpiryResetsStreakViaClockSeam(): void
    {
        $m = $this->buildManager();
        $t = 1000.0;
        $counter = new ClickCounter($m, 500, static function () use (&$t): float {
            return $t;
        });

        [$counter] = $counter->update($this->press(2, 1));
        $this->assertSame(1, $counter->clickCount());

        // Still inside the window — the double click fires.
        $t += 0.4;
        [$counter, $msg] = $counter->update($this->press(2, 1));
        $this->assertInstanceOf(DoubleClickMsg::class, $msg);

        // A second and a half later the window expired — the streak restarts
        // at 1 and nothing is emitted. (Deterministic via the clock seam; the
        // pre-seam shape of this test slept on the wall clock.)
        $t += 1.5;
        [$counter, $msg] = $counter->update($this->press(2, 1));
        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());
    }

    /**
     * The expiry boundary is exclusive (`elapsed > interval` still counts):
     * exactly one interval window between presses is a double click.
     */
    public function testExactlyOneIntervalElapsedStillCountsAsDouble(): void
    {
        $m = $this->buildManager();
        $t = 0.0;
        $counter = new ClickCounter($m, 500, static function () use (&$t): float {
            return $t;
        });

        [$counter] = $counter->update($this->press(2, 1));
        $t = 0.5; // exactly 500ms — not GREATER than the interval
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame(2, $counter->clickCount());
    }

    /**
     * m10: rebinding the manager carries the in-progress streak (mirrors
     * ZoneHoverTracker::withManager) — the user's first click is not erased
     * by a component rewire between the two clicks.
     */
    public function testWithManagerCarriesTheStreak(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);
        [$counter] = $counter->update($this->press(2, 1));
        $this->assertSame(1, $counter->clickCount());

        $m2 = Manager::newUnprefixed();
        $m2->scan($m2->mark('a', 'AAA') . '|' . $m2->mark('b', 'BBB'));

        $counter = $counter->withManager($m2);
        $this->assertSame($m2, $counter->manager);
        $this->assertSame(1, $counter->clickCount());

        [$counter, $msg] = $counter->update($this->press(2, 1));
        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
    }

    /**
     * Regression: update() must not mutate the original instance.
     * Mirrors the failing state that existed before the mutate()-before-return fix.
     */
    public function testUpdateLeavesOriginalUnchanged(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        // Capture original state.
        $old = $counter;

        // First press: takes new instance to clickCount=1.
        [$new,] = $counter->update($this->press(2, 1));

        // Original must still be at zero.
        $this->assertSame(0, $old->clickCount());
        $this->assertNotSame($old, $new);

        // Second press (same zone, within interval): takes new instance to clickCount=2.
        [$mid,] = $new->update($this->press(2, 1));

        // The first new instance ($new / pre-second-press) must still be at 1.
        $this->assertSame(1, $new->clickCount());
        $this->assertNotSame($new, $mid);
    }

    /**
     * Cover the interval-expiry branch (elapsed > clickIntervalMs) with a
     * 1ms window on the clock seam — no wall-clock sleeping (the pre-seam
     * shape of this test carried two usleeps and their CI flake risk).
     */
    public function testIntervalExpiryEmitsNoMsgAndResetsStreak(): void
    {
        $m = $this->buildManager();
        $t = 0.0;
        $counter = new ClickCounter($m, 1, static function () use (&$t): float {
            return $t;
        });

        // First click in zone A.
        [$counter] = $counter->update($this->press(2, 1));
        $this->assertSame(1, $counter->clickCount());

        // Advance past the 1ms window.
        $t = 0.0025;

        // Second click after expiry — should restart streak at 1, not emit double.
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());
    }

    /**
     * C1 regression: a wheel report stamped action Press (the core wire shape
     * today) must not advance the streak. Two real presses bracketing wheel
     * ticks are a DOUBLE click, not a triple — the wheels contribute nothing.
     */
    public function testWheelReportsDoNotAdvanceTheStreak(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        [$counter, $msg] = $counter->update($this->press(2, 1));
        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());

        [$counter, $msg] = $counter->update($this->wheel(2, 1));
        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());

        [$counter, $msg] = $counter->update($this->wheel(6, 1));
        $this->assertNull($msg);
        $this->assertSame(1, $counter->clickCount());

        [$counter, $msg] = $counter->update($this->press(2, 1));
        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame('a', $msg->zone->id);
        $this->assertSame(2, $counter->clickCount());
    }

    /**
     * C1 regression: wheeling must not reset a streak either — inside or
     * outside a zone, the state before and after a wheel tick is identical.
     */
    public function testWheelDoesNotResetStreakInsideOrOutside(): void
    {
        $m = $this->buildManager();
        $counter = new ClickCounter($m);

        [$counter] = $counter->update($this->press(2, 1));
        [$counter] = $counter->update($this->wheel(50, 1)); // wheel over no zone
        [$counter, $msg] = $counter->update($this->press(2, 1));

        $this->assertInstanceOf(DoubleClickMsg::class, $msg);
        $this->assertSame(2, $counter->clickCount());
    }
}
