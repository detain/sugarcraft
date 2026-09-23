<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Crush\Tui\PaneDragController;

/**
 * Unit face of the phase-3 gesture state machine: the three states, the
 * arm/disarm laws, and the two pieces of arithmetic the App delegates its
 * drag decisions to (column-share pixels for a resize, drop side and slot
 * index for a dock drag). The wiring face — MouseMsg sequences through the
 * real `App::update()` — lives in {@see PaneDragIntegrationTest}.
 *
 * @see PaneDragController
 */
final class PaneDragControllerTest extends TestCase
{
    // =========================================================================
    // State machine
    // =========================================================================

    public function testTheIdleStateCarriesNoGesture(): void
    {
        $idle = PaneDragController::idle();

        self::assertTrue($idle->isIdle());
        self::assertFalse($idle->isResizing());
        self::assertFalse($idle->isDockDragging());
        self::assertFalse($idle->isArmed());
        self::assertFalse($idle->isPreviewed());
        self::assertNull($idle->dragSide());
        self::assertNull($idle->dragPaneId());
    }

    public function testPressingADividerBeginsAnArmedResize(): void
    {
        $drag = PaneDragController::idle()->beginResize(Side::Left, 31, 13);

        self::assertTrue($drag->isResizing());
        self::assertFalse($drag->isIdle());
        // Grabbing the divider column IS the intent — no travel needed.
        self::assertTrue($drag->isArmed());
        self::assertSame(Side::Left, $drag->dragSide());
        self::assertFalse($drag->isPreviewed());
        // The press left the idle value untouched (immutable transitions).
        self::assertTrue(PaneDragController::idle()->isIdle());
    }

    public function testPressingADockedHeaderBeginsAnUnarmedDockDrag(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        self::assertTrue($drag->isDockDragging());
        self::assertSame('files', $drag->dragPaneId());
        // Unarmed by law: a release that never travels must still complete
        // the plain click-to-focus the chrome tracker always completed.
        self::assertFalse($drag->isArmed());
    }

    public function testMotionWithinToleranceLeavesADockDragUnarmed(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        self::assertFalse($drag->withMotion(10, 4)->isArmed(), 'pointer still');
        self::assertFalse($drag->withMotion(11, 4)->isArmed(), 'one cell right == tolerance');
        self::assertFalse($drag->withMotion(10, 5)->isArmed(), 'one cell down == tolerance');
    }

    public function testMotionPastToleranceArmsTheDockDragOnceAndForever(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        $armed = $drag->withMotion(12, 4);
        self::assertTrue($armed->isArmed());
        // Wandering back through the tolerance must NOT un-arm: the user
        // already committed to the drag by leaving the header.
        self::assertTrue($armed->withMotion(10, 4)->isArmed());
        // Vertical travel counts too (Manhattan distance).
        self::assertTrue($drag->withMotion(10, 7)->isArmed());
    }

    public function testTheFirstResizeMotionLatchesThePreviewedFlag(): void
    {
        $drag = PaneDragController::idle()->beginResize(Side::Left, 31, 13);

        $moved = $drag->withMotion(35, 13);
        self::assertTrue($moved->isPreviewed());
        // Latched: later motions reuse the same value once set (App swaps in
        // each returned copy, so idempotence keeps churn out of the model).
        self::assertSame($moved, $moved->withMotion(36, 13));
        self::assertTrue($moved->withMotion(36, 13)->isPreviewed());
    }

    public function testDockMotionNeverSetsThePreviewedFlag(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('tools', 10, 4);

        self::assertFalse($drag->withMotion(20, 9)->isPreviewed());
    }

    public function testWithMotionOnTheIdleStateIsANoOp(): void
    {
        $idle = PaneDragController::idle();

        self::assertSame($idle, $idle->withMotion(50, 50));
    }

    // =========================================================================
    // Resize arithmetic
    // =========================================================================

    public function testALeftDragTranslatesTheGrabByThePointerTravel(): void
    {
        $drag = PaneDragController::idle()->beginResize(Side::Left, 44, 13);

        // Grab painted on column 44, the side measures 39 content columns,
        // the centre can spare 20. Releasing ten columns east grows the
        // band by exactly ten — painted/resolve offsets cancel out.
        self::assertSame(49, $drag->resizeColumns(54, 39, 20, 20));
    }

    public function testARightDragGrowsLeftwardWithTheSameTravelRule(): void
    {
        $drag = PaneDragController::idle()->beginResize(Side::Right, 80, 13);

        // A right band widens when the pointer travels WEST of its grab.
        self::assertSame(47, $drag->resizeColumns(72, 39, 20, 20));
        self::assertSame(35, $drag->resizeColumns(84, 39, 20, 20));
    }

    public function testADragNeverShrinksASideBelowItsFloor(): void
    {
        $left = PaneDragController::idle()->beginResize(Side::Left, 44, 13);
        $right = PaneDragController::idle()->beginResize(Side::Right, 44, 13);

        self::assertSame(20, $left->resizeColumns(14, 39, 20, 20), 'left clamps at sideMinCols');
        self::assertSame(20, $right->resizeColumns(74, 39, 20, 20), 'right clamps at sideMinCols');
    }

    public function testADragNeverGrowsASidePastTheCentresFloor(): void
    {
        $left = PaneDragController::idle()->beginResize(Side::Left, 44, 13);
        $right = PaneDragController::idle()->beginResize(Side::Right, 80, 13);

        // current 39 plus a centre that can spare 16 → the side tops out at 55.
        self::assertSame(55, $left->resizeColumns(300, 39, 20, 16));
        self::assertSame(55, $right->resizeColumns(1, 39, 20, 16));
    }

    public function testAFloorVersusCeilingFrameStillAnswersWithTheLocalFloor(): void
    {
        // A centre already below its floor (room -2) plus a band under the
        // side floor: the drag STATES the request (floor wins locally);
        // DockLayout::resolve()'s degradation ladder keeps the geometry
        // guarantee at paint time.
        $drag = PaneDragController::idle()->beginResize(Side::Left, 44, 13);

        self::assertSame(20, $drag->resizeColumns(94, 12, 20, -2));
    }

    public function testResizeColumnsRefusesEveryOtherState(): void
    {
        $this->expectException(\LogicException::class);

        PaneDragController::idle()->resizeColumns(40, 120, 119, 20, 24);
    }

    public function testResizeColumnsRefusesADockDrag(): void
    {
        $this->expectException(\LogicException::class);

        PaneDragController::idle()->beginDockDrag('files', 10, 4)->resizeColumns(40, 120, 119, 20, 24);
    }

    // =========================================================================
    // Drop-side arithmetic
    // =========================================================================

    public function testAReleaseWestOfTheCentreLandsLeft(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        // Centre occupies 0-based columns 30..89.
        self::assertSame(Side::Left, $drag->dockDropSide(10, 30, 89));
        self::assertSame(Side::Left, $drag->dockDropSide(30, 30, 89), 'one cell before the centre edge');
    }

    public function testAReleaseEastOfTheCentreLandsRight(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        self::assertSame(Side::Right, $drag->dockDropSide(110, 30, 89));
        self::assertSame(Side::Right, $drag->dockDropSide(91, 30, 89), 'first cell past the centre');
    }

    public function testAReleaseInsideTheCentreCancels(): void
    {
        $drag = PaneDragController::idle()->beginDockDrag('files', 10, 4);

        self::assertNull($drag->dockDropSide(31, 30, 89), 'first centre column');
        self::assertNull($drag->dockDropSide(90, 30, 89), 'last centre column');
        self::assertNull($drag->dockDropSide(60, 30, 89), 'dead centre');
    }

    // =========================================================================
    // Slot-index arithmetic
    // =========================================================================

    public function testTheInsertIndexCountsTheSlotsPaintedAboveTheRelease(): void
    {
        $tops = [2, 10, 20];

        self::assertSame(0, PaneDragController::insertIndex(1, $tops), 'above every slot');
        self::assertSame(0, PaneDragController::insertIndex(3, $tops), 'inside the first slot');
        self::assertSame(1, PaneDragController::insertIndex(5, $tops), 'below the first top');
        self::assertSame(2, PaneDragController::insertIndex(15, $tops));
        self::assertSame(3, PaneDragController::insertIndex(100, $tops), 'below every slot appends');
    }

    public function testAnEmptyStackAlwaysInsertsAtZero(): void
    {
        self::assertSame(0, PaneDragController::insertIndex(7, []));
    }
}
