<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Foundation;

/**
 * A box whose natural (chrome-inclusive) extent can be measured without
 * first being allocated.
 *
 * The zero-dims cure (finding sugar-dashboard-032): containers hand their
 * children setSize() with a raw 0 for any channel they do not budget, and
 * framed children read that 0 as "unallocated" — silently abandoning their
 * borders and rendering bare content. A container that knows a child's
 * natural extent can send a real allocation down the untaken channel
 * instead of the lying 0.
 *
 * This is deliberately NOT SizedItem::innerSize(): innerSize answers "how
 * much room does my content have inside my CURRENT allocation" (and answers
 * [0,0] when unallocated). naturalExtent answers "how much room would I
 * need" and includes the chrome the box itself paints (borders, title row,
 * padding).
 */
interface Measurable
{
    /**
     * Chrome-inclusive natural size.
     *
     * @param int $width the container's width channel; 0 means "the
     *                   container is not fixing a width" and the natural
     *                   content width (plus own chrome) is returned.
     * @return array{0:int,1:int} [width, height] in cells, >= 1 each
     */
    public function naturalExtent(int $width): array;
}
