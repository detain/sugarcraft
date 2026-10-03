<?php

declare(strict_types=1);

namespace SugarCraft\Veil;

/**
 * Overlay position constants.
 *
 * Matches rmhubbert/bubbletea-overlay Position values.
 */
enum Position
{
    case TOP;
    case RIGHT;
    case BOTTOM;
    case LEFT;
    case CENTER;
    case TOP_RIGHT;
    case BOTTOM_RIGHT;
    case BOTTOM_LEFT;
    case TOP_LEFT;

    /**
     * Resolve the vertical cell offset (rows from the top) for this position
     * given the foreground and background heights.
     */
    public function yOffset(int $fgHeight, int $bgHeight): int
    {
        return match ($this) {
            self::TOP,
            self::TOP_RIGHT,
            self::TOP_LEFT        => 0,
            self::BOTTOM,
            self::BOTTOM_RIGHT,
            self::BOTTOM_LEFT     => $bgHeight - $fgHeight,
            self::CENTER,
            self::LEFT,
            self::RIGHT           => (int) \floor(($bgHeight - $fgHeight) / 2),
        };
    }

    /**
     * Resolve the horizontal cell offset (columns from the left) for this position
     * given the foreground and background widths.
     */
    public function xOffset(int $fgWidth, int $bgWidth): int
    {
        return match ($this) {
            self::LEFT,
            self::TOP_LEFT,
            self::BOTTOM_LEFT     => 0,
            self::RIGHT,
            self::TOP_RIGHT,
            self::BOTTOM_RIGHT    => $bgWidth - $fgWidth,
            self::CENTER,
            self::TOP,
            self::BOTTOM          => (int) \floor(($bgWidth - $fgWidth) / 2),
        };
    }
}
