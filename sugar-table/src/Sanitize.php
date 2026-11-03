<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Sanitize cell and header values before they reach the terminal buffer.
 *
 * Three canonical policies from {@see \SugarCraft\Core\Util\Sanitize} are
 * composed at this single ingress, in this order:
 *
 * 1. {@see \SugarCraft\Core\Util\Sanitize::cellValue()} — the control-byte
 *    sweep shared with the candy-sql grid: newlines collapse to ↵ (U+21B5)
 *    outside multiline paths, TAB and every other C0/DEL byte become ·
 *    (U+00B7), C1 codepoints are replaced, and invalid UTF-8 is repaired to
 *    U+FFFD rather than silently dropped. This kills CSI/OSC injection.
 * 2. {@see \SugarCraft\Core\Util\Sanitize::stripZoneSentinels()} — the
 *    sugar-mouse zone sentinels (U+E000/U+E001) are well-formed text the
 *    control sweep cannot see. The table marks no zones of its own, but a
 *    host that zones *around* the table (or consumes its output into a
 *    zone-marked frame) would register an attacker-forged click target from
 *    a forged pair surviving cell data, column titles, the missing-value
 *    indicator, or expanded rows. Sweeping at ingress keeps that class of
 *    forgery out of every render path at once. Other Private Use codepoints
 *    (image markers U+E002-and-up, Nerd Font glyphs) pass by design —
 *    they carry no zone meaning.
 * 3. {@see \SugarCraft\Core\Util\Sanitize::markInvisibleFormatting()} —
 *    Trojan-Source bidi overrides (U+202E and kin) and zero-width space are
 *    rendered as visible {@code <U+XXXX>} markers instead of silently
 *    reordering or blanking the line a human reads.
 */
final class Sanitize
{
    /**
     * Sanitize a string value for safe terminal rendering.
     *
     * @param string $s                The raw string to sanitize
     * @param bool   $preserveNewlines When true, preserve \n (for multiline expand
     *                                  paths that explode on "\n"); when false,
     *                                  collapse all newline variants to ↵ (U+21B5)
     * @return string Sanitized string safe for the terminal
     */
    public static function value(string $s, bool $preserveNewlines = false): string
    {
        $s = \SugarCraft\Core\Util\Sanitize::cellValue($s, $preserveNewlines);
        $s = \SugarCraft\Core\Util\Sanitize::stripZoneSentinels($s);

        return \SugarCraft\Core\Util\Sanitize::markInvisibleFormatting($s);
    }
}
