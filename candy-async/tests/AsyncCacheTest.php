<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncCache;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Guards the cache-aside machinery extracted from candy-query's
 * AdminQueryCache (port Q6): fresh hits skip the loader, concurrent fetches
 * coalesce onto ONE load, TTL expiry re-fetches while the render path keeps
 * serving the stale value, failed loads optionally fall back to stale WITHOUT
 * re-stamping (a failure must not latch the key), and a load superseded by
 * invalidate()/clear() answers its own callers but is never adopted.
 *
 * No event loop anywhere: AsyncCache arms no timers, so every pin is
 * observable in one synchronous pass (plus micro-sleeps past a 20ms TTL).
 *
 * @covers \SugarCraft\Async\AsyncCache
 */
final class AsyncCacheTest extends TestCase
{
    public function testFreshHitNeverCallsTheLoader(): void
    {
        $cache = AsyncCache::new();

        $value = null;
        $cache->fetch('k', static fn (): PromiseInterface => resolve('v1'))
            ->then(static function ($v) use (&$value): void {
                $value = $v;
            });
        $this->assertSame('v1', $value);

        $second = null;
        $cache->fetch('k', function (): PromiseInterface {
            $this->fail('fresh entry must short-circuit before the loader');
        })->then(static function ($v) use (&$second): void {
            $second = $v;
        });
        $this->assertSame('v1', $second);
    }

    public function testConcurrentFetchesDedupeToOneLoad(): void
    {
        $cache = AsyncCache::new();
        $inFlight = new Deferred();
        $calls = 0;
        $loader = static function () use (&$calls, $inFlight): PromiseInterface {
            $calls++;
            return $inFlight->promise();
        };

        $first = $cache->fetch('k', $loader);
        $second = $cache->fetch('k', $loader);

        $this->assertSame($first, $second, 'joiners receive the identical in-flight promise');
        $this->assertSame(1, $calls);

        $seen = [];
        $first->then(static function ($v) use (&$seen): void {
            $seen[] = $v;
        });
        $second->then(static function ($v) use (&$seen): void {
            $seen[] = $v;
        });
        $inFlight->resolve('rows');

        $this->assertSame(['rows', 'rows'], $seen);
        $this->assertSame('rows', $cache->peek('k'), 'the settled load populates the store');
    }

    public function testSynchronouslySettlingLoaderStillCoalescesWithinItsPass(): void
    {
        // react/promise v3 settles an already-resolved loader synchronously
        // inside then(): the slot is occupied before the loader runs, so the
        // adoption ordering holds with zero loop turns.
        $cache = AsyncCache::new();
        $calls = 0;
        $loader = static function () use (&$calls): PromiseInterface {
            $calls++;
            return resolve('v');
        };

        $cache->fetch('k', $loader)->then(static fn () => null);
        $cache->fetch('k', $loader)->then(static fn () => null);

        $this->assertSame(1, $calls);
    }

    public function testStaleEntryRefetchesWhilePeekKeepsServingTheOldValue(): void
    {
        $cache = AsyncCache::new(ttlSeconds: 0.02);
        $cache->fetch('k', static fn (): PromiseInterface => resolve('v1'))->then(static fn () => null);

        usleep(30_000); // past the 20ms TTL

        $this->assertFalse($cache->isFresh('k'));

        $refresh = new Deferred();
        $cache->fetch('k', static fn (): PromiseInterface => $refresh->promise())->then(static fn () => null);
        $this->assertSame('v1', $cache->peek('k'), 'render path keeps the last known value while the refresh is in flight');

        $refresh->resolve('v2');
        $this->assertSame('v2', $cache->peek('k'));
        $this->assertTrue($cache->isFresh('k'));
    }

    public function testFailedLoadRejectsWhenStaleFallbackIsDisabled(): void
    {
        $cache = AsyncCache::new();

        $error = null;
        $cache->fetch('k', static fn (): PromiseInterface => reject(new \RuntimeException('upstream down')))
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('upstream down', $error->getMessage());
        $this->assertFalse($cache->has('k'));
    }

    public function testFailedLoadServesStaleWithoutRelatchingTheKey(): void
    {
        // E718 ordering law: the stale serve must NOT re-stamp storedAt, or a
        // failing upstream would freeze a "fresh-looking" entry and the poll
        // would stop retrying until the TTL accidentally elapsed.
        $cache = AsyncCache::new(ttlSeconds: 0.02, serveStaleOnError: true);
        $cache->fetch('k', static fn (): PromiseInterface => resolve('v1'))->then(static fn () => null);

        usleep(30_000);

        $value = null;
        $cache->fetch('k', static fn (): PromiseInterface => reject(new \RuntimeException('blip')))
            ->then(static function ($v) use (&$value): void {
                $value = $v;
            });
        $this->assertSame('v1', $value, 'failed refresh falls back to the last known value');
        $this->assertFalse($cache->isFresh('k'), 'the fallback must not pretend the entry is fresh');

        $retried = null;
        $cache->fetch('k', static fn (): PromiseInterface => resolve('v2'))
            ->then(static function ($v) use (&$retried): void {
                $retried = $v;
            });
        $this->assertSame('v2', $retried, 'the very next fetch retries — a failure cannot latch the key');
    }

    public function testStaleFallbackWithoutAnyEntryStillRejects(): void
    {
        $cache = AsyncCache::new(serveStaleOnError: true);

        $error = null;
        $cache->fetch('k', static fn (): PromiseInterface => reject(new \RuntimeException('cold and broken')))
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('cold and broken', $error->getMessage());
    }

    public function testSynchronousLoaderThrowCountsAsFailedLoadAndFreesSlot(): void
    {
        $cache = AsyncCache::new();
        $calls = 0;
        $loader = static function () use (&$calls): PromiseInterface {
            $calls++;
            throw new \LogicException('loader exploded before returning');
        };

        $error = null;
        $cache->fetch('k', $loader)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(\LogicException::class, $error);

        $retryCalls = 0;
        $value = null;
        $cache->fetch('k', static function () use (&$retryCalls): PromiseInterface {
            $retryCalls++;
            return resolve('recovered');
        })->then(static function ($v) use (&$value): void {
            $value = $v;
        });
        $this->assertSame('recovered', $value, 'the failed flight must leave no stuck slot behind');
        $this->assertSame(1, $retryCalls, 'the retry genuinely invoked a fresh loader');
        $this->assertSame(1, $calls, 'the throwing loader ran exactly once');
    }

    public function testInvalidateDuringFlightResolvesCallerButAdoptsNothing(): void
    {
        $cache = AsyncCache::new();
        $first = new Deferred();
        $second = new Deferred();
        $calls = 0;
        $loader = static function () use (&$calls, $first, $second): PromiseInterface {
            $calls++;
            return $calls === 1 ? $first->promise() : $second->promise();
        };

        $displacedAnswer = null;
        $displacedFlight = $cache->fetch('k', $loader);
        $displacedFlight->then(static function ($v) use (&$displacedAnswer): void {
            $displacedAnswer = $v;
        });

        $cache->invalidate('k'); // supersede the in-flight load

        $freshAnswer = null;
        $freshFlight = $cache->fetch('k', $loader);
        $freshFlight->then(static function ($v) use (&$freshAnswer): void {
            $freshAnswer = $v;
        });
        $this->assertSame(2, $calls, 'invalidate must not let a doomed flight keep deduplicating the key');
        $this->assertNotSame($displacedFlight, $freshFlight);

        $first->resolve('pre-invalidation truth');
        $this->assertSame('pre-invalidation truth', $displacedAnswer, 'a displaced arrival still answers its own caller');
        $this->assertNull($cache->peek('k'), 'but the invalidated store adopts nothing');

        // Key-blind release would free the CURRENT flight's slot on the
        // obsolete arrival's tail, letting the next caller fork a third load.
        $joiner = $cache->fetch('k', $loader);
        $this->assertSame($freshFlight, $joiner, 'the live flight keeps deduplicating');
        $this->assertSame(2, $calls);

        $second->resolve('fresh');
        $this->assertSame('fresh', $freshAnswer);
        $this->assertSame('fresh', $cache->peek('k'));
    }

    public function testClearDuringFlightAdoptsNothingButAnswersCaller(): void
    {
        $cache = AsyncCache::new();
        $inFlight = new Deferred();

        $answer = null;
        $cache->fetch('k', static fn (): PromiseInterface => $inFlight->promise())
            ->then(static function ($v) use (&$answer): void {
                $answer = $v;
            });

        $cache->clear();
        $inFlight->resolve('v');

        $this->assertSame('v', $answer);
        $this->assertFalse($cache->has('k'), 'a displaced arrival must not repopulate a cleared cache');
    }

    public function testWithTtlReturnsNewHandleViewingTheSameStore(): void
    {
        $wide = AsyncCache::new(ttlSeconds: 60.0);
        $wide->fetch('k', static fn (): PromiseInterface => resolve('v'))->then(static fn () => null);

        $tight = $wide->withTtl(5.0);

        $this->assertNotSame($wide, $tight, 'house law: with* returns a new instance');
        $this->assertSame(60.0, $wide->ttlSeconds());
        $this->assertSame(5.0, $tight->ttlSeconds());
        $this->assertSame('v', $tight->peek('k'), 'the clone views the SAME data');
        $this->assertTrue($tight->isFresh('k'));

        // A fresh hit on the clone must not fork a loader either.
        $tight->fetch('k', function (): PromiseInterface {
            $this->fail('shared store means the clone hits the same fresh entry');
        })->then(static fn () => null);

        $tight->invalidate('k');
        $this->assertFalse($wide->has('k'), 'invalidation through one handle is visible through the other');
    }

    public function testWithServeStaleOnErrorFlipsTheFlagImmutably(): void
    {
        $strict = AsyncCache::new();
        $lenient = $strict->withServeStaleOnError(true);

        $this->assertNotSame($strict, $lenient);
        $this->assertFalse($strict->servesStaleOnError());
        $this->assertTrue($lenient->servesStaleOnError());
        $this->assertSame(60.0, $lenient->ttlSeconds(), 'the untouched option carries over');
    }

    public function testNewRejectsNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AsyncCache::new(ttlSeconds: 0.0);
    }

    public function testWithTtlRejectsNonPositiveTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AsyncCache::new()->withTtl(-1.0);
    }

    public function testFetchRejectsEmptyKey(): void
    {
        $cache = AsyncCache::new();

        $this->expectException(\InvalidArgumentException::class);
        $cache->fetch('', static fn (): PromiseInterface => resolve('x'));
    }

    public function testNullValueIsCacheableAndDisambiguatedByHas(): void
    {
        $cache = AsyncCache::new();

        $called = false;
        $cache->fetch('k', static function () use (&$called): PromiseInterface {
            $called = true;
            return resolve(null);
        })->then(static fn () => null);

        $this->assertTrue($called);
        $this->assertTrue($cache->has('k'), 'a cached null is present, not absent');
        $this->assertNull($cache->peek('k'));
        $this->assertTrue($cache->isFresh('k'));

        $cache->fetch('k', static function () use (&$called): PromiseInterface {
            $called = false;
            return resolve('would overwrite');
        })->then(static fn () => null);
        $this->assertTrue($called, 'fresh null hit must short-circuit the loader');
        $this->assertNull($cache->peek('k'));
    }
}
