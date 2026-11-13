<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Table;

use SugarCraft\Style\Style;

/**
 * Styles for {@see Table}: one slot per visible table element.
 *
 * Each unset slot falls back to the SAME decoration the unstyled
 * (`styles === null`) render applies — underline for the header, reverse
 * for the selected row — so supplying a PARTIAL Styles (e.g. only a bold
 * header) no longer silently deletes the cursor/header affordance (M6;
 * before, every unset slot defaulted to a no-op Style and the selection
 * highlight vanished the moment any style object was passed).
 * {@see Table::withStyles()} carries them; pass `null` to drop the object
 * entirely (same bytes via the fallback path). Mirrors upstream Bubbles'
 * `table.Styles`, whose unset fields keep the default renderer's look.
 */
final class Styles
{
    public readonly Style $header;
    public readonly Style $cell;
    public readonly Style $selected;
    /**
     * Muted decoration for the cursor row while the table is BLURRED
     * (dim by default, never the loud reverse — the inverted highlight
     * stays the focused signal, W-15/017).
     */
    public readonly Style $unfocusedSelection;

    public function __construct(
        ?Style $header   = null,
        ?Style $cell     = null,
        ?Style $selected = null,
        ?Style $unfocusedSelection = null,
    ) {
        $this->header   = $header   ?? Style::new()->underline();
        $this->cell     = $cell     ?? Style::new();
        $this->selected = $selected ?? Style::new()->reverse();
        $this->unfocusedSelection = $unfocusedSelection ?? Style::new()->dim();
    }
}
