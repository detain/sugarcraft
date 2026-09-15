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
     * — CSI/OSC/SGR introduced by \x1b, the 8-bit C1 forms (\x9b CSI,
     * \x90 DCS, \x9d OSC, \x9f APC …), and string-sequence payloads
     * (DCS/SOS/PM/APC, sixel and Kitty included).
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
        // Step 1: remove every escape sequence in 7-bit and 8-bit form,
        // including DCS/APC/PM/SOS payloads and lone C1 controls. The
        // UTF-8-aware lone-C1 rule (a 0x80-0x9f byte counts as a control
        // only when it does not continue a lead byte 0xC0-0xF7) lives in
        // Ansi::strip() so the sanitizer and the width math cannot diverge.
        $stripped = Ansi::strip($s);

        // Step 2: strip the remaining C0 control bytes and DEL.
        // Preserved: TAB (0x09), LF (0x0a), CR (0x0d).
        // Dropped: NUL..BS (0x00-0x08), VT (0x0b), FF (0x0c),
        //          SO..US (0x0e-0x1f), DEL (0x7f).
        //
        // Byte-oriented on purpose (NO /u flag): the class matches only
        // ASCII bytes, which can never occur inside a well-formed UTF-8
        // multi-byte sequence, yet a /u pattern FAILS on invalid UTF-8 —
        // and the old `?? $stripped` fallback then let every C0 control
        // (BEL included) through. Fail-closed beats fallback-to-hostile.
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $stripped) ?? '';
    }
}
