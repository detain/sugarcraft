<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Position;

use SugarCraft\Core\Util\Width;

/**
 * Auto-positioning helper for overlays.
 *
 * Calculates the optimal position for an overlay relative to an anchor,
 * automatically choosing above or below based on available space.
 *
 * Mirrors the teafields OverlayPosition pattern.
 */
final class Overlay
{
    /**
     * Position an overlay relative to an anchor.
     *
     * @param int $overlayWidth Width of the overlay
     * @param int $overlayHeight Height of the overlay
     * @param int $anchorX Anchor X position
     * @param int $anchorY Anchor Y position
     * @param int $containerWidth Total container width
     * @param int $containerHeight Total container height
     * @return array{x:int,y:int,position:string} Calculated position
     */
    public static function calculate(
        int $overlayWidth,
        int $overlayHeight,
        int $anchorX,
        int $anchorY,
        int $containerWidth,
        int $containerHeight,
    ): array {
        // Try below first
        $belowY = $anchorY + 1;
        $fitsBelow = ($belowY + $overlayHeight) <= $containerHeight;

        // Try above
        $aboveY = $anchorY - $overlayHeight;
        $fitsAbove = $aboveY >= 0;

        // Determine best horizontal position
        $x = self::calculateX($overlayWidth, $anchorX, $containerWidth);

        // Choose vertical position
        if ($fitsBelow) {
            return [
                'x' => $x,
                'y' => $belowY,
                'position' => 'below',
            ];
        }

        if ($fitsAbove) {
            return [
                'x' => $x,
                'y' => $aboveY,
                'position' => 'above',
            ];
        }

        // Fallback: center on anchor
        return [
            'x' => self::calculateX($overlayWidth, $anchorX, $containerWidth),
            'y' => max(0, (int) floor(($containerHeight - $overlayHeight) / 2)),
            'position' => 'center',
        ];
    }

    /**
     * Calculate horizontal position.
     */
    private static function calculateX(int $overlayWidth, int $anchorX, int $containerWidth): int
    {
        // Align left edge with anchor
        $x = $anchorX;

        // Clamp to container bounds
        if ($x + $overlayWidth > $containerWidth) {
            $x = max(0, $containerWidth - $overlayWidth);
        }

        return $x;
    }

    /**
     * Check if overlay would fit below anchor.
     */
    public static function fitsBelow(int $overlayHeight, int $anchorY, int $containerHeight): bool
    {
        return ($anchorY + 1 + $overlayHeight) <= $containerHeight;
    }

    /**
     * Check if overlay would fit above anchor.
     */
    public static function fitsAbove(int $overlayHeight, int $anchorY): bool
    {
        return ($anchorY - $overlayHeight) >= 0;
    }
}
