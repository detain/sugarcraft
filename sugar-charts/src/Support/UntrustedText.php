<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

/**
 * Trust boundary for every string this library can echo to a terminal.
 *
 * Chart labels carry text the library does not own: series names and axis
 * labels assembled from application data (often read from files, network
 * payloads, or user input upstream), legend entries, titles, MarkLine
 * captions. Any of those strings can smuggle terminal control bytes —
 * ESC/CSI/OSC-8 hyperlinks, OSC 0 window-title writes, DCS/SOS/PM/APC
 * payloads, 7- and 8-bit C1, NUL/BEL, forged U+E000/U+E001 mouse-zone
 * sentinels, bidi-override characters such as U+202E, or undecodable
 * UTF-8. Because chart frames are line-composed grids, a surviving
 * newline or tab desynchronises the layout even when it is "benign"
 * input from the host itself. Left raw, one such string reaching a real
 * terminal is an escape-injection primitive.
 *
 * This is the single guard every text ingress passes through. Static
 * strings are swept at their setters; closure-produced ones (axis label
 * formatters, BarChart data-label formatters) are swept at the resolve
 * and render sites — CH-1 (docs-reviews-1104) closed the last two
 * sites that had cast formatter returns straight into label rows:
 *
 *  1. `Sanitize::untrustedForMarkedFrames()` deletes every escape-introduced
 *     sequence (both spellings), C0 controls except TAB/LF/CR, DEL, 8-bit
 *     C1, and the U+E000/U+E001 zone sentinels — the same sweep sugar-table
 *     applies at its cell ingress (ce84fb91a) and sugar-quickpick at its
 *     item ingress.
 *  2. Invalid UTF-8 is repaired to U+FFFD *before* the Unicode-aware
 *     passes, so a malformed byte can never make a `/u` pattern bail and
 *     let the rest of a hostile string through.
 *  3. `Sanitize::markInvisibleFormatting()` turns bidi overrides and
 *     zero-width characters into printable ASCII markers, so a label can't
 *     make the frame display text the bytes never contained.
 *  4. Finally TAB/LF/CR are flattened to spaces: every chart row is a
 *     single fixed-width line, so a surviving newline or tab (both
 *     tolerated by step 1 because other surfaces use them) would desync
 *     the composite regardless of who injected it.
 *
 * The guard is idempotent and byte-identical on ordinary printable
 * strings, so hosts that pass clean data see no change; styling applied
 * by the chart AFTER this guard (series SGR, legend colors) is the
 * library's own output and survives untouched.
 */
final class UntrustedText
{
    /** Cell budget for legend labels (M5, lib-reviews-1103). */
    public const MAX_LABEL_CELLS = 64;
    /**
     * Make `$value` safe to render inside a fixed-width chart frame.
     */
    public static function guard(string $value): string
    {
        $value = self::repairUtf8($value);
        $value = Sanitize::untrustedForMarkedFrames($value);
        $value = Sanitize::markInvisibleFormatting($value);

        return \str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
    }

    /**
     * Guard every `label` entry of a legend-item shaped list
     * (`list<array{label: string, color: string}>`). Colors are validated
     * separately at render time; only the human-readable text needs the
     * sweep.
     *
     * @param array<array-key, array{label: string, color?: string}> $items
     * @return array<array-key, array{label: string, color?: string}>
     */
    public static function guardItems(array $items): array
    {
        foreach ($items as $key => $item) {
            // M5 (lib-reviews-1103): labels also get a cell budget — the legend box is
            // composed against the chart width and an unbounded label breaks the side
            // panels just as surely as an escape sequence does. 64 cells stays far
            // above any legitimate series name.
            $items[$key]['label'] = Width::truncate(self::guard($item['label']), self::MAX_LABEL_CELLS);
        }

        return $items;
    }

    /**
     * Replace malformed UTF-8 with U+FFFD without relying on the process
     * default substitution mode (mirrors Sanitize's private repair).
     */
    private static function repairUtf8(string $value): string
    {
        $previous = \mb_substitute_character();
        \mb_substitute_character(0xFFFD);
        $repaired = \mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        \mb_substitute_character($previous);

        return $repaired;
    }
}
