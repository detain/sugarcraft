<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;

/**
 * Combines {@see AdaptiveColor} (light vs dark background) with
 * {@see CompleteColor} (per-tier override) — a designer-supplied
 * matrix of (background × profile) → Color. Mirrors lipgloss v2's
 * `CompleteAdaptiveColor`.
 *
 * Use when you want both axes of control: pick the dark-background
 * triple if the terminal background is dark, then pick the right
 * tier within that triple based on the active colour profile.
 */
final class CompleteAdaptiveColor
{
    public function __construct(
        public readonly CompleteColor $light,
        public readonly CompleteColor $dark,
    ) {}

    public function pick(bool $isDark, ColorProfile $profile): Color
    {
        return ($isDark ? $this->dark : $this->light)->pick($profile);
    }
}
