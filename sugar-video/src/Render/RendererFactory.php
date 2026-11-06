<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\Mosaic;
use SugarCraft\Video\Lang;
use SugarCraft\Image\TermDb;
use SugarCraft\Image\TermInfo;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;

/**
 * Static factory for FrameRenderer instances.
 *
 * auto() picks the best available rendering mode by probing terminal
 * capabilities (sixel > kitty > iterm2) then falling back to
 * color-profile detection (TrueColor > Ansi256 > Ascii). The text-cell
 * TrueColor rung is symbol-gated: HalfBlock draws Unicode blocks,
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
     * Per-process memo of the live capability probe (M28).
     *
     * WHY a memo: Mosaic::diagnose() is a SYNCHRONOUS terminal negotiation.
     * The capability door below runs on every renderer acquisition — which
     * includes every `m` keypress — and the pre-memo code re-probed each
     * time, paying a round-trip's latency inside a keystroke to re-derive an
     * answer that cannot change under a running program: a terminal's
     * capabilities are fixed for the session. One probe per process is both
     * the honest and the cheap shape.
     */
    private static ?ProbeReport $reportMemo = null;

    /**
     * The terminal's capability report — probed once, memoized thereafter.
     */
    public static function diagnose(): ProbeReport
    {
        return self::$reportMemo ??= Mosaic::diagnose();
    }

    /**
     * Test seam: seed the capability memo (null = clear it).
     *
     * Lets pins script the door without poisoning the process environment —
     * same rationale as the autoModeFrom() pure seam above.
     */
    public static function setDiagnoseForTesting(?ProbeReport $report): void
    {
        self::$reportMemo = $report;
    }

    /**
     * Whether this terminal can actually RECEIVE the mode's output (M1).
     *
     * The text rungs (Ascii/Ansi256/TrueColor/HalfBlock/QuarterBlock) are
     * always transport-safe: they emit only SGR and glyphs, which degrade
     * visibly but harmlessly on any seat. The GRAPHICS rungs are not: their
     * APC/DCS/OSC-1337 payloads are invisible to a terminal that never
     * claimed the protocol, and the un-erased image data accumulates as
     * garbage in the scrollback (tmux) or worse. Before this door, any
     * explicit graphics selection — Reel::withMode(), Player::open(mode:),
     * the example's argv — handed raw escape bytes straight to such a seat.
     *
     * The door is ONE: every renderer construction path routes through
     * new(), which refuses an unsupported mode loudly (typed refusal naming
     * the best supported mode) instead of silently emitting into it.
     *
     * Kitty rides Capability::KittyKeyboard because sugar-colorprofile
     * exposes no standalone KittyGraphics bit (cross-lib seam recorded in
     * the findings ledger); the auto ladder gates on the same signal, so
     * ladder and door can never disagree.
     */
    /**
     * m16: every Mode this terminal can honestly render right now.
     *
     * @return list<Mode>
     */
    public static function supportedModes(): array
    {
        return array_values(array_filter(Mode::cases(), static fn (Mode $mode): bool => self::supports($mode)));
    }

    public static function supports(Mode $mode): bool
    {
        return match ($mode) {
            Mode::Sixel => self::diagnose()->has(Capability::Sixel),
            Mode::Kitty => self::diagnose()->has(Capability::KittyKeyboard),
            Mode::Iterm2 => self::diagnose()->has(Capability::ITerm2),
            default => true,
        };
    }

    /**
     * Return the best Mode enum case for the current terminal.
     *
     * Precedence:
     *  1. Sixel if Mosaic::diagnose() reports sixel support
     *  2. Kitty if Mosaic::diagnose() reports kitty support
     *  3. Iterm2 if Mosaic::diagnose() reports iterm2 support
     *  4. TrueColor profile → HalfBlock, gated on safe block glyphs;
     *     on an ASCII-only seat the rung steps down to Ansi256
     *  5. Ansi256 profile → Ansi256
     *  6. Default → Ascii
     *
     * This is the mode-picking logic extracted so Reel::play() can resolve
     * the auto-detected Mode and pass it to Player::open().
     */
    public static function autoMode(): Mode
    {
        $report = self::diagnose();

        return self::autoModeFrom(
            sixel: $report->has(Capability::Sixel),
            kitty: $report->has(Capability::KittyKeyboard),
            iterm2: $report->has(Capability::ITerm2),
            profile: Probe::colorProfile(),
            info: TermDb::matchChain(TermDb::snapshotEnv()),
        );
    }

    /**
     * The pure decision behind autoMode() — resolved inputs in, Mode
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
            // Fall-through: block glyphs would mojibake; keep color,
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
     *
     * @throws \RuntimeException when the mode is unsupported on this terminal
     */
    public static function new(Mode $mode, string $ramp = 'standard', int $cellPxW = 10, int $cellPxH = 20): FrameRenderer
    {
        // Graphics rungs delegate to ext-gd encode paths upstream; refuse with a
        // typed error here instead of fataling on an undefined GD function.
        if ($mode->isGraphics() && !function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException(Lang::t('decoder.gd_missing'));
        }

        if (!self::supports($mode)) {
            throw new \RuntimeException(Lang::t('render.mode_unsupported', [
                'mode' => $mode->label(),
                'best' => self::autoMode()->label(),
            ]));
        }

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
