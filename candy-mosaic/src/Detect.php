<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Terminal image-protocol capability detection.
 *
 * Probes environment variables and (optionally) sends DA1 queries to
 * determine which image-rendering protocols the current terminal
 * supports. Results are cached per-process.
 *
 * Precedence: Kitty → iTerm2 → Sixel → HalfBlock (always available).
 *
 * DA1 probing (querying the terminal's device attributes) requires an
 * interactive TTY and is deferred to PR5.
 */
final class Detect
{
    private static ?Capability $cached = null;

    /**
     * Probe once and cache the result for the lifetime of this process.
     */
    public static function cached(): Capability
    {
        return self::$cached ??= self::probe();
    }

    /**
     * Detect which image protocol to use based on environment variables.
     * DA1 queries are handled separately in {@see Detect::probeDa1()}.
     */
    public static function probe(): Capability
    {
        return self::probeEnv();
    }

    /**
     * Clear the cached capability (useful for testing).
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /**
     * Detect based solely on environment variables.
     * DA1 probing is NOT performed here — see {@see Detect::probeDa1()}
     * (PR5).
     */
    private static function probeEnv(): Capability
    {
        // Kitty: KITTY_WINDOW_ID set, or known kitty-family $TERM, or ghostty/WezTerm.
        if (getenv('KITTY_WINDOW_ID') !== false
            || getenv('TERM_PROGRAM') === 'WezTerm'
            || getenv('TERM_PROGRAM') === 'ghostty'
            || preg_match('/xterm-kitty/i', (string) getenv('TERM')) === 1
        ) {
            return Capability::kitty();
        }

        // iTerm2: iTerm.app, WezTerm, mintty, or LC_TERMINAL=iTerm2.
        $termProgram = (string) getenv('TERM_PROGRAM');
        $lcTerminal  = (string) getenv('LC_TERMINAL');
        if ($termProgram === 'iTerm.app'
            || $termProgram === 'WezTerm'
            || $termProgram === 'mintty'
            || $lcTerminal === 'iTerm2'
        ) {
            return Capability::iterm2();
        }

        // Sixel: strong env-var hints.
        if (self::hasSixelEnvHints()) {
            return Capability::sixel();
        }

        // Half-block: always available.
        return Capability::unknown();
    }

    /**
     * Terminals known to support Sixel based purely on $TERM + $XTERM_VERSION.
     */
    private static function hasSixelEnvHints(): bool
    {
        $term = (string) getenv('TERM');
        $xtermVersion = (string) getenv('XTERM_VERSION');

        return (
            ($xtermVersion !== '')
            && preg_match('/^(mlterm|foot|xterm(-256color)?)$/i', $term) === 1
        );
    }
}
