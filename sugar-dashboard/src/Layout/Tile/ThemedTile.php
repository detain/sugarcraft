<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

use SugarCraft\Dashboard\Foundation\Theme;

/**
 * A tile that knows how to re-theme itself, preserving its own type.
 *
 * This is the fan-down contract {@see TileLayout::withTheme()} relies on:
 * a layout never rebuilds a child it does not fully understand. Doing so
 * used to downgrade custom tiles to a bare {@see Tile} — name, size and
 * subclass state lost — and hard-fatalled on a plain {@see BaseTile},
 * which carries no content() to read.
 *
 * Implementors return a themed sibling of the SAME static type; the
 * default {@see BaseTile} implementation is identity because the base
 * carries no themeable content.
 */
interface ThemedTile
{
    public function withTheme(Theme $theme): static;
}
