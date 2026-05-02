<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

use CandyCore\Core\Util\Color;

/**
 * A pair of colours that resolve to one based on the active terminal
 * background. Mirrors lipgloss v2's `AdaptiveColor`.
 *
 * Use with {@see Style::foregroundAdaptive()} /
 * {@see Style::backgroundAdaptive()} and resolve at render time via
 * {@see Style::resolveAdaptive()}.
 *
 * The "light" colour is meant for terminals with a *light* background
 * (so it's the darker, contrasting colour); the "dark" colour is for
 * dark backgrounds (so it's the lighter, contrasting colour).
 */
final class AdaptiveColor
{
    public function __construct(
        public readonly Color $light,
        public readonly Color $dark,
    ) {}

    /**
     * Pick the colour appropriate for the current background. Pass the
     * value of {@see \CandyCore\Core\Msg\BackgroundColorMsg::isDark()}
     * (or any other dark-background detector).
     */
    public function pick(bool $isDark): Color
    {
        return $isDark ? $this->dark : $this->light;
    }
}
