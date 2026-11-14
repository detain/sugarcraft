<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Select;

use InvalidArgumentException;

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
     * Boundary rules, pinned by AutoPositionHelperTest (D-142): a tie
     * sends the overlay UP (space above >= space below); an anchor
     * overflowing the screen yields negative room below and still
     * chooses by the same comparison; a non-numeric anchor string is a
     * programmer error and throws instead of silently casting to 0.
     *
     * @param int|string $anchorRectY The Y coordinate of the anchor element (numeric string accepted for flexibility)
     * @param int $anchorHeight The height of the anchor element
     * @param int $availableHeight The total available height for the overlay
     */
    public static function vertical(int|string $anchorRectY, int $anchorHeight, int $availableHeight): OverlayPosition
    {
        if (is_string($anchorRectY)) {
            if (!is_numeric($anchorRectY)) {
                throw new InvalidArgumentException(
                    "anchor rect Y must be numeric, got '{$anchorRectY}'"
                );
            }
            $anchorRectY = (int) $anchorRectY;
        }

        $spaceAbove = $anchorRectY;
        $spaceBelow = $availableHeight - $anchorRectY - $anchorHeight;
        return $spaceAbove >= $spaceBelow ? OverlayPosition::Top : OverlayPosition::Bottom;
    }
}
