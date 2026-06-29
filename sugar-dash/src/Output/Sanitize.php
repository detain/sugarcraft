<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Ansi;

/**
 * Terminal output sanitizer — strips dangerous control bytes and escape
 * sequences from untrusted strings before they reach the terminal.
 *
 * This class is the single authoritative sink for sanitizing module output.
 * Callers that need to preserve color (SGR sequences) must NOT use this;
 * module sinks render plain text so full strip is correct.
 *
 * Mirrors the lattice output sanitization pattern.
 */
final class Sanitize
{
    /**
     * Strip all C0 control bytes (\x00–\x1f) and C1 (\x80–\x9f)
     * except \n (\x0a) and \t (\x09), and remove every escape sequence
     * (CSI/OSC/SGR) introduced by \x1b.
     *
     * Use this on any string that originates from an external process,
     * network response, or user-controlled source before writing to the
     * terminal.
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
