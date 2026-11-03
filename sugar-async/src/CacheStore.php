<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Shared mutable storage behind every {@see AsyncCache} handle.
 *
 * WHY an object and not plain arrays on AsyncCache: withTtl()/
 * withServeStaleOnError() return new handles viewing the SAME data (house
 * immutable-options law), and a settled loader's result must land in that
 * shared store even when the options were re-cloned mid-flight. Object
 * identity carries the storage across clones; settle handlers capture the
 * store, never the handle.
 *
 * @internal  Library plumbing — not part of the public API.
 */
final class CacheStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, float>  monotonic clock stamp per key, never the value's own time. */
    public array $storedAt = [];

    /**
     * Monotonic-seconds clock shared by every handle on this store, so stamps
     * written through one clone are judged by the same clock through another.
     * hrtime() rather than wall time: a backward NTP step must not be able to
     * resurrect stale entries or freeze freshness.
     */
    public readonly \Closure $clock;

    public InFlightMap $flights;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null
            ? static fn(): float => hrtime(true) / 1e9
            : \Closure::fromCallable($clock);
        $this->flights = new InFlightMap();
    }
}
