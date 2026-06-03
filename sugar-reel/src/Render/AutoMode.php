<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * Marker value for "auto-detect the best rendering mode".
 *
 * Unlike null (which means "keep current" in the Reel.with() fluent API),
 * AutoMode explicitly means "resolve to the best available mode at runtime".
 *
 * Used by Reel::withAutoMode() to set the mode to auto-detect.
 */
final class AutoMode
{
    private function __construct()
    {
    }
}
