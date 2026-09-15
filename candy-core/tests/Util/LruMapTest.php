<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\LruMap;

/**
 * The contract that matters for an LRU is which entry dies and when, so the
 * eviction tests below pin exact orders rather than only sizes — a cache that
 * evicts the wrong victim is silently slower, never wrong-shaped.
 */
final class LruMapTest extends TestCase
{
    private string $locale = 'en';

    protected function setUp(): void
    {
        // The exception-message assertions are written in English. Pin the
        // locale so a test that runs before this one and leaves it flipped
        // cannot redden them, and so they stay honest once candy-core's keys
        // are mirrored into the translated locale files.
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    public function testNewAcceptsACapacityOfOne(): void
    {
        $map = LruMap::new(1);

        self::assertSame(1, $map->capacity());
        self::assertNull($map->maxCapacity());
        self::assertCount(0, $map);
    }

    public function testNewRejectsZeroCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LRU capacity must be at least 1, got 0');

        LruMap::new(0);
    }

    public function testNewRejectsNegativeCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LRU capacity must be at least 1, got -5');

        LruMap::new(-5);
    }

    public function testNewRejectsACapacityAboveItsGrowthGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LRU capacity 10 exceeds growth guard 4');

        LruMap::new(10, 4);
    }

    public function testNewAcceptsACapacityExactlyAtTheGuard(): void
    {
        $map = LruMap::new(4, 4);

        self::assertSame(4, $map->capacity());
        self::assertSame(4, $map->maxCapacity());
    }

    public function testPutGetAndHasRoundTrip(): void
    {
        $map = LruMap::new(2);

        $map->put('alpha', 'first');
        $map->put('beta', 'second');

        self::assertSame('first', $map->get('alpha'));
        self::assertSame('second', $map->get('beta'));
        self::assertTrue($map->has('alpha'));
        self::assertFalse($map->has('gamma'));
        self::assertNull($map->get('gamma'));
        self::assertCount(2, $map);
        self::assertSame(2, $map->count());
    }

    public function testStoredNullIsKeptAndDistinguishedFromAMiss(): void
    {
        $map = LruMap::new(2);
        $map->put('known-missing', null);

        self::assertTrue($map->has('known-missing'), 'a cached miss must stay cached');
        self::assertNull($map->get('known-missing'));
        self::assertNull($map->peek('absent'));
        self::assertSame(1, $map->count());
    }

    public function testPutOverwritesWithoutGrowingOrEvicting(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('a', 11);

        self::assertCount(2, $map);
        self::assertSame(11, $map->get('a'));
        self::assertSame(0, $map->evictions(), 'an overwrite is not an eviction');
    }

    public function testReStoringAnExistingKeyDoesNotEvictWhenFull(): void
    {
        $map = LruMap::new(1);
        $map->put('a', 1);
        $map->put('a', 2);

        self::assertSame(0, $map->evictions());
        self::assertSame(['a'], $map->keys());
    }

    public function testPuttingPastCapacityEvictsTheLeastRecentlyUsed(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        self::assertFalse($map->has('a'), 'a was never touched after insertion, so it dies first');
        self::assertTrue($map->has('b'));
        self::assertTrue($map->has('c'));
        self::assertSame(1, $map->evictions());
        self::assertCount(2, $map);
    }

    public function testGetPromotesAnEntryAheadOfEviction(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        self::assertSame(1, $map->get('a'));
        $map->put('c', 3);

        self::assertTrue($map->has('a'), 'reading a made b the victim instead');
        self::assertFalse($map->has('b'));
        self::assertSame(['c', 'a'], $map->keys());
    }

    public function testEvictionOrderIsDeterministicAcrossAReadWriteMix(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        $map->get('b');                    // internal order a, c, b
        $map->put('d', 4);                 // evicts a -> c, b, d

        self::assertSame(['d', 'b', 'c'], $map->keys(), 'most-recently-used first');
        self::assertSame(1, $map->evictions());

        $map->put('c', 9);                 // re-store refreshes -> b, d, c
        self::assertSame(['c', 'd', 'b'], $map->keys());
        self::assertSame(1, $map->evictions());

        $map->put('e', 5);                 // evicts b -> d, c, e
        self::assertSame(['e', 'c', 'd'], $map->keys());
        self::assertSame(2, $map->evictions());
        self::assertFalse($map->has('b'));
    }

    public function testCapacityOfOneKeepsOnlyTheNewestEntry(): void
    {
        $map = LruMap::new(1);

        foreach (['a', 'b', 'c'] as $key) {
            $map->put($key, $key);
        }

        self::assertSame(['c'], $map->keys());
        self::assertSame(2, $map->evictions());
        self::assertCount(1, $map);
    }

    public function testPeekReadsWithoutPromoting(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        self::assertSame(1, $map->peek('a'));
        $map->put('c', 3);

        self::assertFalse($map->has('a'), 'peek() must not resurrect an entry the caller goes on to discard');
        self::assertSame(['c', 'b'], $map->keys());
    }

    public function testHasReportsPresenceWithoutPromoting(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        // The documented contract: has() is a presence check, not a use. If it
        // ever starts promoting, 'a' would survive the put below and this dies.
        self::assertTrue($map->has('a'));
        $map->put('c', 3);

        self::assertFalse($map->has('a'), 'a mere existence probe must not rescue the LRU victim');
        self::assertSame(['c', 'b'], $map->keys());
        self::assertSame(1, $map->evictions());
    }

    public function testTouchPromotesAnExistingEntry(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        self::assertTrue($map->touch('a'));
        self::assertSame(1, $map->peek('a'));
        $map->put('c', 3);

        self::assertFalse($map->has('b'));
    }

    public function testTouchOfAnAbsentKeyDoesNotConjureAnEntry(): void
    {
        $map = LruMap::new(2);

        self::assertFalse($map->touch('ghost'));
        self::assertCount(0, $map);
        self::assertSame([], $map->keys());
    }

    public function testRemoveReportsWhetherItDroppedSomething(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);

        self::assertTrue($map->remove('a'));
        self::assertFalse($map->remove('a'));
        self::assertCount(0, $map);
        self::assertSame(0, $map->evictions(), 'a deliberate remove is not a capacity eviction');
    }

    public function testRemoveFreesASlotWithoutDisturbingOrderOfTheRest(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        $map->remove('b');
        $map->put('d', 4);
        self::assertSame(0, $map->evictions(), 'd filled the slot b freed');

        $map->put('e', 5);
        self::assertSame(['e', 'd', 'c'], $map->keys());
        self::assertSame(1, $map->evictions(), 'only once the freed slot was gone did a die');
    }

    public function testClearEmptiesTheMapButKeepsItsConfiguration(): void
    {
        $map = LruMap::new(2, 4);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        $map->clear();

        self::assertCount(0, $map);
        self::assertSame([], $map->keys());
        self::assertSame(2, $map->capacity());
        self::assertSame(4, $map->maxCapacity());
        self::assertSame(1, $map->evictions(), 'the counter reports lifetime pressure, not current contents');
    }

    public function testEntriesAndIterationWalkMostRecentlyUsedFirst(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);
        $map->get('a');

        self::assertSame(['a' => 1, 'c' => 3, 'b' => 2], $map->entries());

        $walked = [];
        foreach ($map as $key => $value) {
            $walked[$key] = $value;
        }

        self::assertSame(['a', 'c', 'b'], array_keys($walked));
        self::assertSame($map->keys(), array_keys($walked), 'foreach and keys() must agree');
    }

    public function testNumericLookingKeysComeBackAsStringsFromTheKeyList(): void
    {
        // PHP casts an integer-like string key to int inside a hashtable, so the
        // list view has to undo that or a `fn (string $key)` consumer of a
        // strictly-typed lib gets a TypeError on media ids.
        $map = LruMap::new(4);
        $map->put('42', 'poster-a');
        $map->put('0', 'poster-b');

        self::assertSame(['0', '42'], $map->keys());
        foreach ($map->keys() as $key) {
            self::assertIsString($key);
        }

        self::assertSame('poster-a', $map->get('42'), 'lookup by the original string still hits');
        self::assertTrue($map->has('0'));
    }

    public function testKeyedViewsExposePhpNormalisedKeys(): void
    {
        // Documented consequence rather than a bug: entries()/foreach carry
        // whatever the hashtable made of the key, so '42' arrives as int(42).
        $map = LruMap::new(4);
        $map->put('42', 'poster-a');

        self::assertSame([42 => 'poster-a'], $map->entries());
        self::assertSame(['42'], $map->keys());
    }

    public function testTouchingTheMostRecentEntryChangesNothing(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        self::assertTrue($map->touch('c'));

        self::assertSame(['c', 'b', 'a'], $map->keys());
        self::assertSame(0, $map->evictions());

        $map->put('d', 4);
        self::assertFalse($map->has('a'), 'a is still the victim');
    }

    public function testMutatingTheMapDuringIterationIsSafe(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        $walked = [];
        foreach ($map as $key => $value) {
            $walked[] = $key;
            // A consumer dropping a warm entry mid-sweep must not skip or
            // repeat a neighbour: iteration runs over a snapshot.
            $map->remove('b');
            $map->put('late', 9);
        }

        self::assertSame(['c', 'b', 'a'], $walked);
        self::assertSame(['late', 'c', 'a'], $map->keys());
    }

    public function testRemovingTheVictimThenShrinkingShedsFromTheOtherEnd(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);

        $map->remove('a');
        $map->resize(1);

        self::assertSame(['c'], $map->keys());
        self::assertSame(1, $map->evictions(), 'only the shrink evicted');
    }

    public function testNullValuesSurviveEveryReadView(): void
    {
        $map = LruMap::new(3);
        $map->put('a', null);
        $map->put('b', 'x');

        self::assertSame(['b' => 'x', 'a' => null], $map->entries());
        self::assertSame(['b', 'a'], array_keys(iterator_to_array($map)));
        self::assertSame(2, $map->count());
    }

    public function testAnEmptyMapIteratesAsNothing(): void
    {
        $map = LruMap::new(2);

        self::assertSame([], $map->entries());
        self::assertSame([], iterator_to_array($map));
    }

    public function testResizeUpAdmitsMoreEntriesWithoutEvicting(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        $map->resize(4);
        $map->put('c', 3);
        $map->put('d', 4);

        self::assertSame(4, $map->capacity());
        self::assertSame(0, $map->evictions());
        self::assertCount(4, $map);
    }

    public function testResizeDownShedsTheLeastRecentlyUsedFirst(): void
    {
        $map = LruMap::new(4);
        $map->put('a', 1);
        $map->put('b', 2);
        $map->put('c', 3);
        $map->put('d', 4);
        $map->get('a');                    // internal order b, c, d, a

        $map->resize(2);

        self::assertSame(['a', 'd'], $map->keys());
        self::assertSame(2, $map->evictions(), 'sheds are evictions like any others');
        self::assertCount(2, $map);
    }

    public function testResizeRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LRU capacity must be at least 1, got 0');

        LruMap::new(2)->resize(0);
    }

    public function testResizeRejectsGrowingPastTheGuard(): void
    {
        $map = LruMap::new(2, 3);

        $map->resize(3);
        self::assertSame(3, $map->capacity());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('LRU capacity 4 exceeds growth guard 3');

        $map->resize(4);
    }

    public function testAFailedResizeLeavesTheMapUsableAtItsOldCapacity(): void
    {
        $map = LruMap::new(2, 2);
        $map->put('a', 1);
        $map->put('b', 2);

        try {
            $map->resize(3);
            self::fail('expected the growth guard to reject the resize');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame(2, $map->capacity());
        self::assertCount(2, $map);

        $map->put('c', 3);
        self::assertSame(['c', 'b'], $map->keys());
        self::assertSame(1, $map->evictions());
    }

    public function testResizingToTheSameCapacityIsANoOp(): void
    {
        $map = LruMap::new(2);
        $map->put('a', 1);
        $map->put('b', 2);

        $map->resize(2);

        self::assertSame(['b', 'a'], $map->keys());
        self::assertSame(0, $map->evictions());
    }

    public function testCloneYieldsAnIndependentSnapshot(): void
    {
        $map = LruMap::new(3);
        $map->put('a', 1);
        $map->put('b', 2);

        $fork = clone $map;

        // Writing through one must not disturb the other's contents or counters —
        // the difference from cloning a Semaphore, which would be a second budget.
        $map->put('c', 3);
        $fork->remove('a');

        self::assertSame(['c', 'b', 'a'], $map->keys(), 'the original keeps its own writes');
        self::assertSame(['b'], $fork->keys(), 'the clone keeps its own');
        // Pinned as magnitudes, not `assertNotSame` on two small ints: identity of
        // interned integers is an engine detail, and these are the real claim.
        self::assertSame(3, $map->count());
        self::assertSame(1, $fork->count());

        // Object values are still shared references, per PHP clone semantics.
        $obj = new \stdClass();
        $withObj = LruMap::new(2);
        $withObj->put('k', $obj);
        self::assertSame($obj, $withObj->peek('k'));
        self::assertSame($obj, (clone $withObj)->peek('k'));
    }
}
