<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Sanitize;

/**
 * The sidebars' untrusted-text boundary: turns one model- or tool-authored
 * string into exactly one control-byte-free terminal row.
 *
 * {@see FilesPane} and {@see ToolsPane} both went from rendering only
 * operator-supplied text to rendering transcript content — a tool's `name`
 * and stderr, and a `file_path` the MODEL wrote — so they inherited the same
 * boundary {@see \SugarCraft\Crush\Renderer} has always applied to those
 * values. Two halves, and NEITHER alone is sufficient:
 *
 * - `Sanitize::untrusted()` strips ANSI escapes (including the printable
 *   parameter text a bare `\p{C}` sweep would leave as visible junk), NUL,
 *   BEL, DEL and lone C1 bytes — but it deliberately PRESERVES LF, CR and
 *   TAB, so a BEL is gone while a newline is not.
 * - Collapsing the surviving whitespace is what restores the one-row-per-
 *   entry invariant the panes budget against. An LF costs a row the pane
 *   never reserved, pushing the sidebar past `paneRows` so
 *   {@see \SugarCraft\Crush\Tui\Renderer}'s `clipHead()` cuts the box's own
 *   bottom border off; a lone CR returns the cursor to column 0, letting
 *   tool output overwrite the pane border and whatever else shares that
 *   physical row — damage the frame string itself gives no sign of.
 *
 * This mirrors what {@see \SugarCraft\Crush\Message::describeToolCall()}
 * already does, via its `singleLine()`, for the same class of value.
 */
final class PaneLabel
{
    /**
     * $raw as a single row: no escape sequences, no control bytes, no breaks.
     *
     * Applied BEFORE `Width::truncate()`, which happens to run `Ansi::strip()`
     * internally but is a width tool, not a security boundary — LF, CR, TAB,
     * BEL, NUL and DEL all survive it.
     */
    public static function of(string $raw): string
    {
        // \p{C} covers C0/C1 controls plus format code points such as bidi
        // overrides, catching anything untrusted() left behind by design.
        $flat = preg_replace('/[\p{C}\s]+/u', ' ', Sanitize::untrusted($raw));

        if ($flat === null) {
            // Invalid UTF-8 makes the /u pattern bail; sweep byte-wise instead
            // so malformed input can never smuggle a break into a frame.
            $flat = preg_replace('/[[:cntrl:][:space:]]+/', ' ', $raw) ?? '';
        }

        return trim($flat);
    }

    /**
     * {@see of()} plus a Private-Use sweep — the boundary for text that ends up
     * INSIDE a bordered pane rather than on a label row.
     *
     * `untrusted()` leaves the Private-Use block alone because it is printable,
     * and U+E000 is where candy-core's image markers AND candy-mouse's zone
     * sentinels both begin, so a model or tool that echoes one back forges a
     * clickable region (or a graphics placeholder) in our own frame. Every
     * pane that renders a live agent buffer wants both halves;
     * {@see AgentDashboardPane} and {@see AgentSplitColumn} each carried their
     * own copy of exactly this pair before it moved here.
     */
    public static function safe(string $raw): string
    {
        return self::of(self::stripPrivateUse($raw));
    }

    /** Drop every Private-Use code point (U+E000–U+F8FF and the astral planes). */
    private static function stripPrivateUse(string $raw): string
    {
        $stripped = preg_replace('/\p{Co}/u', '', $raw);
        if ($stripped !== null) {
            return $stripped;
        }

        // Invalid UTF-8 bails the /u pattern; sweep the BMP Private-Use area's
        // literal UTF-8 byte ranges instead so malformed output — which a
        // byte-tail cut can produce on its own — cannot smuggle a sentinel in.
        return (string) preg_replace(
            '/\xEE[\x80-\xBF][\x80-\xBF]|\xEF[\x80-\xA3][\x80-\xBF]/',
            '',
            $raw,
        );
    }
}
