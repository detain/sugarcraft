<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\Mosaic;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Player;
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
     * Per-process memo of the live capability probe.
     *
     * WHY a memo: Mosaic::diagnose() does NOT negotiate with the terminal —
     * it delegates to sugar-colorprofile's TerminalProbe, a TERM_PROGRAM
     * lookup table over the environment and terminfo that (despite the
     * historical name) issues no raw OSC query round-trips. A terminal's
     * capabilities are fixed for the session, and the capability door below
     * runs on every renderer acquisition — which includes every `m`
     * keypress — so probing once per process keeps the auto() ladder, the
     * mode cycle list and the door permanently agreeing on what the seat
     * can receive.
     */
    private static ?ProbeReport $reportMemo = null;

    /** SV-L66/066: scripted ext-gd presence (null = live check). */
    private static ?\Closure $graphicsGate = null;

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
     * SV-L66/066: seam for the graphics rung's ext-gd requirement
     * (null = the live function_exists check). {@see new()} consults it
     * through {@see graphicsRenderable()}, so a pin can script a gd-less
     * PHP build — the honest test target is the caller's DEGRADE/THROW
     * split, which is otherwise unreachable on every real host.
     */
    public static function setGraphicsGateForTesting(?\Closure $gate): void
    {
        self::$graphicsGate = $gate;
    }

    /**
     * Whether this PHP build can paint the GD-backed rungs. The single door
     * is consulted by the graphics construction gate in new() (encode paths)
     * and — since SV-L72/072 — by the block-bridge entry guards in
     * HalfBlock/QuarterBlockRenderer, which cross RgbFrame through GD at
     * render time while their construction leg stays gd-free for the Player.
     */
    public static function graphicsRenderable(): bool
    {
        if (self::$graphicsGate !== null) {
            return (bool) (self::$graphicsGate)();
        }

        return function_exists('imagecreatetruecolor');
    }

    /**
     * Every Mode this terminal can honestly render right now.
     *
     * @return list<Mode>
     */
    public static function supportedModes(): array
    {
        return array_values(array_filter(Mode::cases(), static fn (Mode $mode): bool => self::supports($mode)));
    }

    /**
     * Whether this terminal can actually RECEIVE the mode's output.
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
     * The FULL honest verdict for one mode at one geometry (SV-M35/035).
     *
     * Where {@see supports()} answers only "would the seat RECEIVE these
     * bytes" (the transport law that every construction door obeys), this
     * answers the question a host actually holds — "can I hand this terminal
     * this mode at this size?" — folding the protocol claim, the ext-gd
     * requirement (SV-L66/066), the block-glyph safety gate the auto ladder
     * alone used to own, and the SV-H7/007 cell window into one inspectable
     * {@see Support} case. Pass no geometry to ask capability only.
     *
     * Nothing throws for a plain "not here"; the refusal is data.
     */
    public static function support(Mode $mode, ?int $cols = null, ?int $rows = null): Support
    {
        $claimsProtocol = match ($mode) {
            Mode::Sixel => self::diagnose()->has(Capability::Sixel),
            Mode::Kitty => self::diagnose()->has(Capability::KittyKeyboard),
            Mode::Iterm2 => self::diagnose()->has(Capability::ITerm2),
            default => true,
        };

        return self::supportShape(
            $mode,
            claimsProtocol: $claimsProtocol,
            gdPresent: self::graphicsRenderable(),
            blockGlyphsSafe: TermDb::matchChain(TermDb::snapshotEnv())->symbolIsSafe(TermInfo::SYMBOL_BLOCK),
            cols: $cols,
            rows: $rows,
        );
    }

    /**
     * The pure decision behind {@see support()} — resolved facts in, verdict
     * out, no probing (the autoModeFrom() pattern: pins drive every arm
     * without poisoning the process environment).
     */
    public static function supportShape(
        Mode $mode,
        bool $claimsProtocol,
        bool $gdPresent,
        bool $blockGlyphsSafe,
        ?int $cols = null,
        ?int $rows = null,
    ): Support {
        // Geometry is the CALLER'S input error; report it before the seat's
        // fortune. The window is the single-sourced SV-H7/007 clamp constants
        // — read off Player, never re-typed here.
        if ($cols !== null && ($cols < Player::MIN_COLS || ($rows !== null && $rows < Player::MIN_ROWS))) {
            return Support::GeometryTooSmall;
        }

        if ($cols !== null && ($cols > Player::MAX_COLS || ($rows !== null && $rows > Player::MAX_ROWS))) {
            return Support::GeometryTooLarge;
        }

        if ($rows !== null && ($rows < Player::MIN_ROWS || $rows > Player::MAX_ROWS)) {
            return $rows < Player::MIN_ROWS ? Support::GeometryTooSmall : Support::GeometryTooLarge;
        }

        if ($mode->isGraphics()) {
            if (!$claimsProtocol) {
                return Support::NeedsProtocol;
            }

            return $gdPresent ? Support::Supported : Support::NeedsGd;
        }

        // The block rungs paint Unicode ▀▄ glyphs; on a seat that cannot draw
        // them safely the auto ladder steps down (the truecolor rung law) and
        // the explicit door owes the host the same truth.
        if (($mode === Mode::HalfBlock || $mode === Mode::QuarterBlock) && !$blockGlyphsSafe) {
            return Support::NeedsBlockGlyphs;
        }

        // Ascii/Ansi256/TrueColor are SGR-and-glyphs transport-safe text rungs.
        return Support::Supported;
    }

    /**
     * The boolean the consumer loop wants: would {@see support()} say
     * Supported at this exact seat+size? (SV-M35/035 — candy-code can delete
     * its hand-rolled graphics-mode matrix on top of this door.)
     */
    public static function canRender(Mode $mode, int $cols, int $rows): bool
    {
        return self::support($mode, $cols, $rows)->isRenderable();
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
     *  5. TrueColor profile → HalfBlock, gated on
     *     TermInfo::symbolIsSafe(TermInfo::SYMBOL_BLOCK); on a seat whose
     *     table says blocks are unsafe the rung steps down to Ansi256
     *     (ASCII luma ramp, 256-color SGR)
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
        // SV-L66/066: the answer rides a seam (default = the live function
        // check) so a test can script the gd-less seat without uninstalling
        // an extension — the point being proven is the PLAYER's response to
        // this refusal, not GD's absence itself.
        if ($mode->isGraphics() && !self::graphicsRenderable()) {
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
