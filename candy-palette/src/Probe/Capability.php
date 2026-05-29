<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Probe;

/**
 * Terminal capability enum discovered via TerminalProbe.
 *
 * Each case represents a terminal feature that can be detected.
 * The source string (env:var, terminfo:cap, escape:seq) is stored in ProbeReport.
 *
 * Mirrors charmbracelet/vhs terminal capability detection.
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

    /** Bracketed paste mode support. */
    case BracketedPaste = 'bracketed-paste';

    /** Focus tracking events support. */
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
}
