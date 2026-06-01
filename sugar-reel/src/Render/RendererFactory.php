<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Palette\ColorProfile;
use SugarCraft\Palette\Probe;

/**
 * Static factory for FrameRenderer instances.
 *
 * auto() picks the best available rendering mode by probing terminal
 * capabilities (sixel > kitty > iterm2) then falling back to
 * color-profile detection (TrueColor > Ansi256 > Ascii).
 *
 * Mirrors charmbracelet/sugar-reel Render.RendererFactory.
 */
final class RendererFactory
{
    /**
     * Pick the best renderer by probing terminal capabilities.
     *
     * Precedence:
     *  1. $preferred mode if given
     *  2. Sixel if Mosaic::diagnose() reports sixel support
     *  3. Kitty if Mosaic::diagnose() reports kitty support
     *  4. Iterm2 if Mosaic::diagnose() reports iterm2 support
     *  5. TrueColor profile → HalfBlock
     *  6. Ansi256 profile → Ansi256
     *  7. Default → Ascii
     *
     * @param Mode|null $preferred Preferred mode override (null = auto-detect)
     */
    public static function auto(?Mode $preferred = null): FrameRenderer
    {
        if ($preferred !== null) {
            return self::create($preferred);
        }

        // Probe terminal for sixel/kitty/iterm2 capabilities
        // via Mosaic::diagnose() (candy-mosaic Mosaic.php:124).
        $report = Mosaic::diagnose();

        if ($report->sixel) {
            return self::create(Mode::Sixel);
        }
        if ($report->kitty) {
            return self::create(Mode::Kitty);
        }
        if ($report->iterm2) {
            return self::create(Mode::Iterm2);
        }

        // Fall back to color-profile detection
        // via Probe::colorProfile() (candy-palette Probe.php:31).
        $profile = Probe::colorProfile();

        if ($profile === ColorProfile::TrueColor) {
            return self::create(Mode::HalfBlock);
        }
        if ($profile === ColorProfile::Ansi256) {
            return self::create(Mode::Ansi256);
        }

        return self::create(Mode::Ascii);
    }

    /**
     * Return the renderer for the given explicit mode.
     *
     * Sixel, Kitty, and Iterm2 modes are stubbed in Step 3
     * and will be implemented in Step 6.
     */
    public static function create(Mode $mode): FrameRenderer
    {
        return match ($mode) {
            Mode::Ascii     => new AsciiRenderer(),
            Mode::Ansi256   => new AsciiRenderer(),
            Mode::TrueColor => new AsciiRenderer(),
            Mode::HalfBlock => new HalfBlockRenderer(),
            // Step 6 will add SixelRenderer, KittyRenderer, Iterm2Renderer.
            Mode::Sixel,
            Mode::Kitty,
            Mode::Iterm2 => throw new \InvalidArgumentException(
                "Renderer for mode {$mode->value} is not implemented yet (Step 6)"
            ),
        };
    }
}
