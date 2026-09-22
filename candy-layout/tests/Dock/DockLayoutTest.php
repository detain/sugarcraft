<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests\Dock;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Layout\Region;

/**
 * DockLayout — the immutable dock description: slot editing, rational column
 * shares with the 1/2 pair clamp, frame resolution with its degradation
 * ladder, and the versioned manifest round-trip.
 */
final class DockLayoutTest extends TestCase
{
    /**
     * A dock with one pane on each side, the shape most resolve tests use.
     */
    private function dockedBothSides(string $center = 'chat'): DockLayout
    {
        return DockLayout::new($center)
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Right, 'term');
    }

    /**
     * @param list<\SugarCraft\Layout\Dock\DockSlot> $slots
     * @return list<string>
     */
    private function ids(array $slots): array
    {
        return array_map(static fn($slot): string => $slot->paneId, $slots);
    }

    // ── Defaults ─────────────────────────────────────────────────────────────

    public function testNewStartsEmptyAtTheDesignDefaults(): void
    {
        $layout = DockLayout::new();

        $this->assertSame('chat', $layout->centerPaneId);
        $this->assertSame([], $layout->slots(Side::Left));
        $this->assertSame([], $layout->slots(Side::Right));
        $this->assertSame(['num' => 1, 'denom' => 3], $layout->columnShare(Side::Left));
        $this->assertSame(['num' => 1, 'denom' => 3], $layout->columnShare(Side::Right));
        $this->assertSame(24, $layout->centerMinCols);
        $this->assertSame(20, $layout->sideMinCols);
        $this->assertSame(1, $layout->dividerCols);
    }

    public function testNewAcceptsACustomCenterPaneId(): void
    {
        $this->assertSame('main', DockLayout::new('main')->centerPaneId);
    }

    public function testEmptyCenterPaneIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('centerPaneId must be a non-empty string');
        DockLayout::new('');
    }

    // ── withSlotAdded ────────────────────────────────────────────────────────

    public function testSlotAddedAppendsByDefault(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'git');

        $this->assertSame(['files', 'git'], array_map(
            static fn($slot): string => $slot->paneId,
            $layout->slots(Side::Left),
        ));
    }

    public function testSlotAddedAtFrontKeepsExistingOrder(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'git', 0);

        $this->assertSame(['git', 'files'], array_map(
            static fn($slot): string => $slot->paneId,
            $layout->slots(Side::Left),
        ));
    }

    public function testSlotAddedRejectsDuplicateOnSameSide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Pane 'files' is already docked");
        DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'files');
    }

    public function testSlotAddedRejectsDuplicateAcrossSides(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one home');
        DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Right, 'files');
    }

    public function testSlotAddedRejectsOutOfBoundsIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of bounds 0..0 for Left side');
        DockLayout::new()->withSlotAdded(Side::Left, 'files', 1);
    }

    public function testSlotAddedRejectsNegativeIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot index -1 out of bounds');
        DockLayout::new()->withSlotAdded(Side::Right, 'files', -1);
    }

    public function testSlotAddedLeavesOriginalUntouched(): void
    {
        $base = DockLayout::new();
        $extended = $base->withSlotAdded(Side::Left, 'files');

        $this->assertNotSame($base, $extended);
        $this->assertSame([], $base->slots(Side::Left));
        $this->assertCount(1, $extended->slots(Side::Left));
    }

    // ── withSlotRemoved ───────────────────────────────────────────────────────

    public function testSlotRemovedFromItsSideOnly(): void
    {
        $layout = $this->dockedBothSides()->withSlotRemoved('files');

        $this->assertSame([], $layout->slots(Side::Left));
        $this->assertCount(1, $layout->slots(Side::Right));
    }

    public function testSlotRemovedForUnknownPaneIsAnIdenticalNoOp(): void
    {
        $layout = $this->dockedBothSides();
        $this->assertSame($layout, $layout->withSlotRemoved('ghost'));
    }

    public function testSlotRemovedLeavesOriginalUntouched(): void
    {
        $base = $this->dockedBothSides();
        $base->withSlotRemoved('files');

        $this->assertCount(1, $base->slots(Side::Left));
    }

    // ── withSlotMovedTo ───────────────────────────────────────────────────────

    public function testMoveOfUnknownPaneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot move undocked pane 'ghost'");
        DockLayout::new()->withSlotMovedTo('ghost', Side::Left);
    }

    public function testCrossSideMoveWithoutIndexAppendsToTargetEnd(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'git')
            ->withSlotAdded(Side::Right, 'term')
            ->withSlotMovedTo('files', Side::Right);

        $this->assertSame(['git'], array_map(
            static fn($slot): string => $slot->paneId,
            $layout->slots(Side::Left),
        ));
        $this->assertSame(['term', 'files'], $this->ids($layout->slots(Side::Right)));
    }

    public function testCrossSideMoveWithIndexInsertsAtPosition(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Right, 'term')
            ->withSlotAdded(Side::Right, 'git')
            ->withSlotMovedTo('files', Side::Right, 0);

        $this->assertSame(['files', 'term', 'git'], $this->ids($layout->slots(Side::Right)));
        $this->assertSame([], $layout->slots(Side::Left));
    }

    public function testSameSideMoveWithoutIndexGoesToEnd(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withSlotAdded(Side::Left, 'c')
            ->withSlotMovedTo('a', Side::Left);

        $this->assertSame(['b', 'c', 'a'], $this->ids($layout->slots(Side::Left)));
    }

    public function testSameSideMoveWithIndexReorders(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withSlotAdded(Side::Left, 'c')
            ->withSlotMovedTo('c', Side::Left, 0);

        $this->assertSame(['c', 'a', 'b'], $this->ids($layout->slots(Side::Left)));
    }

    public function testMoveRejectsOutOfBoundsIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('out of bounds 0..0 for Right side');
        DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotMovedTo('a', Side::Right, 1);
    }

    public function testMoveLeavesOriginalUntouched(): void
    {
        $base = $this->dockedBothSides();
        $base->withSlotMovedTo('files', Side::Right);

        $this->assertCount(1, $base->slots(Side::Left));
        $this->assertCount(1, $base->slots(Side::Right));
    }

    // ── withColumnShare ───────────────────────────────────────────────────────

    public function testColumnShareRejectsInvalidRationals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Column share must carry numerator and denominator >= 1; got 0/3');
        DockLayout::new()->withColumnShare(Side::Left, 0, 3);
    }

    public function testColumnShareRejectsZeroDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got 1/0');
        DockLayout::new()->withColumnShare(Side::Right, 1, 0);
    }

    public function testColumnShareWithinCapIsStoredVerbatim(): void
    {
        $layout = DockLayout::new()->withColumnShare(Side::Left, 1, 10);
        $this->assertSame(['num' => 1, 'denom' => 10], $layout->columnShare(Side::Left));
    }

    public function testColumnShareClampsToKeepCenterAtLeastHalf(): void
    {
        // other side sits at 1/3 → cap = 1/2 - 1/3 = 1/6; the 1/2 request is
        // clamped by pair rule (never thrown).
        $layout = DockLayout::new()->withColumnShare(Side::Right, 1, 2);
        $this->assertSame(['num' => 1, 'denom' => 6], $layout->columnShare(Side::Right));
    }

    public function testTighteningMutatorAlsoClampsTheThirdsDefault(): void
    {
        // Re-setting Left to its own creation value still goes through the
        // pair rule: cap = 1/2 - 1/3 = 1/6, so the stored share tightens.
        $layout = DockLayout::new()->withColumnShare(Side::Left, 1, 3);
        $this->assertSame(['num' => 1, 'denom' => 6], $layout->columnShare(Side::Left));
    }

    public function testCapDerivesFromTheOtherSideCurrentShare(): void
    {
        $layout = DockLayout::new()
            ->withColumnShare(Side::Left, 1, 10)
            ->withColumnShare(Side::Right, 1, 2);
        // cap = 1/2 - 1/10 = 8/20 → 1/2 exceeds it → stored as the cap itself.
        $this->assertSame(['num' => 8, 'denom' => 20], $layout->columnShare(Side::Right));
    }

    public function testColumnShareClampNeverThrowsAndLeavesOriginalUntouched(): void
    {
        $base = DockLayout::new();
        $clamped = $base->withColumnShare(Side::Right, 9, 10);

        $this->assertNotSame($base, $clamped);
        $this->assertSame(['num' => 1, 'denom' => 3], $base->columnShare(Side::Right));
    }

    public function testColumnShareFloorCapOnOverGrownRestoredState(): void
    {
        // Only a hand-restored manifest can hold both halves at once; a share
        // request then floors at the smallest representable cap 1/(2*denom).
        $layout = DockLayout::fromArray($this->manifestWithShares([1, 2], [1, 2]));
        $tightened = $layout->withColumnShare(Side::Left, 1, 3);

        $this->assertSame(['num' => 1, 'denom' => 4], $tightened->columnShare(Side::Left));
    }

    /**
     * @param array{0:int,1:int} $left
     * @param array{0:int,1:int} $right
     * @return array<string,mixed>
     */
    private function manifestWithShares(array $left, array $right): array
    {
        return [
            'version' => 1,
            'center' => 'chat',
            'sides' => ['left' => [], 'right' => []],
            'columnShare' => ['left' => $left, 'right' => $right],
            'minimums' => [24, 20],
        ];
    }

    // ── withStackWeight ──────────────────────────────────────────────────────

    public function testStackWeightReplacesTheSlotAtItsIndex(): void
    {
        $layout = $this->dockedBothSides()
            ->withSlotAdded(Side::Left, 'git')
            ->withStackWeight(Side::Left, 1, 3, 2);

        $this->assertSame([3, 2], [
            $layout->slots(Side::Left)[1]->weightNum,
            $layout->slots(Side::Left)[1]->weightDenom,
        ]);
        $this->assertSame([1, 1], [
            $layout->slots(Side::Left)[0]->weightNum,
            $layout->slots(Side::Left)[0]->weightDenom,
        ]);
    }

    public function testStackWeightRejectsOutOfBoundsIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stack weight index 0 out of bounds 0..-1 for Left side');
        DockLayout::new()->withStackWeight(Side::Left, 0, 1, 1);
    }

    public function testStackWeightRejectsNegativeIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stack weight index -1 out of bounds');
        $this->dockedBothSides()->withStackWeight(Side::Right, -1, 1, 1);
    }

    public function testStackWeightValidatesTheRationalThroughDockSlot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numerator must be >= 1; got 0');
        $this->dockedBothSides()->withStackWeight(Side::Left, 0, 0, 1);
    }

    public function testStackWeightLeavesOriginalUntouched(): void
    {
        $base = $this->dockedBothSides();
        $base->withStackWeight(Side::Left, 0, 5, 3);

        $this->assertSame(1, $base->slots(Side::Left)[0]->weightNum);
    }

    // ── withMinimums ─────────────────────────────────────────────────────────

    public function testMinimumsAreStoredAndImmutable(): void
    {
        $base = DockLayout::new();
        $tuned = $base->withMinimums(30, 12);

        $this->assertSame(24, $base->centerMinCols);
        $this->assertSame(30, $tuned->centerMinCols);
        $this->assertSame(12, $tuned->sideMinCols);
    }

    public function testMinimumsRejectZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimums must be >= 1');
        DockLayout::new()->withMinimums(0, 20);
    }

    public function testMinimumsRejectNegativeSideMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got centerMinCols=24, sideMinCols=-1');
        DockLayout::new()->withMinimums(24, -1);
    }

    // ── resolve: basic geometry ──────────────────────────────────────────────

    public function testResolveOnWideFrameUsesDefaultThirds(): void
    {
        $geometry = $this->dockedBothSides()->resolve(Region::fromSize(100, 30));

        $this->assertEquals(new Region(0, 0, 32, 30), $geometry->regionFor('files'));
        $this->assertEquals(new Region(33, 0, 34, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(68, 0, 32, 30), $geometry->regionFor('term'));
        $this->assertSame(
            [['x' => 32, 'side' => Side::Left], ['x' => 67, 'side' => Side::Right]],
            $geometry->dividerColumns(),
        );
    }

    public function testResolveCarriesCenterInsideRegionsUnderItsPaneId(): void
    {
        $geometry = DockLayout::new('main')->resolve(Region::fromSize(100, 30));

        $this->assertArrayHasKey('main', $geometry->regions);
        $this->assertEquals(Region::fromSize(100, 30), $geometry->regionFor('main'));
        $this->assertSame([], $geometry->dividerColumns());
    }

    public function testResolveWithOneEmptySideHasNoDividerOrColumnForIt(): void
    {
        $layout = DockLayout::new()->withSlotAdded(Side::Left, 'files');
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        $this->assertEquals(new Region(0, 0, 33, 30), $geometry->regionFor('files'));
        $this->assertEquals(new Region(34, 0, 66, 30), $geometry->regionFor('chat'));
        $this->assertNull($geometry->regionFor('term'));
        $this->assertSame([['x' => 33, 'side' => Side::Left]], $geometry->dividerColumns());
    }

    public function testResolveRightOnlyAnchorsCenterAtTheOrigin(): void
    {
        $layout = DockLayout::new()->withSlotAdded(Side::Right, 'term');
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        $this->assertEquals(new Region(0, 0, 66, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(67, 0, 33, 30), $geometry->regionFor('term'));
        $this->assertSame([['x' => 66, 'side' => Side::Right]], $geometry->dividerColumns());
    }

    public function testResolveWithBothSidesEmptyGivesCenterTheWholeFrame(): void
    {
        $geometry = DockLayout::new()->resolve(Region::fromSize(80, 12));

        $this->assertEquals(new Region(0, 0, 80, 12), $geometry->regionFor('chat'));
        $this->assertCount(1, $geometry->regions);
        $this->assertSame([], $geometry->dividerColumns());
    }

    public function testResolveOnDegenerateFramesIsEmptyNeverThrowing(): void
    {
        $docked = $this->dockedBothSides();

        $this->assertTrue($docked->resolve(Region::fromSize(0, 10))->isEmpty());
        $this->assertTrue($docked->resolve(Region::fromSize(10, 0))->isEmpty());
        $this->assertTrue($docked->resolve(Region::fromSize(0, 0))->isEmpty());
    }

    public function testResolveOnOneByOneFrameDropsSidesToCenterOnly(): void
    {
        $geometry = $this->dockedBothSides()->resolve(Region::fromSize(1, 1));

        $this->assertFalse($geometry->isEmpty());
        $this->assertEquals(new Region(0, 0, 1, 1), $geometry->regionFor('chat'));
        $this->assertNull($geometry->regionFor('files'));
        $this->assertSame([], $geometry->dividerColumns());
    }

    public function testResolvePreservesFrameOrigin(): void
    {
        $geometry = $this->dockedBothSides()->resolve(new Region(5, 7, 100, 30));

        $this->assertEquals(new Region(5, 7, 32, 30), $geometry->regionFor('files'));
        $this->assertEquals(new Region(38, 7, 34, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(73, 7, 32, 30), $geometry->regionFor('term'));
        $this->assertSame(
            [['x' => 37, 'side' => Side::Left], ['x' => 72, 'side' => Side::Right]],
            $geometry->dividerColumns(),
        );
    }

    // ── resolve: min protection + degradation ladder ────────────────────────

    public function testMinProtectionRaisesAShortSideWhileCenterKeepsItsFloor(): void
    {
        $layout = $this->dockedBothSides()->withColumnShare(Side::Left, 1, 20);
        // usable 98: left natural floor(98/20)=4 → raised to 20; right 32;
        // center = 100 - 2 - 52 = 46 ≥ 24 so the raise stands.
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        $this->assertEquals(new Region(0, 0, 20, 30), $geometry->regionFor('files'));
        $this->assertEquals(new Region(21, 0, 46, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(68, 0, 32, 30), $geometry->regionFor('term'));
    }

    public function testMinProtectionRefusesARaiseThatWouldStarveTheCenter(): void
    {
        $layout = $this->dockedBothSides()->withColumnShare(Side::Left, 1, 20);
        // usable 53: raising left to 20 would leave center 16 < 24 → blocked;
        // raising right to 20 leaves center 31 ≥ 24 → allowed.
        $geometry = $layout->resolve(Region::fromSize(55, 30));

        $this->assertEquals(new Region(0, 0, 2, 30), $geometry->regionFor('files'));
        $this->assertEquals(new Region(3, 0, 31, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(35, 0, 20, 30), $geometry->regionFor('term'));
    }

    public function testDegradationDropsTheSideWithFewerSlotsFirst(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withSlotAdded(Side::Left, 'c')
            ->withSlotAdded(Side::Right, 'term');
        // 65 cols cannot honor [20+1+24+1+20]=66: right (1 slot) goes first.
        $geometry = $layout->resolve(Region::fromSize(65, 30));

        $this->assertNull($geometry->regionFor('term'));
        $this->assertEquals(new Region(0, 0, 21, 9), $geometry->regionFor('a'));
        $this->assertEquals(new Region(22, 0, 43, 30), $geometry->regionFor('chat'));
        $this->assertSame([['x' => 21, 'side' => Side::Left]], $geometry->dividerColumns());
        $this->assertCount(4, $geometry->regions);
    }

    public function testDegradationTieDropsLeftFirst(): void
    {
        $geometry = $this->dockedBothSides()->resolve(Region::fromSize(65, 30));

        // 1 vs 1 slot tie → Left dropped; Right keeps the dock column.
        $this->assertNull($geometry->regionFor('files'));
        $this->assertEquals(new Region(0, 0, 43, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(44, 0, 21, 30), $geometry->regionFor('term'));
        $this->assertSame([['x' => 43, 'side' => Side::Right]], $geometry->dividerColumns());
    }

    public function testDegradationLadderEndsCenterOnlyOnTinyFrames(): void
    {
        $geometry = $this->dockedBothSides()->resolve(Region::fromSize(30, 30));

        $this->assertEquals(new Region(0, 0, 30, 30), $geometry->regionFor('chat'));
        $this->assertNull($geometry->regionFor('files'));
        $this->assertNull($geometry->regionFor('term'));
        $this->assertSame([], $geometry->dividerColumns());
    }

    // ── resolve: stack splitting ─────────────────────────────────────────────

    public function testStackSplitIsProportionalWithResidualToLastSlot(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withStackWeight(Side::Left, 1, 2, 1);
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        // rows = 30 - 1 gap = 29 over weights [1, 2] → floor 9 / 19, residual 1 → 20.
        $this->assertEquals(new Region(0, 0, 33, 9), $geometry->regionFor('a'));
        $this->assertEquals(new Region(0, 10, 33, 20), $geometry->regionFor('b'));
    }

    public function testThreeEqualSlotsSplitResidualToLastToo(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withSlotAdded(Side::Left, 'c');
        $geometry = $layout->resolve(Region::fromSize(65, 30));

        // rows = 28, equal weights → 9, 9, 9 + residual 1 on the last slot.
        $this->assertEquals(new Region(0, 0, 21, 9), $geometry->regionFor('a'));
        $this->assertEquals(new Region(0, 10, 21, 9), $geometry->regionFor('b'));
        $this->assertEquals(new Region(0, 20, 21, 10), $geometry->regionFor('c'));
    }

    public function testAbsurdShortStacksCollapseToZeroHeightsWithoutThrowing(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'a')
            ->withSlotAdded(Side::Left, 'b')
            ->withSlotAdded(Side::Left, 'c');
        $geometry = $layout->resolve(Region::fromSize(60, 2));

        $this->assertSame(0, $geometry->regionFor('a')?->height);
        $this->assertSame(0, $geometry->regionFor('b')?->height);
        $this->assertSame(0, $geometry->regionFor('c')?->height);
        // y still advances by height + gap — deterministic, non-throwing.
        $this->assertSame(0, $geometry->regionFor('a')?->y);
        $this->assertSame(1, $geometry->regionFor('b')?->y);
        $this->assertSame(2, $geometry->regionFor('c')?->y);
    }

    public function testStackOfOneSlotTakesTheFullHeight(): void
    {
        $layout = DockLayout::new()->withSlotAdded(Side::Left, 'solo');
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        $this->assertEquals(new Region(0, 0, 33, 30), $geometry->regionFor('solo'));
    }

    public function testCustomMinimumsSteerDegradation(): void
    {
        $layout = $this->dockedBothSides()->withMinimums(40, 20);
        // At 100 wide default thirds give center 34 < 40 → degrade (tie drops
        // Left) → single side: usable 99, right 33, center 66 ≥ 40 → stands.
        $geometry = $layout->resolve(Region::fromSize(100, 30));

        $this->assertNull($geometry->regionFor('files'));
        $this->assertEquals(new Region(0, 0, 66, 30), $geometry->regionFor('chat'));
        $this->assertEquals(new Region(67, 0, 33, 30), $geometry->regionFor('term'));
    }

    // ── toArray / fromArray ──────────────────────────────────────────────────

    public function testToArrayEmitsTheExactVersionedShape(): void
    {
        $layout = DockLayout::new()
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'git', 0)
            ->withStackWeight(Side::Left, 1, 2, 3);

        $this->assertSame([
            'version' => 1,
            'center' => 'chat',
            'sides' => [
                'left' => [
                    ['id' => 'git', 'weight' => [1, 1]],
                    ['id' => 'files', 'weight' => [2, 3]],
                ],
                'right' => [],
            ],
            'columnShare' => [
                'left' => [1, 3],
                'right' => [1, 3],
            ],
            'minimums' => [24, 20],
        ], $layout->toArray());
    }

    public function testRoundTripIdentityOnDefaultLayout(): void
    {
        $layout = DockLayout::new();
        $this->assertSame($layout->toArray(), DockLayout::fromArray($layout->toArray())->toArray());
    }

    public function testRoundTripIdentityOnBusyLayout(): void
    {
        $layout = $this->dockedBothSides('main')
            ->withSlotAdded(Side::Left, 'git')
            ->withStackWeight(Side::Left, 0, 3, 4)
            ->withSlotMovedTo('term', Side::Left, 0)
            ->withColumnShare(Side::Right, 1, 10)
            ->withMinimums(30, 15);

        $this->assertSame($layout->toArray(), DockLayout::fromArray($layout->toArray())->toArray());
    }

    public function testFromArrayRestoresSharesVerbatimEvenWhenTheyBreakThePairRule(): void
    {
        // The 1/2 cap is a mutator policy, not a state invariant — persisted
        // manifests restore exactly as written so round-trips stay lossless.
        $layout = DockLayout::fromArray($this->manifestWithShares([1, 2], [1, 2]));

        $this->assertSame(['num' => 1, 'denom' => 2], $layout->columnShare(Side::Left));
        $this->assertSame(['num' => 1, 'denom' => 2], $layout->columnShare(Side::Right));
    }

    public function testFromArrayRestoresSlotsOnBothSides(): void
    {
        $layout = DockLayout::fromArray([
            'version' => 1,
            'center' => 'chat',
            'sides' => [
                'left' => [['id' => 'files', 'weight' => [1, 1]]],
                'right' => [['id' => 'term', 'weight' => [2, 1]]],
            ],
            'columnShare' => ['left' => [1, 4], 'right' => [1, 6]],
            'minimums' => [30, 12],
        ]);

        $this->assertSame(['files'], $this->ids($layout->slots(Side::Left)));
        $this->assertSame(2, $layout->slots(Side::Right)[0]->weightNum);
        $this->assertSame(30, $layout->centerMinCols);
        $this->assertSame(12, $layout->sideMinCols);
        $this->assertSame(1, $layout->dividerCols);
    }

    // ── fromArray: every malformed shape throws naming its key ──────────────

    public function testFromRejectsMissingVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'version' must be int 1");
        $data = $this->validManifest();
        unset($data['version']);
        DockLayout::fromArray($data);
    }

    public function testFromRejectsWrongVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'version' must be int 1");
        DockLayout::fromArray(['version' => 2] + $this->validManifest());
    }

    public function testFromRejectsMissingCenter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'center' must be a non-empty string");
        $data = $this->validManifest();
        unset($data['center']);
        DockLayout::fromArray($data);
    }

    public function testFromRejectsEmptyCenter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'center' must be a non-empty string");
        DockLayout::fromArray(['center' => ''] + $this->validManifest());
    }

    public function testFromRejectsNonStringCenter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockLayout::fromArray(['center' => 7] + $this->validManifest());
    }

    public function testFromRejectsMissingSides(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'sides' must be an array");
        $data = $this->validManifest();
        unset($data['sides']);
        DockLayout::fromArray($data);
    }

    public function testFromRejectsMissingSideKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'sides.right' must be an array");
        DockLayout::fromArray(['sides' => ['left' => []]] + $this->validManifest());
    }

    public function testFromRejectsNonArraySlotRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sides.left.0' must be an array");
        DockLayout::fromArray(
            ['sides' => ['left' => ['files'], 'right' => []]] + $this->validManifest()
        );
    }

    public function testFromRejectsEmptySlotId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sides.left.0.id' must be a non-empty string");
        DockLayout::fromArray(
            ['sides' => ['left' => [['id' => '', 'weight' => [1, 1]]], 'right' => []]]
            + $this->validManifest()
        );
    }

    public function testFromRejectsNonStringSlotId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockLayout::fromArray(
            ['sides' => ['left' => [['id' => 5, 'weight' => [1, 1]]], 'right' => []]]
            + $this->validManifest()
        );
    }

    public function testFromRejectsMissingSlotId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sides.right.0.id' must be a non-empty string");
        DockLayout::fromArray(
            ['sides' => ['left' => [], 'right' => [['weight' => [1, 1]]]]]
            + $this->validManifest()
        );
    }

    public function testFromRejectsDuplicatePaneAcrossSides(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicates pane 'files' across sides");
        DockLayout::fromArray([
            'sides' => [
                'left' => [['id' => 'files', 'weight' => [1, 1]]],
                'right' => [['id' => 'files', 'weight' => [1, 1]]],
            ],
        ] + $this->validManifest());
    }

    public function testFromRejectsNonIntWeightPair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sides.left.0.weight' must be a [num, denom] pair of ints");
        DockLayout::fromArray([
            'sides' => [
                'left' => [['id' => 'files', 'weight' => [1.0, 1]]],
                'right' => [],
            ],
        ] + $this->validManifest());
    }

    public function testFromRejectsShortWeightPair(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockLayout::fromArray([
            'sides' => [
                'left' => [['id' => 'files', 'weight' => [1]]],
                'right' => [],
            ],
        ] + $this->validManifest());
    }

    public function testFromRejectsZeroWeightValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("sides.left.0.weight' must carry values >= 1");
        DockLayout::fromArray([
            'sides' => [
                'left' => [['id' => 'files', 'weight' => [0, 1]]],
                'right' => [],
            ],
        ] + $this->validManifest());
    }

    public function testFromRejectsMissingColumnShare(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'columnShare' must be an array");
        $data = $this->validManifest();
        unset($data['columnShare']);
        DockLayout::fromArray($data);
    }

    public function testFromRejectsMissingSideInsideColumnShare(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("columnShare.right' must be a [num, denom] pair of ints");
        DockLayout::fromArray(['columnShare' => ['left' => [1, 3]]] + $this->validManifest());
    }

    public function testFromRejectsZeroInColumnShare(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("columnShare.left' must carry values >= 1");
        DockLayout::fromArray(
            ['columnShare' => ['left' => [0, 3], 'right' => [1, 3]]] + $this->validManifest()
        );
    }

    public function testFromRejectsMissingMinimums(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'minimums' must be a [center, side] pair of ints");
        $data = $this->validManifest();
        unset($data['minimums']);
        DockLayout::fromArray($data);
    }

    public function testFromRejectsZeroMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'minimums' must carry values >= 1");
        DockLayout::fromArray(['minimums' => [24, 0]] + $this->validManifest());
    }

    public function testFromRejectsNonPairMinimums(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockLayout::fromArray(['minimums' => [24, 20, 1]] + $this->validManifest());
    }

    /**
     * @return array<string,mixed>
     */
    private function validManifest(): array
    {
        return DockLayout::new()->toArray();
    }
}
