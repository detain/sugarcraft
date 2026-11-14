<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

use SugarCraft\Core\Util\Ansi;

/**
 * Neutralize terminal-control injection in caller-supplied display text.
 *
 * The single-line presenter primitives (StatusLine, Stage, Section, HelpText)
 * interpolate caller strings straight into ANSI output that a frame-diff
 * renderer (sugar-core) paints onto a screen it owns. An embedded cursor move,
 * screen clear, OSC 52 clipboard write, DCS/APC payload, or a raw newline in
 * that text would desync the renderer's one-line-per-row model or drive a
 * terminal-escape injection — so it must be stripped before it reaches the
 * terminal. {@see Frame} and {@see Logo} accept pre-rendered strings and are
 * sanitized by the {@see styledLine()} and {@see page()} counterparts below.
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
     * controls, and lone C1 bytes; then the horizontal/vertical whitespace
     * controls (HT/LF/VT/FF/CR, 0x09-0x0d) each become ONE space — they are
     * word boundaries, and deleting them outright fuses words into a bogus
     * compound ("line one\nline two" must not read "lineonelinetwo") — and
     * the remaining C0 control bytes (0x00-0x08, 0x0e-0x1f) and DEL (0x7f)
     * are dropped. Multi-byte UTF-8 survives untouched (its C1-range
     * continuation bytes sit inside fully well-formed sequences), and clean
     * printable text is returned identical.
     */
    public static function line(string $s): string
    {
        // Fast path (finding 030): plain printable text is the overwhelming
        // majority of ingress. If no byte below 0x20, no DEL and no 8-bit byte
        // appears, there is no ESC introducer to strip, no control run to fold
        // and no C0/DEL to delete — the pipeline below is a provable identity
        // — so one byte-class scan replaces three passes. Any UTF-8 text (bytes
        // ≥ 0x80) and every dirty string take the full path unchanged; a false
        // preg_match (impossible for this pattern, but not assumed) also falls
        // through to it.
        if (preg_match('/[\x00-\x1f\x7f\x80-\xff]/', $s) !== 1) {
            return $s;
        }

        $whitespaced = preg_replace('/[\x09-\x0d]+/', ' ', Ansi::strip($s));

        if ($whitespaced === null) {
            // Unreachable for these patterns by construction — a single-pass
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
            throw new \RuntimeException('SafeText::line(): whitespace fold failed: ' . preg_last_error_msg());
        }

        $stripped = preg_replace('/[\x00-\x08\x0e-\x1f\x7f]/', '', $whitespaced);

        if ($stripped === null) {
            throw new \RuntimeException('SafeText::line(): C0/DEL strip failed: ' . preg_last_error_msg());
        }

        return $stripped;
    }

    /**
     * Sanitize a one-line display string that may legitimately carry SGR
     * styling, stripping everything else {@see line()} would remove.
     *
     * Frame's title/status/body are documented pre-rendered: host apps pass
     * strings built by sugar-style (Style::render), whose colors ARE escape
     * sequences, so {@see line()} would flatten the very styling the caller
     * asked for. This variant keeps exactly the CSI-SGR subset — ESC `[`
     * parameter bytes (0x30-0x3b: digits, colon, semicolon) then `final  m` —
     * verbatim, and runs every other chunk through {@see line()}. The split
     * pattern requires the closing `m`, so a truncated or non-SGR sequence
     * (`ESC ] … OSC`, `ESC [ ? 2 5 l`, a lone ESC) lands inside an even chunk
     * and loses its ESC byte to the C0 strip; because no ESC can survive in
     * an even chunk, a kept SGR can never re-form a longer escape with
     * surrounding text, and the only ESC bytes in the result head a complete
     * SGR. Newlines go (folded to a word-boundary space by {@see line()}):
     * styled strings here are single-line cells, and a row count that
     * changes is exactly the frame-desync this class exists to prevent.
     */
    public static function styledLine(string $s): string
    {
        // PREG_SPLIT_DELIM_CAPTURE puts SGR sequences at odd indices; even
        // indices (including the leading chunk) are the untrusted remainder.
        $parts = preg_split('/(\x1b\[[\x30-\x3b]*m)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!\is_array($parts)) {
            // false (pattern error) and null (PCRE terminated mid-run) both land here.
            // Same fail-loud shape as {@see line()}: coalescing a failed split
            // to '' would report an unsanitized-looking blank cell instead of
            // a bug, and the fixed class-with-final here cannot backtrack —
            // null/false means PCRE was terminated, not that the input was dirty.
            throw new \RuntimeException('SafeText::styledLine(): SGR split failed: ' . preg_last_error_msg());
        }

        foreach ($parts as $i => $chunk) {
            if ($i % 2 === 0) {
                $parts[$i] = self::line($chunk);
            }
        }

        return implode('', $parts);
    }

    /**
     * Strip escape sequences and control bytes from a multi-line page,
     * preserving the line structure.
     *
     * The counterpart of {@see line()} for authored pages: candy-code's
     * translated `help` screen is one catalogue string whose embedded
     * newlines ARE the layout, so the
     * line-wise flattening in {@see line()} destroys it. Here every byte
     * {@see line()} would remove still goes — escape sequences via
     * {@see Ansi::strip()}, the remaining C0 controls and DEL — except the
     * line feed (0x0a), which survives so the page keeps its rows. Tab
     * (0x09) folds to a single space before that strip, matching the
     * control-run→space behaviour of {@see line()}: silently DELETING the
     * tab fused the words it separated (`clone\tfast` came out as
     * `clonefast`), so the fold keeps the word boundary while removing the
     * terminal-dependent width a cell-grid cannot account for. A run of
     * tabs folds to one space too. Carriage return (0x0d) stays dropped:
     * CRLF input normalizes to bare LF rows instead of leaving a stray
     * control at each row end.
     *
     * @internal For the page-preserving presenters (HelpText::renderPage).
     */
    public static function page(string $s): string
    {
        $tabbed = preg_replace('/\t+/', ' ', Ansi::strip($s));

        if ($tabbed === null) {
            // Unreachable by construction, same as the strip below — guarded
            // for the same fail-loud reason: a silent null here would drop
            // the fold and ship fused words downstream.
            throw new \RuntimeException('SafeText::page(): tab fold failed: ' . preg_last_error_msg());
        }

        $stripped = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/', '', $tabbed);

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
