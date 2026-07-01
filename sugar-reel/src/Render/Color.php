<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * Color utility for packing RGB components into a single integer.
 *
 * Packs three 8-bit channels into a 24-bit integer using the formula:
 * (r << 16) | (g << 8) | b
 *
 * This is the standard 0xRRGGBB representation used by candy-buffer
 * and many terminal color systems.
 */
final class Color
{
    /**
     * Pack RGB components into a 0xRRGGBB integer.
     *
     * @param int $r Red component (0-255)
     * @param int $g Green component (0-255)
     * @param int $b Blue component (0-255)
     * @return int 0xRRGGBB packed color
     */
    public static function pack(int $r, int $g, int $b): int
    {
        return (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }
}
