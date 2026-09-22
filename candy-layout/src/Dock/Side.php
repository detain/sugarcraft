<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Dock;

/**
 * Which dock column a pane stack occupies, relative to the center surface.
 *
 * Original SugarCraft design (no upstream mirror) — the left/right pairing
 * exists so a Side can answer its own counterpart without callers hard-coding
 * the flip.
 */
enum Side
{
    case Left;
    case Right;

    /**
     * The opposite side of the dock.
     */
    public function other(): self
    {
        return $this === self::Left ? self::Right : self::Left;
    }
}
