<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

use SugarCraft\Core\Util\Width;

/**
 * Resolver for tile layout dimensions.
 */
final class Resolver
{
    /**
     * Calculate tile dimensions based on layout direction and available space.
     */
    public static function resolveTile(
        Tile $tile,
        Direction $direction,
        int $availableWidth,
        int $availableHeight,
        int $totalFixedWidth = 0,
        int $totalFixedHeight = 0,
    ): array {
        $size = $tile->getSize();

        if ($direction === Direction::Horizontal) {
            $tileWidth = self::decideWidth($size, $availableWidth, $totalFixedWidth);
            $tileHeight = self::decideHeight($size, $availableHeight);
            return [$tileWidth, $tileHeight];
        }

        $tileWidth = self::decideWidth($size, $availableWidth);
        $tileHeight = self::decideHeight($size, $availableHeight, $totalFixedHeight);
        return [$tileWidth, $tileHeight];
    }

    private static function decideWidth(Size $size, int $available, int $fixedSubtract = 0): int
    {
        $avail = $available - $fixedSubtract;

        if ($size->fixedWidth !== null) {
            return min($avail, $size->fixedWidth);
        }

        $w = $avail;
        if ($size->maxWidth !== null && $w > $size->maxWidth) {
            $w = $size->maxWidth;
        }
        if ($size->minWidth !== null && $w < $size->minWidth) {
            $w = $size->minWidth;
        }

        return max(0, $w);
    }

    private static function decideHeight(Size $size, int $available, int $fixedSubtract = 0): int
    {
        $avail = $available - $fixedSubtract;

        if ($size->fixedHeight !== null) {
            return min($avail, $size->fixedHeight);
        }

        $h = $avail;
        if ($size->maxHeight !== null && $h > $size->maxHeight) {
            $h = $size->maxHeight;
        }
        if ($size->minHeight !== null && $h < $size->minHeight) {
            $h = $size->minHeight;
        }

        return max(0, $h);
    }
}
