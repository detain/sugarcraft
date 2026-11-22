<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

/**
 * How a width-bounded {@see Tabs} bar picks the first tab of its scroll
 * window ({@see Tabs::withScrollWindow()}).
 *
 * Original SugarCraft work: upstream bubbles has no tab bar, so there is
 * no upstream counterpart to mirror.
 */
enum ScrollWindow
{
    /**
     * The default: the window keeps its stored offset and slides by the
     * smallest amount that reveals the active tab, so the window a bar
     * shows depends on how it got there (and {@see Tabs::withScrollOffset()}
     * pins it).
     */
    case Offset;

    /**
     * Scan from the first tab and show the first run of tabs that keeps the
     * active tab in view: the offset is the smallest one whose window still
     * keeps the active tab's first cells in view (the active tab is drawn
     * whole only when it fits the bar), recomputed from the active tab on every
     * change, so the same active tab always shows the same window. A stored
     * or pinned offset is ignored.
     */
    case FirstKeepingActive;
}
