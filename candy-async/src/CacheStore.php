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

    /** @var array<string, float>  microtime(true) stamp per key, never the value's own time. */
    public array $storedAt = [];

    public InFlightMap $flights;

    public function __construct()
    {
        $this->flights = new InFlightMap();
    }
}
