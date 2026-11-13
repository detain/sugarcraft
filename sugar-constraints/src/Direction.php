<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

/**
 * Direction of a constraint-based layout split.
 *
 * Mirrors ratatui `Direction`.
 */
enum Direction
{
    case Horizontal;
    case Vertical;

    /**
     * The other axis of the split — the Dock vocabulary's {@see Dock\Side::other()}
     * shape, so a Direction can answer its own counterpart without callers
     * hard-coding the flip.
     */
    public function other(): self
    {
        return $this === self::Horizontal ? self::Vertical : self::Horizontal;
    }
}
