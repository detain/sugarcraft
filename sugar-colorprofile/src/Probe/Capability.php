<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

/**
 * Terminal capability enum discovered via TerminalProbe.
 *
 * Each case represents a terminal feature that can be detected.
 * The source string records WHERE the verdict came from: `env:VAR` (environment
 * variable), `terminfo:cap` (infocmp capability) or `fallback:reason`
 * (floor rules).
 *
 * Mirrors charmbracelet/colorprofile capability detection.
 */
enum Capability: string
{
    /** Full 24-bit TrueColor support (24-bit RGB). */
    case TrueColor = 'truecolor';

    /** 256-color ANSI palette support. */
    case Color256 = '256color';

    /** Basic 16-color ANSI support. */
    case Color16 = '16color';

    /** Colors disabled via NO_COLOR or terminal does not support color. */
    case NoColor = 'nocolor';

    /** Sixel graphics protocol support (DEC). */
    case Sixel = 'sixel';

    /** Kitty keyboard protocol extension. */
    case KittyKeyboard = 'kitty-keyboard';

    /** iTerm2 inline image protocol support. */
    case ITerm2 = 'iterm2';

    /** Hyperlinks (OSC 8) support. */
    case Hyperlinks = 'hyperlinks';

    /**
     * Bracketed paste mode support.
     *
     * @deprecated RESERVED — never produced by this build. Reporting it
     *             would require an OSC 2004 query round-trip, which this
     *             probe deliberately leaves to the TUI layer (raw-mode
     *             read-back); the phase-3 upgrade table in TerminalProbe is
     *             a lookup table, not a query face. Candidates for real
     *             query legs are tracked in CALIBER_LEARNINGS (Phase-5
     *             candidate #6).
     */
    case BracketedPaste = 'bracketed-paste';

    /**
     * Focus tracking events support.
     *
     * @deprecated RESERVED — never produced by this build. Reporting it
     *             would require an OSC 1004 query round-trip, which this
     *             probe deliberately leaves to the TUI layer (raw-mode
     *             read-back); the phase-3 upgrade table in TerminalProbe is
     *             a lookup table, not a query face. Candidates for real
     *             query legs are tracked in CALIBER_LEARNINGS (Phase-5
     *             candidate #6).
     */
    case FocusEvents = 'focus-events';

    /** Basic ASCII-only fallback (always available). */
    case BasicAscii = 'basic-ascii';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::TrueColor     => 'TrueColor',
            self::Color256      => '256-Color',
            self::Color16       => '16-Color',
            self::NoColor       => 'No Color',
            self::Sixel         => 'Sixel',
            self::KittyKeyboard  => 'Kitty Keyboard',
            self::ITerm2         => 'iTerm2',
            self::Hyperlinks    => 'Hyperlinks',
            self::BracketedPaste => 'Bracketed Paste',
            self::FocusEvents   => 'Focus Events',
            self::BasicAscii     => 'Basic ASCII',
        };
    }

    /**
     * The string key a Capability occupies in a raw caps map.
     *
     * PHP arrays cannot key on enum cases under the strict typing this lib
     * uses, so reports carry the backing value as key. Replaces the old
     * namespaced free function at the bottom of TerminalProbe.php, which was
     * not autoloadable through the class map.
     */
    public static function key(self $cap): string
    {
        return $cap->value;
    }
}
