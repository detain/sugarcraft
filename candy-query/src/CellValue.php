<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Core\Util\Sanitize;

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
     *
     * Delegates to candy-core's canonical {@see Sanitize::cellValue()} — the
     * single source of truth shared with the sugar-table grid — so both grids
     * neutralize binary/ANSI payloads identically. This grid is always
     * single-line, so newlines collapse to the ↵ glyph ($preserveNewlines
     * stays false); the output is byte-for-byte the same as the private copy
     * this method used to carry.
     */
    public static function sanitize(string $s): string
    {
        return Sanitize::cellValue($s, false);
    }
}
