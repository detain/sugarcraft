<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Theme;

/**
 * Hex colour normalisation shared by the theme loaders.
 *
 * VS Code and chroma themes both ship #rgb/#rrggbb/#rrggbbaa values; the
 * loaders used to carry byte-identical private copies of the expansion
 * logic, which drifted-invisible the moment one side learned a new format.
 * One implementation, two callers.
 */
trait NormalizesHexColor
{
    /**
     * Normalize a hex colour to 6-digit lowercase #rrggbb.
     *
     * Handles 3-digit #rgb and 8-digit #rrggbbaa formats.
     */
    private static function normalizeHex(string $hex): string
    {
        $hex = trim($hex);
        if (str_starts_with($hex, '#')) {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }
        return '#' . strtolower($hex);
    }

    /**
     * Accept only what a renderer can actually paint: a 6-digit hex colour.
     * Anything else (CSS names, half-written values, junk) returns null so
     * the caller falls back to its documented default instead of carrying
     * garbage into an SVG `fill` or a GD colour allocation.
     */
    private static function isValidHexColor(string $normalized): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/', $normalized) === 1;
    }
}
