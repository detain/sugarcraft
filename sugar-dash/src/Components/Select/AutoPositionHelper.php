<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Select;

/**
 * Determines optimal overlay position based on available space.
 * Mirrors teafields position.go auto-positioning logic.
 */
final class AutoPositionHelper
{
    /**
     * Given anchorRect (y, height) and availableHeight,
     * returns OverlayPosition::Top or OverlayPosition::Bottom
     * depending on which has more room.
     *
     * @param string $anchorRectY The Y coordinate of the anchor element (as string for flexibility)
     * @param int $anchorHeight The height of the anchor element
     * @param int $availableHeight The total available height for the overlay
     */
    public static function vertical(string $anchorRectY, int $anchorHeight, int $availableHeight): OverlayPosition
    {
        $anchorY = (int) $anchorRectY;
        $spaceAbove = $anchorY;
        $spaceBelow = $availableHeight - $anchorY - $anchorHeight;
        return $spaceAbove >= $spaceBelow ? OverlayPosition::Top : OverlayPosition::Bottom;
    }
}
