<?php

declare(strict_types=1);

namespace SugarCraft\Focus\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Focus\FocusRing;

final class FocusRingTest extends TestCase
{
    public function testNewRingIsEmpty(): void
    {
        $ring = FocusRing::new();

        self::assertTrue($ring->isEmpty());
        self::assertSame(0, $ring->count());
        self::assertSame(-1, $ring->index());
        self::assertNull($ring->current());
        self::assertSame([], $ring->ids());
    }

    public function testOfFocusesFirstAndPreservesOrder(): void
    {
        $ring = FocusRing::of('sidebar', 'grid', 'filter');

        self::assertSame(['sidebar', 'grid', 'filter'], $ring->ids());
        self::assertSame('sidebar', $ring->current());
        self::assertSame(0, $ring->index());
        self::assertSame(3, $ring->count());
        self::assertFalse($ring->isEmpty());
    }

    public function testOfDropsDuplicatesKeepingFirstOccurrence(): void
    {
        $ring = FocusRing::of('a', 'b', 'a', 'c', 'b');

        self::assertSame(['a', 'b', 'c'], $ring->ids());
        self::assertSame('a', $ring->current());
    }

    public function testOfWithNoArgumentsIsEmpty(): void
    {
        self::assertTrue(FocusRing::of()->isEmpty());
        self::assertNull(FocusRing::of()->current());
    }

    public function testRegisterIntoEmptyRingFocusesIt(): void
    {
        $ring = FocusRing::new()->register('grid');

        self::assertSame(['grid'], $ring->ids());
        self::assertSame('grid', $ring->current());
        self::assertTrue($ring->isFocused('grid'));
    }

    public function testRegisterAppendsWithoutMovingFocus(): void
    {
        $ring = FocusRing::of('a', 'b')->register('c');

        self::assertSame(['a', 'b', 'c'], $ring->ids());
        self::assertSame('a', $ring->current(), 'focus stays on the first region');
    }

    public function testRegisterExistingIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');
        $same = $ring->register('a');

        self::assertSame($ring, $same);
    }

    public function testNextWrapsAround(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        $ring = $ring->next();
        self::assertSame('b', $ring->current());
        $ring = $ring->next();
        self::assertSame('c', $ring->current());
        $ring = $ring->next();
        self::assertSame('a', $ring->current(), 'wraps past the end');
    }

    public function testPreviousWrapsAround(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        $ring = $ring->previous();
        self::assertSame('c', $ring->current(), 'wraps past the start');
        $ring = $ring->previous();
        self::assertSame('b', $ring->current());
    }

    public function testNextAndPreviousAreNoOpsBelowTwoRegions(): void
    {
        $empty = FocusRing::new();
        self::assertSame($empty, $empty->next());
        self::assertSame($empty, $empty->previous());

        $single = FocusRing::of('only');
        self::assertSame($single, $single->next());
        self::assertSame($single, $single->previous());
        self::assertSame('only', $single->current());
    }

    public function testFocusMovesToRegisteredRegion(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('c');

        self::assertSame('c', $ring->current());
        self::assertSame(2, $ring->index());
        self::assertTrue($ring->isFocused('c'));
        self::assertFalse($ring->isFocused('a'));
    }

    public function testFocusUnknownRegionIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');
        $same = $ring->focus('missing');

        self::assertSame($ring, $same);
        self::assertSame('a', $same->current());
    }

    public function testFocusAlreadyFocusedRegionIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');
        self::assertSame($ring, $ring->focus('a'));
    }

    public function testUnregisterUnknownRegionIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');
        self::assertSame($ring, $ring->unregister('missing'));
    }

    public function testUnregisterRegionBeforeFocusKeepsFocusedRegion(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('c'); // index 2
        $ring = $ring->unregister('a');

        self::assertSame(['b', 'c'], $ring->ids());
        self::assertSame('c', $ring->current(), 'still focuses the same region');
        self::assertSame(1, $ring->index());
    }

    public function testUnregisterRegionAfterFocusKeepsFocusedRegion(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b'); // index 1
        $ring = $ring->unregister('c');

        self::assertSame(['a', 'b'], $ring->ids());
        self::assertSame('b', $ring->current());
        self::assertSame(1, $ring->index());
    }

    public function testUnregisterFocusedRegionShiftsToNextInSlot(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b'); // index 1
        $ring = $ring->unregister('b');

        self::assertSame(['a', 'c'], $ring->ids());
        self::assertSame('c', $ring->current(), 'the region that took the slot is focused');
        self::assertSame(1, $ring->index());
    }

    public function testUnregisterFocusedLastRegionClampsToNewEnd(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('c'); // index 2
        $ring = $ring->unregister('c');

        self::assertSame(['a', 'b'], $ring->ids());
        self::assertSame('b', $ring->current(), 'clamps to the new last region');
        self::assertSame(1, $ring->index());
    }

    public function testUnregisterLastRemainingRegionEmptiesTheRing(): void
    {
        $ring = FocusRing::of('only')->unregister('only');

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->current());
        self::assertSame(-1, $ring->index());
    }

    public function testReRegisterAfterEmptyRefocuses(): void
    {
        $ring = FocusRing::of('only')->unregister('only')->register('again');

        self::assertSame('again', $ring->current());
        self::assertSame(0, $ring->index());
    }

    public function testHasReportsMembership(): void
    {
        $ring = FocusRing::of('a', 'b');

        self::assertTrue($ring->has('a'));
        self::assertTrue($ring->has('b'));
        self::assertFalse($ring->has('c'));
    }

    public function testPreviousFromFirstRegionOnTwoElementRingWraps(): void
    {
        $ring = FocusRing::of('a', 'b'); // index 0
        self::assertSame('b', $ring->previous()->current(), 'no off-by-one wrapping back from index 0');
    }

    public function testUnregisterAfterFocusedSlotWithFocusAtZero(): void
    {
        $ring = FocusRing::of('a', 'b', 'c'); // index 0 ('a')
        $ring = $ring->unregister('c');

        self::assertSame(['a', 'b'], $ring->ids());
        self::assertSame('a', $ring->current(), 'focus untouched when removing after the focused slot');
        self::assertSame(0, $ring->index());
    }

    public function testEmptyStringIdIsADistinctRegionNotTheEmptySentinel(): void
    {
        $ring = FocusRing::of('', 'b');

        self::assertTrue($ring->has(''));
        self::assertSame('', $ring->current(), 'an empty-string id is a real focused region, not "nothing"');
        self::assertFalse($ring->isEmpty());
        self::assertSame(0, $ring->index());
    }

    public function testMutatorsDoNotMutateTheReceiver(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        $ring->next();
        $ring->focus('c');
        $ring->register('d');
        $ring->unregister('a');
        $ring->disable('b');
        $ring->enable('a');

        self::assertSame(['a', 'b', 'c'], $ring->ids(), 'original ring is unchanged');
        self::assertSame('a', $ring->current());
    }

    // ─── Step 2: ofStrict() ─────────────────────────────────────────────────

    public function testOfStrictBuildsRingFromUniqueIds(): void
    {
        $ring = FocusRing::ofStrict('a', 'b', 'c');

        self::assertSame(['a', 'b', 'c'], $ring->ids());
        self::assertSame('a', $ring->current());
        self::assertSame(0, $ring->index());
    }

    public function testOfStrictThrowsOnDuplicate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate region id "a" passed to FocusRing::ofStrict()');

        FocusRing::ofStrict('a', 'b', 'a');
    }

    public function testOfStrictWithNoArgumentsIsEmpty(): void
    {
        $ring = FocusRing::ofStrict();

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->current());
    }

    // ─── Step 3: reorder() ─────────────────────────────────────────────────

    public function testReorderPreservesFocusedRegionById(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('c');
        $ring = $ring->reorder('c', 'a', 'b');

        self::assertSame('c', $ring->current());
        self::assertSame(0, $ring->index());
    }

    public function testReorderToSetWithoutFocusedRegionFocusesFirst(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b');
        $ring = $ring->reorder('x', 'y');

        self::assertSame('x', $ring->current());
        self::assertSame(0, $ring->index());
    }

    public function testReorderToEmptyEmptiesTheRing(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->reorder();

        self::assertTrue($ring->isEmpty());
        self::assertNull($ring->current());
        self::assertSame(-1, $ring->index());
    }

    public function testReorderDropsDuplicates(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->reorder('x', 'a', 'x', 'y');

        self::assertSame(['x', 'a', 'y'], $ring->ids());
    }

    public function testReorderToIdenticalSetIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b');
        $same = $ring->reorder('a', 'b', 'c');

        self::assertSame($ring, $same);
    }

    // ─── Step 4: disable / enable / skip-aware traversal ───────────────────

    public function testDisableSkippedByNext(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b'); // focus 'a'

        $ring = $ring->next(); // should skip 'b', land on 'c'
        self::assertSame('c', $ring->current());
    }

    public function testDisableSkippedByPrevious(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('c')->disable('b');

        $ring = $ring->previous(); // should skip 'b', land on 'a'
        self::assertSame('a', $ring->current());
    }

    public function testEnableReinstatesRegionInTraversal(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b')->enable('b');

        $ring = $ring->next(); // back to normal traversal
        self::assertSame('b', $ring->current());
    }

    public function testNextIsNoOpWhenAllOtherRegionsDisabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('a')->disable('b')->disable('c');

        self::assertSame($ring, $ring->next(), 'no other enabled regions to move to');
    }

    public function testNextIsNoOpWhenEveryRegionDisabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('a')->disable('b')->disable('c');

        self::assertSame($ring, $ring->next(), 'all regions disabled — noOp');
        self::assertSame('a', $ring->current(), 'focus stays where it is even when focused region is disabled');
    }

    public function testDisableUnknownRegionIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');

        self::assertSame($ring, $ring->disable('unknown'));
    }

    public function testEnableAlreadyEnabledIsANoOp(): void
    {
        $ring = FocusRing::of('a', 'b');

        self::assertSame($ring, $ring->enable('a'));
    }

    public function testUnregisterClearsDisabledFlag(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b')->unregister('b');

        // 'b' is no longer in the ring, so isEnabled returns false even though
        // the disabled flag was cleared (the flag only matters for ids in the ring)
        self::assertFalse($ring->isEnabled('b'), ' unregistered id is not in ring so not enabled');
        self::assertFalse($ring->has('b'), 'id is gone from ring');
    }

    public function testReRegisterAfterDisableReEnables(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b')->unregister('b');

        $ring = $ring->register('b');

        self::assertTrue($ring->isEnabled('b'), 're-registered id should be enabled');
    }

    public function testDisabledFocusedRegionStillReportedByCurrent(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b')->disable('b');

        self::assertSame('b', $ring->current(), 'current() still names the focused id regardless of enabled state');
        self::assertFalse($ring->isEnabled('b'));
    }

    public function testDisableThenNextMovesOffDisabledFocus(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b')->disable('b');

        self::assertSame('b', $ring->current(), 'focus stays on disabled id until traversal');
        $ring = $ring->next();
        self::assertSame('c', $ring->current(), 'next() moves off the disabled focused region');
    }

    public function testIsEnabledReturnsTrueForRegisteredEnabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        self::assertTrue($ring->isEnabled('a'));
        self::assertTrue($ring->isEnabled('b'));
    }

    public function testIsEnabledReturnsFalseForDisabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b');

        self::assertFalse($ring->isEnabled('b'));
    }

    public function testIsEnabledReturnsFalseForUnregistered(): void
    {
        $ring = FocusRing::of('a', 'b');

        self::assertFalse($ring->isEnabled('unknown'));
    }

    public function testEnabledIdsReturnsOnlyEnabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->disable('b');

        self::assertSame(['a', 'c'], $ring->enabledIds());
    }

    // ─── disabledIds() ─────────────────────────────────────────────────────

    public function testDisabledIdsReturnsOnlyDisabledInTraversalOrder(): void
    {
        // Disable out of traversal order to prove the result is traversal-ordered,
        // not disable-ordered.
        $ring = FocusRing::of('a', 'b', 'c', 'd')->disable('d')->disable('b');

        self::assertSame(['b', 'd'], $ring->disabledIds());
    }

    public function testDisabledIdsIsEmptyWhenNothingDisabled(): void
    {
        self::assertSame([], FocusRing::of('a', 'b', 'c')->disabledIds());
        self::assertSame([], FocusRing::new()->disabledIds());
    }

    public function testDisabledIdsAndEnabledIdsPartitionTheRing(): void
    {
        $ring = FocusRing::of('a', 'b', 'c', 'd')->disable('b');

        self::assertSame(['a', 'c', 'd'], $ring->enabledIds());
        self::assertSame(['b'], $ring->disabledIds());
    }

    // ─── enabledCount() / disabledCount() ──────────────────────────────────

    public function testCountsOnAllEnabledRing(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        self::assertSame(3, $ring->enabledCount());
        self::assertSame(0, $ring->disabledCount());
    }

    public function testCountsReflectDisableAndEnable(): void
    {
        $ring = FocusRing::of('a', 'b', 'c', 'd')->disable('b')->disable('c');

        self::assertSame(2, $ring->enabledCount());
        self::assertSame(2, $ring->disabledCount());

        $ring = $ring->enable('b');
        self::assertSame(3, $ring->enabledCount());
        self::assertSame(1, $ring->disabledCount());
    }

    public function testCountsOnEmptyRingAreZero(): void
    {
        $ring = FocusRing::new();

        self::assertSame(0, $ring->enabledCount());
        self::assertSame(0, $ring->disabledCount());
    }

    public function testEnabledPlusDisabledCountEqualsTotal(): void
    {
        $ring = FocusRing::of('a', 'b', 'c', 'd', 'e')->disable('a')->disable('e');

        self::assertSame($ring->count(), $ring->enabledCount() + $ring->disabledCount());
    }

    // ─── getIterator() ─────────────────────────────────────────────────────

    public function testGetIteratorYieldsIdsInTraversalOrder(): void
    {
        $ring = FocusRing::of('a', 'b', 'c');

        self::assertSame(['a', 'b', 'c'], iterator_to_array($ring));
    }

    public function testForeachOverRingWalksEveryRegionIncludingDisabled(): void
    {
        // getIterator yields the full traversal order — disabled regions are not
        // filtered out (that is enabledIds()'s job).
        $ring = FocusRing::of('a', 'b', 'c')->disable('b');

        $collected = [];
        foreach ($ring as $id) {
            $collected[] = $id;
        }

        self::assertSame(['a', 'b', 'c'], $collected);
    }

    public function testGetIteratorOnEmptyRingYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array(FocusRing::new()));
    }

    // ─── jsonSerialize() ───────────────────────────────────────────────────

    public function testJsonSerializeCapturesIdsIndexAndDisabled(): void
    {
        $ring = FocusRing::of('a', 'b', 'c')->focus('b')->disable('c');

        self::assertSame(
            ['ids' => ['a', 'b', 'c'], 'index' => 1, 'disabled' => ['c']],
            $ring->jsonSerialize(),
        );
    }

    public function testJsonSerializeOnEmptyRing(): void
    {
        self::assertSame(
            ['ids' => [], 'index' => -1, 'disabled' => []],
            FocusRing::new()->jsonSerialize(),
        );
    }

    public function testJsonEncodeProducesExpectedShape(): void
    {
        $ring = FocusRing::of('a', 'b')->disable('b');

        self::assertSame(
            '{"ids":["a","b"],"index":0,"disabled":["b"]}',
            json_encode($ring, JSON_THROW_ON_ERROR),
        );
    }

    // ─── PERF refactor: incremental enabledPositions maintenance ────────────

    /**
     * Load-bearing regression for the incremental-maintenance refactor: pin the
     * exact current() sequence over a scenario that mixes next()/previous() with
     * register/unregister/disable/enable. The sequence and enabled-skip semantics
     * must stay byte-identical to the O(n)-rebuild implementation — reverting the
     * incremental maintenance to a wrong cache diverges this sequence (or trips
     * the reflection invariant below).
     */
    public function testTraversalParityAcrossMutationScenario(): void
    {
        $ring = FocusRing::of('a', 'b', 'c', 'd', 'e'); // index 0 -> 'a'
        self::assertCacheConsistent($ring);

        $steps = [
            fn (FocusRing $r) => $r->disable('c'),   // 'a'
            fn (FocusRing $r) => $r->next(),         // 'b'
            fn (FocusRing $r) => $r->next(),         // 'd' (skips disabled 'c')
            fn (FocusRing $r) => $r->next(),         // 'e'
            fn (FocusRing $r) => $r->next(),         // 'a' (wrap)
            fn (FocusRing $r) => $r->previous(),     // 'e' (wrap back, skips 'c')
            fn (FocusRing $r) => $r->enable('c'),    // 'e'
            fn (FocusRing $r) => $r->next(),         // 'a' (wrap)
            fn (FocusRing $r) => $r->next(),         // 'b'
            fn (FocusRing $r) => $r->next(),         // 'c' (re-enabled)
            fn (FocusRing $r) => $r->unregister('a'), // 'c' (positions shift left)
            fn (FocusRing $r) => $r->next(),         // 'd'
            fn (FocusRing $r) => $r->disable('e'),   // 'd'
            fn (FocusRing $r) => $r->next(),         // 'b' (wrap, skips 'e')
            fn (FocusRing $r) => $r->previous(),     // 'd' (wrap back, skips 'e')
            fn (FocusRing $r) => $r->register('f'),  // 'd' (append enabled)
            fn (FocusRing $r) => $r->next(),         // 'f' (skips disabled 'e')
            fn (FocusRing $r) => $r->next(),         // 'b' (wrap)
        ];

        $expected = ['a', 'b', 'd', 'e', 'a', 'e', 'e', 'a', 'b', 'c', 'c', 'd', 'd', 'b', 'd', 'd', 'f', 'b'];

        foreach ($steps as $i => $step) {
            $ring = $step($ring);
            self::assertSame($expected[$i], $ring->current(), "step {$i} focus mismatch");
            // Invariant: the maintained cache equals a freshly-computed one.
            self::assertCacheConsistent($ring);
        }
    }

    public function testEnabledPositionsCacheMatchesRebuildAfterEnableDisableChurn(): void
    {
        $ring = FocusRing::of('a', 'b', 'c', 'd');
        self::assertCacheConsistent($ring);

        foreach (['b', 'd', 'a', 'c'] as $id) {
            $ring = $ring->disable($id);
            self::assertCacheConsistent($ring);
        }
        foreach (['c', 'a', 'd', 'b'] as $id) {
            $ring = $ring->enable($id);
            self::assertCacheConsistent($ring);
        }
    }

    /**
     * Read the private, incrementally-maintained enabledPositions and assert it
     * equals a list freshly computed from the ring's public state. This is the
     * white-box half of the parity guard: it holds regardless of whether runtime
     * assertions are enabled (zend.assertions may be -1 under phpunit).
     */
    private static function assertCacheConsistent(FocusRing $ring): void
    {
        $ref = new \ReflectionProperty(FocusRing::class, 'enabledPositions');
        /** @var list<int> $actual */
        $actual = $ref->getValue($ring);

        $disabled = array_fill_keys($ring->disabledIds(), true);
        $expected = [];
        foreach ($ring->ids() as $i => $id) {
            if (!isset($disabled[$id])) {
                $expected[] = $i;
            }
        }

        self::assertSame(
            $expected,
            $actual,
            'maintained enabledPositions must equal a freshly-computed list',
        );
    }
}
