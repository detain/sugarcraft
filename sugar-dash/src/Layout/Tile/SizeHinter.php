<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * SizeHinter is implemented by widgets that can report their size preferences
 * given available space. The layout engine calls SizeHint during resolution
 * for Fit-constrained children.
 * Mirrors tealeaves tealayout_sizehinter.go SizeHinter interface.
 */
interface SizeHinter
{
    public function sizeHint(int $availWidth, int $availHeight): SizeHint;
}
