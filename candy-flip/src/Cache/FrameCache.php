<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Cache;

use SugarCraft\Flip\Frame;

/**
 * Memoizes rendered frame output so identical Frame instances skip
 * the rendering step on subsequent calls.
 *
 * Uses a {@see WeakMap} keyed by Frame object identity — entries are
 * discarded automatically when the Frame is garbage-collected,
 * avoiding memory pressure from long-running players.
 */
final class FrameCache
{
    /** @var \WeakMap<object, string> */
    private \WeakMap $map;

    public function __construct()
    {
        $this->map = new \WeakMap();
    }

    /**
     * Return the cached rendered output for $frame, or null if not yet cached.
     */
    public function get(Frame $frame): ?string
    {
        if (!$this->map->offsetExists($frame)) {
            return null;
        }
        return $this->map->offsetGet($frame);
    }

    /**
     * Store the rendered output for $frame.
     */
    public function set(Frame $frame, string $rendered): void
    {
        $this->map->offsetSet($frame, $rendered);
    }

    /**
     * True if $frame has a cached render.
     */
    public function has(Frame $frame): bool
    {
        return $this->map->offsetExists($frame);
    }

    /**
     * Invalidate the cache entry for $frame, if present.
     */
    public function delete(Frame $frame): void
    {
        $this->map->offsetUnset($frame);
    }

    /**
     * Clear all cached entries.
     */
    public function clear(): void
    {
        $this->map = new \WeakMap();
    }
}
