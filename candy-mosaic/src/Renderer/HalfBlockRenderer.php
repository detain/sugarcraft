<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;
use SugarCraft\Mosaic\PixelGrid;

/**
 * Universal fallback renderer using Unicode half-block (▀) with 24-bit
 * foreground + background SGR codes. Each terminal cell shows two
 * vertically-stacked pixel rows: the upper pixel's colour as foreground,
 * the lower pixel's colour as background, rendered as one ▀ glyph.
 *
 * Works in any terminal supporting truecolour. The visual effect is
 * near-square pixels because ▀ occupies roughly the top half of the
 * cell height, and the doubled vertical resolution compensates for the
 * cell aspect ratio.
 *
 * **Transparent pixels:** When a pixel's alpha is fully transparent
 * (GD alpha 127 → `null` in the PixelGrid cell tuple), no SGR codes are
 * emitted for that half of the cell, letting the terminal default show
 * through. Both-transparent cells emit ▀ with no SGR at all. For a cell
 * where only one half is transparent the renderer emits the opposite
 * half-block glyph (▄ for bottom-transparent, ▀ for top-transparent)
 * with only the visible half's SGR codes.
 */
final class HalfBlockRenderer implements Renderer
{
    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        if ($width <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ['width' => $width]));
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ['height' => $height]));
        }

        // Resolve height from aspect ratio when not given.
        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        // Load the GD image.
        $img = imagecreatefromstring($image->bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        try {
            $grid = PixelGrid::fromGd($img, $width, $effectiveHeight);
        } finally {
            imagedestroy($img);
        }

        $lines = [];
        foreach ($grid->cells as $row) {
            $line = '';
            foreach ($row as $pairs) {
                [$topR, $topG, $topB, $topA] = $pairs[0];
                [$botR, $botG, $botB, $botA] = $pairs[1];
                $topTransparent = ($topA === null);
                $botTransparent = ($botA === null);
                if ($topTransparent && $botTransparent) {
                    // Both transparent: emit upper-half block with no SGR,
                    // leaving the cell blank (terminal default background).
                    $line .= "\u{2580}";
                } elseif ($topTransparent) {
                    // Top pixel transparent, bottom opaque: show bottom half
                    // only via lower-half block (▄) with bg color.
                    $line .= Ansi::bgRgb($botR, $botG, $botB)
                        . "\u{2584}"
                        . Ansi::reset();
                } elseif ($botTransparent) {
                    // Bottom pixel transparent, top opaque: show top half
                    // only via upper-half block (▀) with fg color.
                    $line .= Ansi::fgRgb($topR, $topG, $topB)
                        . "\u{2580}"
                        . Ansi::reset();
                } else {
                    // Both opaque: standard half-block rendering
                    $line .= Ansi::fgRgb($topR, $topG, $topB)
                        . Ansi::bgRgb($botR, $botG, $botB)
                        . "\u{2580}"
                        . Ansi::reset();
                }
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public function name(): string
    {
        return 'halfblock';
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    public function isInline(): bool
    {
        return true;
    }

    /**
     * Half-block rendering uses plain text SGR codes — no stored image
     * identity to delete. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }
}
