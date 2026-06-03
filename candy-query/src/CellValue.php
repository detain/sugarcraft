<?php

declare(strict_types=1);

namespace SugarCraft\Query;

/**
 * Safe display formatting for arbitrary DB cell values.
 *
 * Database columns can hold binary BLOBs (geometry, encrypted payloads,
 * etc.). Rendering those bytes verbatim is catastrophic in a TUI: a raw
 * ESC (0x1b) injects a bogus escape sequence that desyncs the frame-diff
 * renderer's line model, NUL/control bytes garble the terminal, and BEL
 * makes it beep on every repaint.
 *
 * Shared by {@see Renderer} (the sugar-table rows grid) and
 * {@see ResultTable} (the executed-query result grid) so both grids get
 * identical binary/ANSI safety from one place.
 */
final class CellValue
{
    /**
     * Turn an arbitrary cell value into a safe, single-line display string:
     * scalars cast, NULL labelled, arrays/objects JSON-encoded, then
     * {@see sanitize()}d.
     */
    public static function display(mixed $val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_scalar($val)) {
            $s = (string) $val;
        } else {
            $s = json_encode($val);
            if ($s === false) {
                $s = '';
            }
        }
        return self::sanitize($s);
    }

    /**
     * Strip everything that could corrupt the terminal from an already-
     * stringified value:
     *   1. repair invalid UTF-8 (binary data) so width/truncation stay sane,
     *   2. collapse newlines to a visible marker (no extra rows), and
     *   3. replace every other control byte (C0, DEL, C1) with a middle dot.
     */
    public static function sanitize(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $prev = mb_substitute_character();
            mb_substitute_character(0xFFFD); // U+FFFD REPLACEMENT CHARACTER
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            mb_substitute_character($prev);
        }

        // Visualize newlines so they can't break a cell into extra rows.
        $s = str_replace(["\r\n", "\r", "\n"], '↵', $s);
        // Strip dangerous control bytes: C0 (0x00-0x1F) + DEL (0x7F)…
        $s = preg_replace('/[\x00-\x1F\x7F]/', '·', $s) ?? $s;
        // …and the C1 control range (U+0080-U+009F), now valid UTF-8.
        $s = preg_replace('/[\x{0080}-\x{009F}]/u', '·', $s) ?? $s;

        return $s;
    }
}
