<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Internal;

use SugarCraft\Core\Util\Ansi;

/**
 * Neutralize terminal-control injection in caller-supplied display text.
 *
 * The single-line presenter primitives (StatusLine, Stage, Section, HelpText)
 * interpolate caller strings straight into ANSI output that a frame-diff
 * renderer (candy-core) paints onto a screen it owns. An embedded cursor move,
 * screen clear, OSC 52 clipboard write, DCS/APC payload, or a raw newline in
 * that text would desync the renderer's one-line-per-row model or drive a
 * terminal-escape injection — so it must be stripped before it reaches the
 * terminal (only Frame previously bounded caller text, and only by width).
 *
 * @internal Not part of the public API; may change without notice.
 */
final class SafeText
{
    /**
     * Strip escape sequences and control bytes from a one-line display string.
     *
     * {@see Ansi::strip()} removes every escape sequence in 7-bit and 8-bit
     * form — CSI / OSC / DCS / SOS / PM / APC payloads, string-terminator
     * controls, and lone C1 bytes; the second pass drops the remaining C0
     * control bytes (0x00-0x1f) and DEL (0x7f). Multi-byte UTF-8 survives
     * untouched (its C1-range continuation bytes sit inside fully
     * well-formed sequences), and clean
     * printable text is returned identical.
     */
    public static function line(string $s): string
    {
        $stripped = preg_replace('/[\x00-\x1f\x7f]/', '', Ansi::strip($s));

        if ($stripped === null) {
            // Unreachable for this pattern by construction — a single-pass
            // character-class substitution has nothing to backtrack over, so
            // neither pcre.backtrack_limit nor pcre.recursion_limit can be
            // exhausted by any input. Guarded because the alternatives to
            // throwing are worse: coalescing the null to an empty string (the
            // previous shape) reported a failed strip as clean empty text,
            // which a frame-diff renderer paints as a legitimately blank row,
            // and a bare `false` would surface as a TypeError far from the
            // cause. Fail loud at the boundary instead. (The literal that the
            // old expression used is deliberately not spelled out here:
            // SafeTextTest pins its absence from this method's source.)
            throw new \RuntimeException('SafeText::line(): C0/DEL strip failed: ' . preg_last_error_msg());
        }

        return $stripped;
    }

    /**
     * Strip escape sequences and control bytes from a multi-line page,
     * preserving the line structure.
     *
     * The counterpart of {@see line()} for authored pages (E453, round of
     * the crush_libs rerun): sugar-crush's translated `help` screen is one
     * catalogue string whose embedded newlines ARE the layout, so the
     * line-wise flattening in {@see line()} destroys it. Here every byte
     * {@see line()} would remove still goes — escape sequences via
     * {@see Ansi::strip()}, the remaining C0 controls and DEL — except the
     * line feed (0x0a), which survives so the page keeps its rows. Tab
     * (0x09) and carriage return (0x0d) are deliberately NOT exempt: tabs
     * render at terminal-dependent widths a cell-grid cannot account for,
     * and dropping CR means CRLF input normalizes to bare LF rows instead
     * of leaving a stray control at each row end.
     *
     * @internal For the page-preserving presenters (HelpText::renderPage).
     */
    public static function page(string $s): string
    {
        $stripped = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/', '', Ansi::strip($s));

        if ($stripped === null) {
            // Same fail-loud shape as {@see line()}: a single-pass
            // character-class substitution cannot exhaust the PCRE limits,
            // so null means a bug, and coalescing it to '' would report a
            // failed strip as a legitimately blank page.
            throw new \RuntimeException('SafeText::page(): C0/DEL strip failed: ' . preg_last_error_msg());
        }

        return $stripped;
    }
}
