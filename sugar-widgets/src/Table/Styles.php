<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Table;

use SugarCraft\Style\Style;

/**
 * Styles for {@see Table}: one slot per visible table element.
 * Defaults are no-op {@see Style} instances so existing snapshot
 * tests stay green; pass non-default styles to
 * {@see Table::withStyles()} to customise. Mirrors upstream
 * Bubbles' `table.Styles`.
 */
final class Styles
{
    public readonly Style $header;
    public readonly Style $cell;
    public readonly Style $selected;

    public function __construct(
        ?Style $header   = null,
        ?Style $cell     = null,
        ?Style $selected = null,
    ) {
        $noop = Style::new();
        $this->header   = $header   ?? $noop;
        $this->cell     = $cell     ?? $noop;
        $this->selected = $selected ?? $noop;
    }
}
