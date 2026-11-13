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
 * Extracted from the candy-sql AdminQueryCache pattern. What generalizes is
 * the entry machinery; the SQL-string keying, pending-drain queue, and
 * connection/ServerContext memo slots stay app-side. AdminQueryCache needed a
 * pending set + external drain only because its render path could not hang
 * that policy on a promise combinator — here the synchronous render path is
 * peek()/isFresh() (never blocking, stale served while a refresh runs) and
 * the loading path is fetch().
 *
 * Ordering rule carried over from candy-sql's hard-won live refresh (the
 * stale-wins family): a load that was superseded by invalidate()/clear() while
 * in flight still resolves its own callers — the data it fetched was true at
 * request time — but is never adopted into the store, because the
 * invalidation is the newer truth.
 *
 * No timers are armed here — freshness is judged against a monotonic clock at
 * read time, so this class holds the event loop for exactly as long as the loader's
 * own promise does. To bound a hung loader, wrap the loader's promise with
 * {@see AsyncOps::withDeadline()} — bounded I/O ceiling semantics: pass
 * the remaining budget, never a blanket per-request wall-clock killer.
 *
 * Space-bound rule: with maxEntries set, an adopt past the cap evicts the
 * LEAST-recently-stored entry; expired entries are NOT eagerly reaped, so an
 * uncapped cache releases memory only via invalidate()/clear() — the cap
 * bounds values, in-flight loads never count against it.
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
        private readonly ?int $maxEntries = null,
    ) {
    }

    /**
     * @param float $ttlSeconds        Seconds an entry stays fresh (must be > 0)
     * @param bool $serveStaleOnError  Resolve failed loads with the last known value when one exists
     * @param null|callable():float $clock  Monotonic-seconds clock seam (defaults to hrtime()/1e9); tests may drive freshness boundaries without sleeping
     * @param int|null $maxEntries  Optional cap on stored keys (>= 1); an adopt past it evicts the least-recently-stored entry. null (default) keeps the bounded-caller-side rule: memory follows caller key discipline.
     */
    public static function new(float $ttlSeconds = 60.0, bool $serveStaleOnError = false, ?callable $clock = null, ?int $maxEntries = null): self
    {
        if ($ttlSeconds <= 0 || !is_finite($ttlSeconds)) {
            throw new \InvalidArgumentException('TTL seconds must be a positive finite number');
        }
        self::guardMaxEntries($maxEntries);

        return new self($ttlSeconds, $serveStaleOnError, new CacheStore($clock), $maxEntries);
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

    /** The stored-key cap, or null when unbounded (the default). */
    public function maxEntries(): ?int
    {
        return $this->maxEntries;
    }

    /** Number of stored values, fresh or stale. In-flight loads are not stored. */
    public function count(): int
    {
        return count($this->store->values);
    }

    /** Stored keys (fresh or stale) in insertion order. */
    public function keys(): array
    {
        return array_keys($this->store->values);
    }

    /** True when the key is fresh OR a load is in flight — the loading-indicator read. */
    public function isLoaded(string $key): bool
    {
        return $this->isFresh($key) || $this->store->flights->find($key) !== null;
    }

    /**
     * Monotonic age of the stored value, fresh or stale; null when none is
     * held. Reads past the TTL on purpose: stale-serving and age reporting
     * both consult peek semantics, not freshness.
     */
    public function ageSeconds(string $key): ?float
    {
        if (!array_key_exists($key, $this->store->values)) {
            return null;
        }

        return max(0.0, ($this->store->clock)() - $this->store->storedAt[$key]);
    }

    /**
     * @param float $ttlSeconds  Must be > 0
     * @return self  New handle over the same store
     */
    public function withTtl(float $ttlSeconds): self
    {
        if ($ttlSeconds <= 0 || !is_finite($ttlSeconds)) {
            throw new \InvalidArgumentException('TTL seconds must be a positive finite number');
        }

        return new self($ttlSeconds, $this->serveStaleOnError, $this->store, $this->maxEntries);
    }

    /**
     * @param bool $serveStaleOnError  Failed loads resolve with the stale entry when true
     * @return self  New handle over the same store
     */
    public function withServeStaleOnError(bool $serveStaleOnError): self
    {
        return new self($this->ttlSeconds, $serveStaleOnError, $this->store, $this->maxEntries);
    }

    /**
     * @param int|null $maxEntries  Must be >= 1 when set
     * @return self  New handle over the same store
     */
    public function withMaxEntries(?int $maxEntries): self
    {
        self::guardMaxEntries($maxEntries);

        return new self($this->ttlSeconds, $this->serveStaleOnError, $this->store, $maxEntries);
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
        // inside then()) still coalesces — the in-flight ordering rule InFlightMap pins.
        $deferred = new Deferred();
        $flight = $deferred->promise();
        $this->store->flights->begin($key, $flight);

        try {
            $loaderResult = $loader();
            if (!$loaderResult instanceof PromiseInterface) {
                // Fail LOUD and early: a non-promise here used to surface as
                // 'Call to a member function then() on string' several frames
                // deep. The throw rides the catch below, freeing the slot.
                throw new \InvalidArgumentException('AsyncCache loader must return a promise; ' . get_debug_type($loaderResult) . ' returned.');
            }
            $loaderResult->then(
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

        return (($this->store->clock)() - $this->store->storedAt[$key]) < $this->ttlSeconds;
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

    private static function guardMaxEntries(?int $maxEntries): void
    {
        if ($maxEntries !== null && $maxEntries < 1) {
            throw new \InvalidArgumentException('Max entries must be >= 1 when set');
        }
    }

    /**
     * Cap enforcement at adoption time: evict least-recently-stored keys
     * (equal stamps break insertion-first via a strict '<' scan). The
     * just-adopted key carries the newest stamp, so it is never a candidate.
     * Flights are untouched — a coalescing load is not a stored value.
     */
    private function evictOverflow(): void
    {
        if ($this->maxEntries === null || count($this->store->values) <= $this->maxEntries) {
            return;
        }

        $oldest = array_key_first($this->store->values);
        $oldestStamp = $this->store->storedAt[$oldest];
        foreach ($this->store->values as $candidate => $_) {
            if ($this->store->storedAt[$candidate] < $oldestStamp) {
                $oldest = $candidate;
                $oldestStamp = $this->store->storedAt[$candidate];
            }
        }

        unset($this->store->values[$oldest], $this->store->storedAt[$oldest]);

        $this->evictOverflow();
    }

    private function adopt(string $key, PromiseInterface $flight, Deferred $deferred, mixed $value): void
    {
        // The identity-guarded release doubles as the adoption gate: a flight
        // displaced mid-load by invalidate()/clear() resolved its callers but
        // never writes into the store — the invalidation was the newer truth.
        if ($this->store->flights->end($key, $flight) === true) {
            $this->store->values[$key] = $value;
            $this->store->storedAt[$key] = ($this->store->clock)();
            $this->evictOverflow();
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
