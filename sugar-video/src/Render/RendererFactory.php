<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\Mosaic;
use SugarCraft\Image\TermDb;
use SugarCraft\Image\TermInfo;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\Capability;

/**
 * Static factory for FrameRenderer instances.
 *
 * auto() picks the best available rendering mode by probing terminal
 * capabilities (sixel > kitty > iterm2) then falling back to
 * color-profile detection (TrueColor > Ansi256 > Ascii). The text-cell
 * TrueColor rung is F-06 symbol-gated: HalfBlock draws Unicode blocks,
 * and a terminal whose table row says blocks are unsafe (TERM=linux
 * console, cmd.exe — chicle's LINUX_CONSOLE_SYMS/WIN_TERMINAL_SYMS rows)
 * steps down to the Ansi256 rung, whose grayscale ramp is pure ASCII.
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
     *  4. TrueColor profile → HalfBlock, F-06-gated on safe block glyphs;
     *     on an ASCII-only seat the rung steps down to Ansi256
     *  5. Ansi256 profile → Ansi256
     *  6. Default → Ascii
     *
     * This is the mode-picking logic extracted so Reel::play() can resolve
     * the auto-detected Mode and pass it to Player::open() (F3).
     */
    public static function autoMode(): Mode
    {
        $report = Mosaic::diagnose();

        return self::autoModeFrom(
            sixel: $report->has(Capability::Sixel),
            kitty: $report->has(Capability::KittyKeyboard),
            iterm2: $report->has(Capability::ITerm2),
            profile: Probe::colorProfile(),
            info: TermDb::matchChain(TermDb::snapshotEnv()),
        );
    }

    /**
     * F-06: the pure decision behind autoMode() — resolved inputs in, Mode
     * out, no probing. autoMode() collects the live signals (capability
     * report, color profile, and the PURE TermDb table consult — no tty, no
     * exec, matching the ladder's own "no invented detection" rule); tests
     * drive this seam directly instead of poisoning the process environment.
     *
     * Precedence unchanged from autoMode()'s documented ladder, except the
     * TrueColor rung now requires the BLOCK symbol class to be safe: on an
     * ASCII-only seat the rung cannot draw ▀/▄, so the same color profile
     * answers with the Ansi256 rung (ASCII luma ramp, 256-color SGR).
     */
    public static function autoModeFrom(bool $sixel, bool $kitty, bool $iterm2, ?ColorProfile $profile, TermInfo $info): Mode
    {
        if ($sixel) {
            return Mode::Sixel;
        }
        if ($kitty) {
            // Note: Kitty image mode is gated on Capability::KittyKeyboard because
            // sugar-colorprofile exposes no standalone KittyGraphics capability.
            return Mode::Kitty;
        }
        if ($iterm2) {
            return Mode::Iterm2;
        }

        if ($profile === ColorProfile::TrueColor) {
            if ($info->symbolIsSafe(TermInfo::SYMBOL_BLOCK)) {
                return Mode::HalfBlock;
            }
            // F-06 fall-through: block glyphs would mojibake; keep color,
            // drop to the text-cell tier one rung down.
            return Mode::Ansi256;
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
            return self::new($preferred);
        }

        return self::new(self::autoMode());
    }

    /**
     * Return the renderer for the given explicit mode.
     *
     * @param string $ramp    Luma ramp name: 'minimal', 'standard', 'dense'
     * @param int    $cellPxW Pixel width of a terminal cell (graphics modes only — used to
     *                        recover the cell footprint and size the sixel canvas)
     * @param int    $cellPxH Pixel height of a terminal cell
     */
    public static function new(Mode $mode, string $ramp = 'standard', int $cellPxW = 10, int $cellPxH = 20): FrameRenderer
    {
        return match ($mode) {
            Mode::Ascii     => new AsciiRenderer($ramp),
            Mode::Ansi256   => new AsciiRenderer($ramp),
            Mode::TrueColor => new AsciiRenderer($ramp),
            Mode::HalfBlock => new HalfBlockRenderer(),
            Mode::QuarterBlock => new QuarterBlockRenderer(),
            Mode::Sixel     => new GraphicsRenderer(Mode::Sixel, $cellPxW, $cellPxH),
            Mode::Kitty     => new GraphicsRenderer(Mode::Kitty, $cellPxW, $cellPxH),
            Mode::Iterm2    => new GraphicsRenderer(Mode::Iterm2, $cellPxW, $cellPxH),
        };
    }
}
