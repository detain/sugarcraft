<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Util;

/**
 * Strips C0 control bytes and bare ESC bytes from untrusted display strings.
 * Preserves TAB (\x09) and LF (\x0A), valid UTF-8 text, and ANSI SGR
 * sequences (ESC + '[' + params + 'm').
 *
 * C1 control bytes (0x80-0x9F) are NOT stripped because they overlap with
 * UTF-8 continuation byte range and stripping them from valid UTF-8 text
 * would corrupt multi-byte characters.
 *
 * Prevents malicious filenames / option strings from injecting terminal
 * control sequences (screen clears, cursor moves, SGR/text effects) into
 * the rendered output.
 *
 * Mirrors the sanitisation applied in upstream bubbletea's Viewport for
 * externally-sourced content.
 */
final class RenderSafe
{
    /**
     * Strip dangerous control bytes from an untrusted string.
     *
     * Pass 1 — C0 (except TAB/LF) + DEL:
     *   0x00–0x08, 0x0B, 0x0C, 0x0E–0x1A, 0x1C–0x1F, 0x7F
     *
     * Pass 2 — bare ESC not part of an SGR sequence:
     *   Strip bare ESC bytes; SGR sequences (\x1b[...m) are kept intact.
     *   ESC (0x1B) is excluded from the C0 strip so SGR survives pass 1.
     *
     * @param string $s  Untrusted string (e.g. filename, user-supplied label)
     */
    public static function clean(string $s): string
    {
        // Pass 1: strip C0/DEL bytes (TAB and LF intentionally kept;
        // ESC 0x1B intentionally kept so SGR sequences survive this pass).
        $s = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1A\x1C-\x1F\x7F]/',
            '',
            $s,
        ) ?? $s;

        // Pass 2: strip bare ESC bytes; preserve SGR sequences.
        // Three alternatives:
        //   1. Full SGR sequence (\x1b[params]m)  → preserve intact
        //   2. Bare ESC + following byte           → strip ESC, keep next byte
        //   3. Bare ESC at end-of-string            → strip it
        return preg_replace_callback(
            '/(\x1b\[[^\x1b]*m)|(\x1b[^\x1b])|(\x1b)/',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    // Alt 1: SGR sequence — preserve it intact.
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
