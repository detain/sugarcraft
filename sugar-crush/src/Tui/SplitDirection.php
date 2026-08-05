<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Split direction for the in-process split pane renderer.
 *
 * Mirrors tmux/iTerm2 split conventions but rendered in-process
 * without external multiplexer dependencies.
 */
enum SplitDirection: string
{
    /** Horizontal split: top and bottom panes. */
    case Horizontal = 'horizontal';

    /** Vertical split: left and right panes. */
    case Vertical = 'vertical';

    /**
     * Returns the divider character(s) for this split direction.
     *
     * Horizontal uses a full-width line across columns.
     * Vertical uses a single pipe character.
     */
    public function divider(): string
    {
        return match ($this) {
            self::Horizontal => "\u{2500}", // BOX DRAWINGS LIGHT HORIZONTAL
            self::Vertical => "\u{2502}",   // BOX DRAWINGS LIGHT VERTICAL
        };
    }

    /**
     * Returns true if this direction splits along the vertical axis (columns).
     */
    public function isVertical(): bool
    {
        return $this === self::Vertical;
    }

    /**
     * Returns true if this direction splits along the horizontal axis (rows).
     */
    public function isHorizontal(): bool
    {
        return $this === self::Horizontal;
    }
}
