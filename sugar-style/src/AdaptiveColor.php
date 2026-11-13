<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\Color;

/**
 * A pair of colours that resolve to one based on the active terminal
 * background. Mirrors lipgloss v2's `AdaptiveColor`.
 *
 * Use with {@see Style::foregroundAdaptive()} /
 * {@see Style::backgroundAdaptive()}, or build once and assign through
 * {@see Style::withAdaptiveForeground()} /
 * {@see Style::withAdaptiveBackground()}, and collapse before render via
 * {@see Style::resolveAdaptive()} — render never picks on its own: the
 * dark-vs-light answer is ambient, and rendering with an uncollapsed
 * pair throws.
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
     * value of {@see \SugarCraft\Core\Msg\BackgroundColorMsg::isDark()}
     * (or any other dark-background detector).
     */
    public function pick(bool $isDark): Color
    {
        return $isDark ? $this->dark : $this->light;
    }
}
