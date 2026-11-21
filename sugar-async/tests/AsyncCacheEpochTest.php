<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\AsyncCache;
use function React\Promise\resolve;

final class AsyncCacheEpochTest extends TestCase
{
    public function testAReAdoptedKeyMovesToTheTailOfTheEvictionOrder(): void
    {
        $cache = AsyncCache::new(60.0, false, null, 2);
        $cache->fetch('a', static fn () => resolve(1));
        $cache->fetch('b', static fn () => resolve(2));
        $cache->invalidate('a');
        $cache->fetch('a', static fn () => resolve(3));

        // Regression: eviction used to scan for the oldest stamp; with the O(1)
        // head eviction a re-adopted key must still count as newest.
        $cache->fetch('c', static fn () => resolve(4));

        self::assertSame(['a', 'c'], $cache->keys());
        self::assertFalse($cache->has('b'));
    }

    public function testAShrinkBelowTheSizeEvictsOldestFirst(): void
    {
        $cache = AsyncCache::new(60.0);
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $cache->fetch($key, static fn () => resolve($key));
        }

        $cache->withMaxEntries(2);

        self::assertSame(['c', 'd'], $cache->keys());
    }

    public function testTheGenerationStartsAtZeroAndAdvancesOnInvalidateAndClear(): void
    {
        $cache = AsyncCache::new();
        self::assertSame(0, $cache->generation());

        $cache->fetch('a', static fn () => resolve(1));
        self::assertSame(0, $cache->generation(), 'a load does not advance the epoch');

        $cache->invalidate('a');
        self::assertSame(1, $cache->generation());

        $cache->clear();
        self::assertSame(2, $cache->generation());
    }

    public function testAHandleSeesTheSameGenerationAsItsStore(): void
    {
        $cache = AsyncCache::new();
        $view = $cache->withTtl(30.0);
        $cache->clear();

        self::assertSame(1, $view->generation());
    }
}
