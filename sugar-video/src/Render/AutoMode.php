<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

/**
 * Marker value for "auto-detect the best rendering mode".
 *
 * Unlike null (which means "keep current" in the Reel.with() fluent API),
 * AutoMode explicitly means "resolve to the best available mode at runtime".
 *
 * Used by Reel::withAutoMode() to set the mode to auto-detect. The constructor
 * stays private on purpose: the marker exists only as a value the fluent
 * builder can distinguish from "keep current", and {@see new()} is its single
 * sanctioned instantiation point (AGENTS.md factory law — `::new()` default).
 */
final class AutoMode
{
    private function __construct()
    {
    }

    /**
     * The marker instance — the only way to obtain one.
     */
    public static function new(): self
    {
        return new self();
    }
}
