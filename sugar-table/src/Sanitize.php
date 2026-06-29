<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Sanitize cell and header values before they reach the terminal buffer.
 *
 * Prevents ANSI injection attacks (CSI/OSC escape sequences embedded in data)
 * and other control characters from being interpreted as terminal commands.
 *
 * Mirrors the pattern in {@see \SugarCraft\Query\CellValue::sanitize()}.
 */
final class Sanitize
{
    // Visible replacement character for neutralized control chars
    private const REPLACEMENT = "\xC2\xB7";  // · (U+00B7 MIDDLE DOT) as UTF-8

    /**
     * Sanitize a string value for safe terminal rendering.
     *
     * @param string $s               The raw string to sanitize
     * @param bool   $preserveNewlines When true, preserve \n (for multiline expand
     *                                  paths that explode on "\n"); when false,
     *                                  collapse all newline variants to "\xE2\x86\x92"
     * @return string Sanitized string safe for the terminal
     */
    public static function value(string $s, bool $preserveNewlines = false): string
    {
        // 1. Repair invalid UTF-8 using iconv (ignore errors, replace with //IGNORE)
        $repaired = @\iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if (\is_string($repaired)) {
            $s = $repaired;
        }

        // 2. Handle newlines FIRST — before C0 replacement — since \n (0x0A)
        // and \r (0x0D) are themselves in the C0 block and would otherwise be
        // caught by the C0 sweep.
        if ($preserveNewlines) {
            // Multiline: normalize all line endings to \n for consistent explode()
            $s = \str_replace(["\r\n", "\r"], "\n", $s);
        } else {
            // Single-line: collapse all line endings to the visually-distinct ↵ glyph
            $s = \str_replace(["\r\n", "\r", "\n"], "\xE2\x86\x92", $s);
        }

        // 3. Neutralize remaining C0 control characters (0x00–0x1F) and DEL (0x7F).
        // These are bytes 0x00-0x1F and 0x7F (ASCII control chars).
        // Note: \n and \r were already handled in step 2 above.
        $s = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', self::REPLACEMENT, $s);

        // 4. Neutralize C1 control characters (U+0080–U+009F).  These appear in
        // UTF-8 as the two-byte sequence \xC2\x80–\xC2\x9F.  Using the /u flag so
        // \x{0080} matches the Unicode code point (not a raw 0x80 byte, which
        // could be a UTF-8 continuation byte).
        $s = \preg_replace('/[\x{0080}-\x{009F}]/u', self::REPLACEMENT, $s);

        return $s;
    }
}
