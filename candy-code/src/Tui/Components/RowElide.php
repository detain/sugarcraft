<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Core\Util\Width;

/**
 * One-row text elision for the docked panes: a cut name says "there was more"
 * instead of ending mid-glyph as if that were the whole string.
 *
 * The M12 docs review counted six panes clipping their name rows through
 * `Width::truncate()` directly, which appends nothing — a list that reads
 * `config.json` next to a `config.json.bak` is an ambiguity, not a rendering.
 * Every pane row now answers through this one seam, so the marker is a
 * decision made once: the final cell belongs to the ellipsis, and only the
 * head before it is measured for fitting.
 *
 * This is deliberately NOT the dashboard's tinting helper: that one sanitises
 * untrusted text before cutting and keeps its own contract. A pane row here is
 * already trusted display text (`PaneLabel` has done its job upstream); this
 * seam only answers the width question.
 */
final class RowElide
{
    /** The marker a cut row ends with — U+2026, one cell in every supported font. */
    public const MARKER = "\u{2026}";

    /**
     * Render `$text` inside `$max` cells, replacing the cut tail with
     * {@see self::MARKER} when it does not fit.
     */
    public static function text(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }

        if (Width::string($text) <= $max) {
            return $text;
        }

        return Width::truncate($text, max(1, $max - 1)) . self::MARKER;
    }

    private function __construct()
    {
    }
}
