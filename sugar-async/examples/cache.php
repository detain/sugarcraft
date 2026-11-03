<?php

declare(strict_types=1);

/**
 * AsyncCache: cache-aside with coalescing, a monotonic clock seam (no sleeps),
 * serve-stale-on-error, and the bounded entry cap.
 */

namespace SugarCraft\Async\Examples;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncCache;

use function React\Promise\reject;
use function React\Promise\resolve;

require __DIR__ . '/../vendor/autoload.php';

$now = 1000.0;
$clock = static function () use (&$now): float {
    return $now;
};

$cache = AsyncCache::new(ttlSeconds: 10.0, serveStaleOnError: true, clock: $clock, maxEntries: 2);

$runs = 0;
$loader = static function () use (&$runs): PromiseInterface {
    $runs++;
    return resolve('value-' . $runs);
};

$cache->fetch('a', $loader)->then(static function (mixed $v): void {
    print("first load: $v\n");
});
$cache->fetch('a', $loader)->then(static function (mixed $v): void {
    print("fresh hit:  $v\n");
});

// Coalescing: two concurrent calls on a cold key share ONE loader run.
$inflight = new Deferred();
$once = 0;
$coalesce = static function () use (&$once, $inflight): PromiseInterface {
    $once++;
    return $inflight->promise();
};
$cache->fetch('b', $coalesce);
$cache->fetch('b', $coalesce);
$inflight->resolve('shared');
echo "coalesced loader runs: $once (expected 1)\n";

// Stale serving: advance past the TTL, fail the next load, get the last value.
$now += 20.0;
$cache->fetch('a', static fn (): PromiseInterface => reject(new \RuntimeException('blip')))
    ->then(static function (mixed $v): void {
        print("stale on error: $v\n");
    });

echo 'isLoaded(a)=', var_export($cache->isLoaded('a'), true),
    ' count=', $cache->count(),
    ' age(a)=', $cache->ageSeconds('a'), "\n";

// Bounded: adopting a third key evicts the least-recently-stored one.
$cache->fetch('c', static fn (): PromiseInterface => resolve('value-c'))
    ->then(static function () use ($cache): void {
        echo 'after cap, keys: ', implode(',', $cache->keys()), "\n";
    });
