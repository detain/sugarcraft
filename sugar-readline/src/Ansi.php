<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

use SugarCraft\Core\Util\Ansi as CoreAnsi;

/**
 * Tiny ANSI helper. Wraps `text` in `\x1b[<codes>m...\x1b[0m`.
 * Empty `codes` is a no-op so callers can pass a configurable style.
 *
 * Also provides sanitization for untrusted content before ANSI emission,
 * stripping C0 control bytes that could drive the terminal.
 *
 * Finding 072 (S-48): the escape-set DIVERGENCE from sugar-core, recorded.
 * {@see CoreAnsi::strip()} removes well-formed escape SEQUENCES so widths can
 * be computed over text — it deliberately keeps bare C0 bytes (BEL rings the
 * terminal, BS and CR rewind it), which is exactly what hostile input must
 * never deliver. sugar-core's Util\Sanitize C0 families keep CR or replace
 * bytes with grid cells — neither matches this input-safety posture. So the
 * two sweep sets below are spelled here, once, as a documented divergence —
 * and every paste/cleanup site in the library routes through them instead of
 * re-rolling the regex inline. The decoration bytes themselves are NOT
 * re-spelled: wrap composes {@see CoreAnsi::CSI} and {@see CoreAnsi::reset()},
 * the same canonical sequences the rest of the fleet emits.
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

        return CoreAnsi::CSI . $codes . 'm' . $text . CoreAnsi::reset();
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
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text) ?? '';
    }

    /**
     * Single-line posture: like {@see sanitize()} but LF is stripped too —
     * a pasted newline must not forge a submit or a multi-line value in a
     * one-line prompt. TAB survives (completion wants it readable).
     */
    public static function sanitizeForSingleLine(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0a-\x1f\x7f]/', '', $text) ?? '';
    }

    private function __construct() {}
}
