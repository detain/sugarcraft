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

        self::assertSame(['a', 'b', 'c'], $ring->ids(), 'original ring is unchanged');
        self::assertSame('a', $ring->current());
    }
}
