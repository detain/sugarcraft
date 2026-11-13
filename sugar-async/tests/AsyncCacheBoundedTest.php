<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncCache;

use function React\Promise\resolve;

/**
 * B7/M15-M17: the entry cap, eviction law, and introspection surface of
 * AsyncCache, driven by a fake monotonic clock so no test sleeps.
 */
final class AsyncCacheBoundedTest extends TestCase
{
    public function test_adopt_beyond_the_cap_evicts_the_least_recently_stored(): void
    {
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 100.0, clock: $clock, maxEntries: 2);

        $this->resolveWith($cache->fetch('a', self::loader('va')), 'va');
        $this->resolveWith($cache->fetch('b', self::loader('vb')), 'vb');
        $clock->advance(5.0);
        $this->resolveWith($cache->fetch('c', self::loader('vc')), 'vc');

        self::assertSame(['b', 'c'], $cache->keys());

        $clock->advance(5.0);
        $this->resolveWith($cache->fetch('d', self::loader('vd')), 'vd');
        self::assertSame(['c', 'd'], $cache->keys());
    }

    public function test_in_flight_loads_never_count_against_the_cap(): void
    {
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 100.0, clock: $clock, maxEntries: 1);

        $first = new Deferred();
        $second = new Deferred();
        $cache->fetch('a', static fn(): PromiseInterface => $first->promise());
        $cache->fetch('b', static fn(): PromiseInterface => $second->promise());

        self::assertSame(0, $cache->count());
        self::assertTrue($cache->isLoaded('a'));
        self::assertTrue($cache->isLoaded('b'));

        $first->resolve('va');
        self::assertSame(['a'], $cache->keys());

        $second->resolve('vb');
        self::assertSame(['b'], $cache->keys());
    }

    public function test_the_cap_rides_clones(): void
    {
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 100.0, clock: $clock, maxEntries: 2);

        $this->resolveWith($cache->fetch('a', self::loader('va')), 'va');
        $clock->advance(5.0);
        $this->resolveWith($cache->fetch('b', self::loader('vb')), 'vb');

        $clone = $cache->withTtl(5.0);
        self::assertSame(2, $clone->maxEntries());

        $clock->advance(5.0);
        $this->resolveWith($clone->fetch('c', self::loader('vc')), 'vc');
        self::assertSame(['b', 'c'], $clone->keys());
    }

    public function test_withMaxEntries_is_immutable_and_guarded(): void
    {
        $cache = AsyncCache::new(ttlSeconds: 10.0);
        self::assertNull($cache->maxEntries());

        $capped = $cache->withMaxEntries(1);
        self::assertSame(1, $capped->maxEntries());
        self::assertNull($cache->maxEntries());

        foreach ([0, -1] as $bad) {
            try {
                $cache->withMaxEntries($bad);
                self::fail('expected a refusal for maxEntries ' . $bad);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Max entries must be >= 1 when set', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max entries must be >= 1 when set');
        AsyncCache::new(ttlSeconds: 10.0, maxEntries: 0);
    }

    public function test_isLoaded_answers_only_for_fresh_or_in_flight_state(): void
    {
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 10.0, clock: $clock);

        self::assertFalse($cache->isLoaded('missing'));

        $this->resolveWith($cache->fetch('fresh', self::loader('v')), 'v');
        self::assertTrue($cache->isLoaded('fresh'));

        $clock->advance(10.0);
        self::assertFalse($cache->isLoaded('fresh'));

        $parked = new Deferred();
        $cache->fetch('loading', static fn(): PromiseInterface => $parked->promise());
        self::assertTrue($cache->isLoaded('loading'));
        $parked->resolve('v');

        $cache->clear();
        self::assertFalse($cache->isLoaded('loading'));
    }

    public function test_ageSeconds_reports_the_monotonic_age_and_never_goes_negative(): void
    {
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 1000.0, clock: $clock);

        self::assertNull($cache->ageSeconds('unknown'));

        $this->resolveWith($cache->fetch('a', self::loader('va')), 'va');
        $clock->advance(0.5);
        self::assertSame(0.5, $cache->ageSeconds('a'));

        $clock->advance(5.0);
        self::assertSame(5.5, $cache->ageSeconds('a'));

        $clock->advance(-100.0);
        self::assertSame(0.0, $cache->ageSeconds('a'));
    }

    public function test_a_loader_that_returns_a_non_promise_is_refused_loudly_and_frees_the_slot(): void
    {
        $cache = AsyncCache::new(ttlSeconds: 10.0);

        $error = null;
        $cache->fetch('k', static fn(): string => 'oops')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertSame('AsyncCache loader must return a promise; string returned.', $error->getMessage());

        $this->resolveWith($cache->fetch('k', self::loader('good')), 'good');
    }

    public function test_shrink_below_size_drains_at_the_setter(): void
    {
        // finding-035: the old evictOverflow() recursed once per excess
        // entry, so a shrink past the cap cost O(entries - cap) stack depth
        // on the next write. Drain loop + setter-time eviction: the shrink
        // itself brings the store to the cap, and a constant one evicts per
        // later write.
        $clock = new FakeClock(1000.0);
        $cache = AsyncCache::new(ttlSeconds: 100.0, clock: $clock, maxEntries: 1000);

        for ($i = 0; $i < 1000; $i++) {
            $clock->advance(0.001);
            $this->resolveWith($cache->fetch("k$i", self::loader("v$i")), "v$i");
        }
        self::assertCount(1000, $cache->keys());

        $shrunk = $cache->withMaxEntries(10);
        self::assertCount(10, $shrunk->keys(), 'the drain lands on the setter, not lazily across later writes');
        // Order/tie logic unchanged: the ten most-recently-STORED survive,
        // in storage order.
        self::assertSame(
            ['k990', 'k991', 'k992', 'k993', 'k994', 'k995', 'k996', 'k997', 'k998', 'k999'],
            $shrunk->keys(),
        );

        $clock->advance(0.001);
        $this->resolveWith($shrunk->fetch('fresh', self::loader('vf')), 'vf');
        self::assertSame(
            ['k991', 'k992', 'k993', 'k994', 'k995', 'k996', 'k997', 'k998', 'k999', 'fresh'],
            $shrunk->keys(),
            'a write after the shrink evicts exactly one, at the cap',
        );
    }

    /** @return callable(): PromiseInterface */
    private static function loader(mixed $value): callable
    {
        return static fn(): PromiseInterface => resolve($value);
    }

    private function resolveWith(PromiseInterface $promise, mixed $expected): void
    {
        $outcome = 'UNSET';
        $promise->then(static function (mixed $value) use (&$outcome): void {
            $outcome = $value;
        });
        self::assertSame($expected, $outcome);
    }
}

final class FakeClock
{
    public function __construct(private float $now = 1000.0)
    {
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
