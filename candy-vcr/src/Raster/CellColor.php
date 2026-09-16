<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Theme;

/**
 * The single palette-vs-truecolour decision every rasterizer shares.
 *
 * A {@see Cell} carries two colour representations: a resolved 256-colour slot
 * in `fg`/`bg` and — when SGR `38;2`/`48;2` supplied one — an exact 24-bit value
 * reachable through {@see Cell::fgRgb()}/{@see Cell::bgRgb()}. Before this helper
 * the image rasterizers read only the palette slot, so a truecolour cell rendered
 * in its (often default) palette colour and the exact 24-bit value was silently
 * downgraded. These functions are the one authoritative answer to "which colour
 * do I paint", honouring truecolour first and falling back to the {@see Theme}
 * palette slot otherwise — deliberately pure and ext-free so it is unit-testable
 * without a GD or Imagick canvas (per the project's rasterizer test policy).
 *
 * Channel selection under {@see Cell::ATTR_INVERSE} stays with the caller: this
 * class maps ONE channel at a time, so the rasterizer picks which physical slot
 * (fg or bg) feeds the on-screen pen before calling.
 */
final class CellColor
{
    /**
     * Exact 24-bit colour of the cell's own foreground channel: the packed
     * SGR `38;2` value when present, otherwise the {@see Theme} palette slot.
     */
    public static function foreground(Cell $cell, Theme $theme): int
    {
        return self::pack($cell->fgRgb()) ?? $theme->color($cell->fg);
    }

    /**
     * Exact 24-bit colour of the cell's own background channel — the
     * {@see foreground()} twin for SGR `48;2`/the `bg` palette slot.
     */
    public static function background(Cell $cell, Theme $theme): int
    {
        return self::pack($cell->bgRgb()) ?? $theme->color($cell->bg);
    }

    /**
     * Pack a `[r, g, b]` triple (the shape {@see Cell::fgRgb()} returns) into a
     * `0xRRGGBB` int, passing a null triple straight through so callers can use
     * `?? $paletteFallback` to layer the truecolour-over-palette decision.
     *
     * @param array{0: int, 1: int, 2: int}|null $rgb
     */
    public static function pack(?array $rgb): ?int
    {
        if ($rgb === null) {
            return null;
        }

        return (($rgb[0] & 0xff) << 16) | (($rgb[1] & 0xff) << 8) | ($rgb[2] & 0xff);
    }
}
