<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

/**
 * SizeHinter is implemented by widgets that can report their size preferences
 * given available space. The layout engine calls sizeHint during resolution
 * for Fit-constrained and minSizeFit children.
 *
 * Axis contract (finding sugar-dashboard-041): the budgets are per-AXIS, not
 * per-priority - a horizontal container passes its main-axis remaining space
 * in $availWidth and the cross-axis allocation in $availHeight, a vertical
 * container the other way round. Where the engine cannot know a budget it
 * passes 0 (unbounded from the hinter's point of view), never the wrong
 * axis's number.
 *
 * Mirrors tealeaves tealayout_sizehinter.go SizeHinter interface.
 */
interface SizeHinter
{
    public function sizeHint(int $availWidth, int $availHeight): SizeHint;
}
