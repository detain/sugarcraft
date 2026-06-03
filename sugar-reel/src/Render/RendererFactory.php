<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Palette\ColorProfile;
use SugarCraft\Palette\Probe;
use SugarCraft\Palette\Probe\Capability;

/**
 * Static factory for FrameRenderer instances.
 *
 * auto() picks the best available rendering mode by probing terminal
 * capabilities (sixel > kitty > iterm2) then falling back to
 * color-profile detection (TrueColor > Ansi256 > Ascii).
 *
 * No single upstream — drawn from maxcurzi/tplay, seatedro/glyph,
 * and joelibaceta/video-to-ascii.
 */
final class RendererFactory
{
    /**
     * Return the best Mode enum case for the current terminal.
     *
     * Precedence:
     *  1. Sixel if Mosaic::diagnose() reports sixel support
     *  2. Kitty if Mosaic::diagnose() reports kitty support
     *  3. Iterm2 if Mosaic::diagnose() reports iterm2 support
     *  4. TrueColor profile → HalfBlock
     *  5. Ansi256 profile → Ansi256
     *  6. Default → Ascii
     *
     * This is the mode-picking logic extracted so Reel::play() can resolve
     * the auto-detected Mode and pass it to Player::open() (F3).
     */
    public static function autoMode(): Mode
    {
        $report = Mosaic::diagnose();

        if ($report->has(Capability::Sixel)) {
            return Mode::Sixel;
        }
        if ($report->has(Capability::KittyKeyboard)) {
            // Note: Kitty image mode is gated on Capability::KittyKeyboard because
            // candy-palette exposes no standalone KittyGraphics capability.
            return Mode::Kitty;
        }
        if ($report->has(Capability::ITerm2)) {
            return Mode::Iterm2;
        }

        $profile = Probe::colorProfile();

        if ($profile === ColorProfile::TrueColor) {
            return Mode::HalfBlock;
        }
        if ($profile === ColorProfile::Ansi256) {
            return Mode::Ansi256;
        }

        return Mode::Ascii;
    }

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

        return self::create(self::autoMode());
    }

    /**
     * Return the renderer for the given explicit mode.
     *
     * @param string $ramp Luma ramp name: 'minimal', 'standard', 'dense'
     */
    public static function create(Mode $mode, string $ramp = 'standard'): FrameRenderer
    {
        return match ($mode) {
            Mode::Ascii     => new AsciiRenderer($ramp),
            Mode::Ansi256   => new AsciiRenderer($ramp),
            Mode::TrueColor => new AsciiRenderer($ramp),
            Mode::HalfBlock => new HalfBlockRenderer(),
            Mode::Sixel     => new GraphicsRenderer(Mode::Sixel),
            Mode::Kitty     => new GraphicsRenderer(Mode::Kitty),
            Mode::Iterm2    => new GraphicsRenderer(Mode::Iterm2),
        };
    }
}
