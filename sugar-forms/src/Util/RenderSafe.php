<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Util;

/**
 * Strips C0 control bytes and every ESC-introduced sequence *except* true
 * SGR from untrusted display strings.
 *
 * "True SGR" is narrowly defined: ESC '[' followed by ZERO OR MORE of the
 * digit and ';' bytes only, terminated by a literal 'm'
 * (`\x1b[[0-9;]*m` — e.g. `\x1b[m`, `\x1b[1m`, `\x1b[1;31m`, `\x1b[38;5;200m`).
 * Only these are preserved intact so that legitimate colour/text styling
 * survives.
 *
 * EVERY other ESC-introduced sequence has its ESC byte stripped, leaving the
 * (now harmless, printable) remainder behind. This deliberately covers:
 *   - CSI erase/cursor sequences: `\x1b[2J` (Erase-in-Display / screen clear),
 *     `\x1b[H` (cursor home), `\x1b[K` (erase-in-line), etc. — note these do
 *     NOT match the SGR pattern because 'J'/'H'/'K' are not digits/';'.
 *   - OSC sequences (`\x1b]…`), bare ESC, and any ESC + single following byte.
 *
 * Preserves TAB (\x09) and LF (\x0A) — and ONLY those two C0 bytes — and
 * valid UTF-8 text. CR (\x0D) is stripped: it falls in the C0 gap between
 * the kept bytes and forges display content (overwrite-back) on every
 * cleaned surface.
 *
 * C1 bytes (0x80-0x9F) inside VALID UTF-8 are never raw controls — every
 * byte in that range is a multi-byte continuation byte there — so they are
 * exempt from stripping. For INVALID UTF-8 (crafted dir names arrive as
 * raw bytes via scandir) the exemption would fail: a lone 0x9B is 8-bit
 * CSI in terminals that accept it. The sanitizer therefore normalizes
 * non-UTF-8 input through mb_convert_encoding first, so every surviving
 * 0x80-0xBF byte provably belongs to a legal sequence.
 *
 * Prevents untrusted content — FilePicker entries and paths, option
 * strings, labels and descriptions, validation-error messages, echoed
 * field values, and the accessible form view — from injecting terminal
 * control sequences (screen clears, cursor moves, OSC payloads) into the
 * rendered output.
 *
 * Mirrors the sanitisation applied in upstream bubbletea's Viewport for
 * externally-sourced content.
 */
final class RenderSafe
{
    /**
     * Strip dangerous control bytes from an untrusted string.
     *
     * Pass 0 — encoding normalization:
     *   strings that are not valid UTF-8 are converted through
     *   mb_convert_encoding() (invalid sequences replaced per the mbstring
     *   substitution policy) so the C1 exemption in the class doc holds
     *   provably: no raw 8-bit control byte survives outside a legal
     *   multi-byte sequence.
     *
     * Pass 1 — C0 (except TAB/LF) + DEL:
     *   0x00–0x08, 0x0B–0x0D, 0x0E–0x1A, 0x1C–0x1F, 0x7F
     *
     * Pass 2 — strip every ESC-introduced sequence except true SGR:
     *   True SGR (ESC '[' + digits/';' + 'm') is kept intact; every other
     *   ESC sequence (CSI erase/cursor `\x1b[…J/H/K`, OSC, bare ESC) has its
     *   ESC byte stripped. ESC (0x1B) is excluded from the C0 strip so SGR
     *   survives pass 1.
     *
     *   NOTE: alt-1 is anchored to `[0-9;]*` (NOT `[^\x1b]*`) so a dangerous
     *   CSI sequence that happens to be followed by a literal 'm' (e.g.
     *   `\x1b[2Jm`) is NOT misclassified as SGR — `[0-9;]*` stops at 'J', so
     *   alt-2 strips its ESC leaving the harmless printable `[2Jm`.
     *
     * @param string $s  Untrusted string (e.g. filename, label, error message)
     */
    public static function clean(string $s): string
    {
        // Pass 0: invalid UTF-8 carries raw 8-bit control bytes (lone 0x9B
        // == CSI on 8-bit-parsing terminals) that pass 1/2 cannot see as
        // controls. Normalize first; the C1 exemption is then sound.
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }

        // Pass 1: strip C0/DEL bytes (TAB and LF intentionally kept; CR is
        // NOT — display-forging; ESC 0x1B intentionally kept so SGR
        // sequences survive this pass).
        $s = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0D\x0E-\x1A\x1C-\x1F\x7F]/',
            '',
            $s,
        ) ?? $s;

        // Pass 2: strip every ESC sequence except true SGR.
        // Three alternatives:
        //   1. True SGR sequence (\x1b[[0-9;]*m)   → preserve intact
        //   2. Bare ESC + following byte           → strip ESC, keep next byte
        //   3. Bare ESC at end-of-string            → strip it
        return preg_replace_callback(
            '/(\x1b\[[0-9;]*m)|(\x1b[^\x1b])|(\x1b)/',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    // Alt 1: true SGR sequence (digits/';' then 'm') — keep intact.
                    return $m[1];
                }
                if (($m[2] ?? '') !== '') {
                    // Alt 2: bare ESC + following byte — strip ESC, keep the byte.
                    return $m[2][1] ?? '';
                }
                // Alt 3: lone bare ESC (e.g. at end of string) — strip it.
                return '';
            },
            $s,
        ) ?? $s;
    }
}
