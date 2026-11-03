<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * Tiny ANSI helper. Wraps `text` in `\x1b[<codes>m...\x1b[0m`.
 * Empty `codes` is a no-op so callers can pass a configurable style.
 *
 * Also provides sanitization for untrusted content before ANSI emission,
 * stripping C0 control bytes that could drive the terminal.
 */
final class Ansi
{
    public static function wrap(string $text, string $codes): string
    {
        if ($codes === '') {
            return $text;
        }

        // NO_COLOR convention (https://no-color.org): any non-empty value
        // asks for plain text. The library-wide escape hatch — decorations
        // (bold/reverse) fall away with colour, matching what terminals in
        // NO_COLOR mode can reasonably render.
        $noColor = \getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return $text;
        }

        return "\x1b[{$codes}m{$text}\x1b[0m";
    }

    /**
     * Strip C0 control bytes from text before ANSI emission.
     *
     * Removes control characters that could drive the terminal (ESC, etc.)
     * while preserving tab and newline which have legitimate uses in multi-line
     * prompts. Carriage return is stripped, not preserved: a raw CR inside
     * untrusted content lets it rewind the cursor and overwrite the line it
     * was printed on. Does NOT strip high-bit characters or multibyte UTF-8
     * sequences.
     */
    public static function sanitize(string $text): string
    {
        // Strip C0 controls except TAB (0x09) and LF (0x0a)
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);
    }

    private function __construct() {}
}
