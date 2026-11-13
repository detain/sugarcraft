<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * Terminal color capability profile — THE single vocabulary.
 *
 * Ordered richest to simplest so {@see self::degradedTo()} walks the chain
 * downwards:
 * TrueColor → ANSI256 → ANSI240 → ANSI → ANSI8 → FGBG → ASCII → NoTTY.
 *
 * CONSOLIDATION. This library once carried two parallel five-level enums
 * under the same wire values — {@see Profile} (renderer-facing, SCREAMING
 * case spellings) and this one (detection-facing, CamelCase spellings).
 * ColorProfile is now canonical in its CamelCase spelling, and {@see Profile}
 * survives as a deprecated class_alias façade. The old SCREAMING spellings
 * resolve through the {@see self::ANSI256()}/{@see self::ANSI()} deprecated
 * constants below, so `Profile::ANSI256` keeps evaluating — to the very
 * same case object — during the deprecation window.
 *
 * THE THREE NEW ARMS MIRROR chafa's canvas-mode ladder (chafa-common.h:202-224):
 * - Ansi240  ← CHAFA_canvas_INT_INDEXED_240: the fixed part of the xterm-256
 *   palette ONLY (slots 16-255). The themeable basic 16 vary per user theme,
 *   so a renderer targeting "240 colors" must never emit slots 0-15. The
 *   quantiser here is 240-safe by construction: Color::toAnsi256Index()
 *   lands exclusively on 16-255 (see its docblock); Palette::emitColor()
 *   additionally refuses raw passthrough of a 38;5;k slot < 16.
 * - Ansi8    ← CHAFA_canvas_INT_INDEXED_16_8 territory: the eight basic
 *   colors (slots 0-7), no bright variants.
 * - Fgbg     ← CHAFA_canvas_INT_FGBG: two-color foreground/background mode.
 *   At the palette layer this reduces every color to black or white by
 *   perceived brightness; the actual inversion painting is a render-side
 *   policy this vocabulary only names (FgbgInvert/FgbgPlain remain future
 *   additions).
 *
 * Detection (Probe, AsyncProbe, DetectionChain, Palette::detect) never
 * EMITS the three new arms — environment probing cannot distinguish a
 * 240-color terminal from a 256-color one. They are request-side vocabulary:
 * pass them to Palette::withProfile(), Color::convert() or ProfileWriter to
 * force that degradation level. The ASCII arm IS reachable from detection:
 * a falsy FORCE_COLOR spelling (0/false/off) lands there as step 2 of the
 * chain ladder, and since CP-05 (Shape B) the NO_COLOR / CLICOLOR=0 /
 * TERM=dumb suppressions land there too — all four mean the same thing:
 * "disable color, keep decoration". Only hardware absence (the stream/TTY
 * gate) still answers NoTTY's full strip.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
enum ColorProfile: string
{
    /** Full 24-bit TrueColor (16.7 million colors). */
    case TrueColor = 'truecolor';

    /** 256-color ANSI (216 cube + 24 grayscale + 16 standard). */
    case Ansi256 = 'ansi256';

    /** 240 fixed palette colors (slots 16-255; the themeable 16 excluded). */
    case Ansi240 = 'ansi240';

    /** Classic 16-color ANSI. */
    case Ansi = 'ansi';

    /** Basic 8 ANSI colors (slots 0-7; no bright variants). */
    case Ansi8 = 'ansi8';

    /** Two-color foreground/background (black or white by brightness). */
    case Fgbg = 'fgbg';

    /** Colorless mode — no color codes are emitted; decoration and every
     *  other sequence pass through (the color-only strip rung, CP-05). */
    case Ascii = 'ascii';

    /** Hardware absence — no TTY in front of the writer, so every ANSI
     *  sequence must be stripped, decoration included. User suppression
     *  (NO_COLOR, CLICOLOR=0, TERM=dumb) does NOT land here: since CP-05 it
     *  answers the colorless Ascii rung, and DetectionChain::userOptedOut()
     *  carries the WHY a caller needs (CP-20, sugar-colorprofile-023). */
    case NoTTY = 'notty';

    /**
     * @deprecated — SCREAMING-era spelling kept for the old {@see Profile}
     *             case names; it resolves to this very case object. Use
     *             {@see self::Ansi256()}.
     */
    public const ANSI256 = self::Ansi256;

    /**
     * @deprecated — SCREAMING-era spelling kept for the old {@see Profile}
     *             case names; it resolves to this very case object. Use
     *             {@see self::Ansi()}.
     */
    public const ANSI = self::Ansi;

    /**
     * @deprecated — identity bridge. Profile IS this enum
     *             (class_alias façade), the two vocabularies are one; this
     *             method survives only so old call sites compile unchanged.
     */
    public static function fromColorProfile(self $p): self
    {
        return $p;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::TrueColor => 'TrueColor',
            self::Ansi256   => 'ANSI 256',
            self::Ansi240   => 'ANSI 240',
            self::Ansi      => 'ANSI',
            self::Ansi8     => 'ANSI 8',
            self::Fgbg      => 'FGBG',
            self::Ascii     => 'ASCII',
            self::NoTTY     => 'No TTY',
        };
    }

    /**
     * Short description for display.
     */
    public function description(): string
    {
        return match ($this) {
            self::TrueColor => '24-bit full color',
            self::Ansi256   => '256-color palette',
            self::Ansi240   => '240-color fixed palette (themeable 16 excluded)',
            self::Ansi      => '16-color standard',
            self::Ansi8     => '8-color basic ANSI',
            self::Fgbg      => 'two-color foreground/background',
            self::Ascii     => 'no color codes (decoration kept)',
            self::NoTTY     => 'colors disabled',
        };
    }

    /**
     * Maximum colors this profile supports.
     */
    public function maxColors(): int
    {
        return match ($this) {
            self::TrueColor => 16_777_216,
            self::Ansi256   => 256,
            self::Ansi240   => 240,
            self::Ansi      => 16,
            self::Ansi8     => 8,
            self::Fgbg      => 2,
            self::Ascii     => 0,
            self::NoTTY     => 0,
        };
    }

    /**
     * Walk the profile downgrade chain to the next simpler profile.
     * Returns null when already at the simplest (NoTTY). The walk is about
     * emission richness, not provenance: standing on either colorless rung
     * it cannot tell an operator opt-out from absent hardware —
     * DetectionChain::userOptedOut() answers that (CP-20).
     */
    public function degradedTo(): ?self
    {
        return match ($this) {
            self::TrueColor => self::Ansi256,
            self::Ansi256   => self::Ansi240,
            self::Ansi240   => self::Ansi,
            self::Ansi      => self::Ansi8,
            self::Ansi8     => self::Fgbg,
            self::Fgbg      => self::Ascii,
            self::Ascii     => self::NoTTY,
            self::NoTTY     => null,
        };
    }

    /**
     * Whether this profile permits ANSI COLOR rendering.
     *
     * NoTTY strips everything; the colorless Ascii rung (CP-05 Shape B)
     * renders no color at all — decoration passes, color codes do not.
     * Both answer false; every rung at Ansi8 depth or above answers true.
     */
    public function allowsColor(): bool
    {
        return $this !== self::NoTTY && $this !== self::Ascii;
    }
}
