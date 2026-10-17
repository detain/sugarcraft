<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Core\Util\ColorProfile;

/**
 * Per-writer rendering context. Mirrors lipgloss's `Renderer` —
 * a small value object that bundles a {@see ColorProfile} and a
 * "dark background" flag so callers can branch on those without
 * threading them through every {@see Style} construction.
 *
 * Lipgloss exposes `lipgloss.NewRenderer(out)` to associate a
 * rendering context with an output writer. PHP's stream model is
 * coarser, so this implementation is a pure value object — no
 * embedded writer. Pair with {@see Output::fprint()} when you need
 * to write to a specific stream.
 *
 * ```php
 * $r = Renderer::new()
 *     ->withColorProfile(ColorProfile::Ansi256)
 *     ->withHasDarkBackground(true);
 *
 * // Hand a Style the renderer's profile in one call.
 * $bold = $r->newStyle()->bold()->render('hi');
 *
 * // Branch a colour pick on dark background:
 * $col  = $r->lightDark()->pick($lightCol, $darkCol);
 * ```
 */
final class Renderer
{
    private function __construct(
        public readonly ColorProfile $colorProfile,
        public readonly bool $hasDarkBackground,
    ) {}

    /**
     * Default renderer: TrueColor profile, dark background.
     * Convenience for the common case (most modern terminals).
     */
    public static function new(): self
    {
        return new self(ColorProfile::TrueColor, true);
    }

    /**
     * Auto-detect the color profile from the environment via
     * {@see ColorProfile::detect()}. The dark-background flag stays
     * at its default (true) since that's not detectable without an
     * OSC 11 round trip — toggle it explicitly via
     * {@see withHasDarkBackground()} once the program has the answer.
     */
    public static function fromEnvironment(): self
    {
        return new self(ColorProfile::detect(), true);
    }

    /** Override the color profile (downsamples colours when rendering). */
    public function withColorProfile(ColorProfile $p): self
    {
        return new self($p, $this->hasDarkBackground);
    }

    /**
     * Override the dark-background flag. Bubble Tea programs set this
     * from a `BackgroundColorMsg::isDark()` reply on startup; for
     * non-interactive output, leave at the default.
     */
    public function withHasDarkBackground(bool $isDark): self
    {
        return new self($this->colorProfile, $isDark);
    }

    /**
     * Create a fresh {@see Style} pre-configured with this renderer's
     * color profile. Equivalent to `Style::new()->colorProfile($p)`.
     */
    public function newStyle(): Style
    {
        return Style::new()->colorProfile($this->colorProfile);
    }

    /**
     * Bind this renderer's `hasDarkBackground` to a {@see LightDark}
     * picker. Equivalent to `LightDark::picker($r->hasDarkBackground)`.
     *
     * @return \Closure(\SugarCraft\Core\Util\Color, \SugarCraft\Core\Util\Color): \SugarCraft\Core\Util\Color
     */
    public function lightDark(): \Closure
    {
        return LightDark::picker($this->hasDarkBackground);
    }

    /**
     * Resolve an {@see AdaptiveColor} with this renderer's dark-bg
     * flag. Mirrors lipgloss `Renderer.HasDarkBackground` + adaptive
     * pick.
     */
    public function resolveAdaptive(AdaptiveColor $color): \SugarCraft\Core\Util\Color
    {
        return $color->pick($this->hasDarkBackground);
    }
}
