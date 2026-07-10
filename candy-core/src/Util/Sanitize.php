<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Canonical text sanitizer for TUI components — the single source of truth
 * for neutralizing terminal-control-sequence injection across SugarCraft.
 *
 * Terminal control sequence injection is a real TUI attack vector: a raw
 * ESC (0x1b) desyncs the frame-diff renderer's line model, NUL/control
 * bytes garble the terminal, and BEL makes it beep on every repaint. This
 * class is the one place to audit that risk. Three policies are offered,
 * differing in how much they preserve:
 *
 *   - {@see controlChars()} — strips C0 controls (ESC included) and maps
 *     \n \r \t to spaces; the printable SGR parameter text is left behind.
 *   - {@see cellValue()}    — glyph-replacement + UTF-8 repair for data grids.
 *   - {@see untrusted()}    — full ANSI strip for plain-text module sinks.
 *
 * Mirrors charmbracelet/<repo>.sanitize helpers.
 */
final class Sanitize
{
    // Visible stand-in for a neutralized control byte: · (U+00B7 MIDDLE DOT).
    private const CELL_REPLACEMENT = "\xC2\xB7";

    // Visible stand-in for a collapsed newline: ↵ (U+21B5 DOWNWARDS ARROW
    // WITH CORNER LEFTWARDS). Reads as "line ended here" — chosen over → so
    // the glyph carries its return semantics.
    private const NEWLINE_GLYPH = "\xE2\x86\xB5";

    /**
     * Strip C0 control characters from caller-supplied text so they
     * cannot inject newlines or corrupt the TUI render.
     * \n \r \t are replaced with spaces; other C0 (\x00-\x08\x0b\x0c\x0e-\x1f)
     * are removed. ESC (\x1b) is preserved for SGR sequences.
     */
    public static function controlChars(string $s): string
    {
        $s = str_replace(["\n", "\r", "\t"], ' ', $s);
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s) ?? $s;
    }

    /**
     * Turn an arbitrary already-stringified cell value into something safe
     * for a cell-grid renderer: repair invalid UTF-8, collapse (or preserve)
     * newlines, and replace every remaining control byte with a visible dot.
     *
     * Invalid UTF-8 bytes become U+FFFD rather than being silently dropped,
     * so binary/BLOB data keeps a 1:1 visible stand-in and downstream
     * width/truncation math stays sane. This is the canonical policy that
     * the sugar-table and candy-query grids share.
     *
     * @param string $value            Raw, already-stringified cell value.
     * @param bool   $preserveNewlines When true, keep line breaks as "\n" so
     *                                  multiline callers can explode() on them;
     *                                  when false, collapse every newline
     *                                  variant to the ↵ glyph (single line).
     * @return string Sanitized string safe for the terminal buffer.
     */
    public static function cellValue(string $value, bool $preserveNewlines = false): string
    {
        // 1. Repair invalid UTF-8 (binary data) so width/truncation stay sane.
        //    Malformed bytes are substituted with U+FFFD, not dropped, keeping
        //    a visible marker where corrupted bytes were.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD); // U+FFFD REPLACEMENT CHARACTER
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
        }

        // 2. Handle newlines FIRST — before the C0 sweep — because \n (0x0A)
        //    and \r (0x0D) live in the C0 block and would otherwise be caught
        //    below. The two branches also differ in which C0 sweep follows, so
        //    that \n survives only when the caller asked to preserve it.
        if ($preserveNewlines) {
            // Multiline: normalize CRLF/CR to LF for consistent explode("\n").
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            // C0 + DEL sweep that spares the LF we just normalized.
            $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', self::CELL_REPLACEMENT, $value) ?? $value;
        } else {
            // Single-line: collapse every newline variant to the ↵ glyph.
            $value = str_replace(["\r\n", "\r", "\n"], self::NEWLINE_GLYPH, $value);
            // C0 + DEL sweep over everything that remains (no newline survivors).
            $value = preg_replace('/[\x00-\x1F\x7F]/', self::CELL_REPLACEMENT, $value) ?? $value;
        }

        // 3. Neutralize the C1 control range (U+0080–U+009F), now valid UTF-8.
        //    The /u flag matches code points, not raw continuation bytes.
        $value = preg_replace('/[\x{0080}-\x{009F}]/u', self::CELL_REPLACEMENT, $value) ?? $value;

        return $value;
    }

    /**
     * Strip all C0 control bytes (\x00–\x1f) and C1 (\x80–\x9f)
     * except \n (\x0a) and \t (\x09), and remove every escape sequence
     * (CSI/OSC/SGR) introduced by \x1b.
     *
     * Use this on any string that originates from an external process,
     * network response, or user-controlled source before writing to the
     * terminal. Unlike {@see controlChars()}, this does NOT preserve SGR —
     * plain-text sinks render no color, so a full strip is correct.
     *
     * @param string $s Untrusted input string
     * @return string Sanitized string safe for terminal output
     */
    public static function untrusted(string $s): string
    {
        // First strip all ANSI escape sequences (SGR, CSI, OSC, etc.)
        // using Ansi::strip which is already used in the monorepo.
        $stripped = Ansi::strip($s);

        // Step 1: Strip C0 control bytes with /u flag (ASCII range 0x00-0x1f).
        // Since these are all single-byte ASCII characters, they can never be
        // part of a valid multi-byte UTF-8 character, so matching with /u is
        // safe and does not corrupt UTF-8 sequences.
        // Preserved: TAB (0x09), LF (0x0a), CR (0x0d).
        // Dropped: NUL..BS (0x00-0x08), VT (0x0b), FF (0x0c),
        //          SO..US (0x0e-0x1f), DEL (0x7f).
        $noC0 = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $stripped) ?? $stripped;

        // Step 2: Strip LONE C1 control bytes (0x80-0x9f).
        //
        // A byte in 0x80-0x9f is a valid UTF-8 continuation byte ONLY when
        // it belongs to a multi-byte character whose leading byte precedes it.
        // If it appears after an ASCII byte or at the start of the string, it
        // is a LONE (malformed) C1 byte and must be stripped.
        //
        // To determine "lone": look back through any chain of continuation bytes
        // (0x80-0xBF). If the first non-continuation byte is a valid UTF-8
        // leading byte (0xC0-0xDF, 0xE0-0xEF, or 0xF0-0xF7), the byte is part
        // of a valid UTF-8 sequence and is preserved. If the first
        // non-continuation byte is ASCII (0x00-0x7F) or there is none, the byte
        // is a lone C1 control byte and is stripped.
        //
        // This approach preserves all valid UTF-8 multi-byte sequences while
        // catching genuinely malformed C1 bytes (e.g. lone 0x80 from injection).
        $len = strlen($noC0);
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $b = ord($noC0[$i]);
            if ($b >= 0x80 && $b <= 0x9f) {
                // This byte is in the C1 range. Determine if it is a lone byte
                // by scanning backward for the first non-continuation byte.
                $j = $i - 1;
                while ($j >= 0) {
                    $prev = ord($noC0[$j]);
                    if ($prev >= 0x80 && $prev <= 0xbf) {
                        $j--; // continue scanning backward through continuation bytes
                        continue;
                    }
                    // Found a non-continuation byte or reached start of string
                    break;
                }
                // $j is now -1 (no predecessor) or points to a non-continuation byte
                if ($j >= 0) {
                    $first = ord($noC0[$j]);
                    // Valid UTF-8 leading bytes: 0xC0-0xDF (2-byte), 0xE0-0xEF (3-byte),
                    // 0xF0-0xF7 (4-byte). Any other value (ASCII 0x00-0x7F or lone C1
                    // 0x80-0x9F) means the current byte is lone C1 → strip it.
                    if ($first >= 0xc0 && $first <= 0xf7) {
                        $result .= $noC0[$i]; // valid UTF-8 continuation — keep
                        continue;
                    }
                }
                // Lone C1 byte (no predecessor, or predecessor is ASCII/lone-C1) → strip
                continue;
            }
            $result .= $noC0[$i];
        }

        return $result;
    }
}
