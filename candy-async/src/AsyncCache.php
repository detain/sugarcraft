<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Promise-library-only async cache-aside primitive: TTL freshness,
 * concurrent-loader dedupe, optional serve-stale-on-error.
 *
 * Extracted from the candy-query AdminQueryCache pattern. What generalises is
 * the entry machinery; the SQL-string keying, pending-drain queue, and
 * connection/ServerContext memo slots stay app-side. AdminQueryCache needed a
 * pending set + external drain only because its render path could not hang
 * that policy on a promise combinator — here the synchronous render path is
 * peek()/isFresh() (never blocking, stale served while a refresh runs) and
 * the loading path is fetch().
 *
 * Ordering law carried over from candy-query's hard-won live refresh (the
 * stale-wins family): a load that was superseded by invalidate()/clear() while
 * in flight still resolves its own callers — the data it fetched was true at
 * request time — but is never adopted into the store, because the
 * invalidation is the newer truth.
 *
 * No timers are armed here — freshness is judged against microtime() at read
 * time, so this class holds the event loop for exactly as long as the loader's
 * own promise does. To bound a hung loader, wrap the loader's promise with
 * {@see AsyncOps::withDeadline()} — bounded I/O ceiling semantics per the
 * E646 law: pass the remaining budget, never a blanket per-request
 * wall-clock killer.
 *
 * Options are immutable in house style: withTtl()/withServeStaleOnError()
 * return NEW handles viewing the SAME store — configure-by-clone never forks
 * the cache and never strands an in-flight load.
 */
final class AsyncCache
{
    private function __construct(
        private readonly float $ttlSeconds,
        private readonly bool $serveStaleOnError,
        private readonly CacheStore $store,
    ) {
    }

    /**
     * @param float $ttlSeconds        Seconds an entry stays fresh (must be > 0)
     * @param bool $serveStaleOnError  Resolve failed loads with the last known value when one exists
     */
    public static function new(float $ttlSeconds = 60.0, bool $serveStaleOnError = false): self
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL seconds must be positive');
        }

        return new self($ttlSeconds, $serveStaleOnError, new CacheStore());
    }

    /**
     * Freshness window for cache hits (seconds).
     */
    public function ttlSeconds(): float
    {
        return $this->ttlSeconds;
    }

    public function servesStaleOnError(): bool
    {
        return $this->serveStaleOnError;
    }

    /**
     * @param float $ttlSeconds  Must be > 0
     * @return self  New handle over the same store
     */
    public function withTtl(float $ttlSeconds): self
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL seconds must be positive');
        }

        return new self($ttlSeconds, $this->serveStaleOnError, $this->store);
    }

    /**
     * @param bool $serveStaleOnError  Failed loads resolve with the stale entry when true
     * @return self  New handle over the same store
     */
    public function withServeStaleOnError(bool $serveStaleOnError): self
    {
        return new self($this->ttlSeconds, $serveStaleOnError, $this->store);
    }

    /**
     * Cache-aside load: answer from a fresh entry, join the in-flight load,
     * or start one.
     *
     * @param string $key  Cache key (must not be empty)
     * @param callable(): PromiseInterface<mixed> $loader  Starts the backing load;
     *        invoked only when no fresh entry and no in-flight load exist. A
     *        synchronous throw counts as a failed load, same path as a rejection.
     * @return PromiseInterface<mixed>
     */
    public function fetch(string $key, callable $loader): PromiseInterface
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty');
        }

        if ($this->isFresh($key)) {
            return resolve($this->store->values[$key]);
        }

        $inFlight = $this->store->flights->find($key);
        if ($inFlight !== null) {
            return $inFlight;
        }

        // The slot is occupied before the loader runs so a synchronously
        // settling loader (react/promise settles an already-resolved inner
        // inside then()) still coalesces — the phlix in-flight ordering law.
        $deferred = new Deferred();
        $flight = $deferred->promise();
        $this->store->flights->begin($key, $flight);

        try {
            $loader()->then(
                function (mixed $value) use ($key, $flight, $deferred): void {
                    $this->adopt($key, $flight, $deferred, $value);
                },
                function (\Throwable $error) use ($key, $flight, $deferred): void {
                    $this->fail($key, $flight, $deferred, $error);
                },
            );
        } catch (\Throwable $error) {
            $this->fail($key, $flight, $deferred, $error);
        }

        return $flight;
    }

    /**
     * Last stored value for a key, fresh or stale — the render-path read.
     * Null covers both "never loaded" and "loaded as null"; has() tells them
     * apart.
     */
    public function peek(string $key): mixed
    {
        return $this->store->values[$key] ?? null;
    }

    /** True when a value (fresh or stale) is held for the key. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store->values);
    }

    /** True when a value is held and its TTL has not elapsed. */
    public function isFresh(string $key): bool
    {
        if (!array_key_exists($key, $this->store->storedAt)) {
            return false;
        }

        return (microtime(true) - $this->store->storedAt[$key]) < $this->ttlSeconds;
    }

    /**
     * Drop a key and supersede any in-flight load: the next fetch starts
     * fresh, and the displaced arrival resolves its own callers without
     * adopting into the store.
     */
    public function invalidate(string $key): void
    {
        unset($this->store->values[$key], $this->store->storedAt[$key]);
        $this->store->flights->forget($key);
    }

    /** Drop every entry and supersede every in-flight load. */
    public function clear(): void
    {
        $this->store->values = [];
        $this->store->storedAt = [];
        $this->store->flights->forgetAll();
    }

    private function adopt(string $key, PromiseInterface $flight, Deferred $deferred, mixed $value): void
    {
        // The identity-guarded release doubles as the adoption gate: a flight
        // displaced mid-load by invalidate()/clear() resolved its callers but
        // never writes into the store — the invalidation was the newer truth.
        if ($this->store->flights->end($key, $flight) === true) {
            $this->store->values[$key] = $value;
            $this->store->storedAt[$key] = microtime(true);
        }

        $deferred->resolve($value);
    }

    private function fail(string $key, PromiseInterface $flight, Deferred $deferred, \Throwable $error): void
    {
        $this->store->flights->end($key, $flight);

        if ($this->serveStaleOnError === true && array_key_exists($key, $this->store->values)) {
            // Serve the last known value, storedAt deliberately untouched: the
            // entry stays stale, so the NEXT fetch retries — a failed refresh
            // can never latch the key behind a frozen "fresh-looking" value.
            $deferred->resolve($this->store->values[$key]);
            return;
        }

        $deferred->reject($error);
    }
}
