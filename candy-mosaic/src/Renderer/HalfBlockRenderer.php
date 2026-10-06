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
 * (GD alpha 127 → `null` in the PixelGrid cell tuple), that half of the
 * cell shows the terminal default. Both-transparent cells emit a plain
 * space. When only one half is opaque, the renderer paints just that
 * half using the half-block whose FOREGROUND fills the needed side:
 * a top-transparent cell emits ▄ (U+2584, fg paints its LOWER half)
 * with the bottom colour as foreground; a bottom-transparent cell emits
 * ▀ (U+2580, fg paints its UPPER half) with the top colour as
 * foreground. In both cases the other colour layer is omitted entirely.
 */
final class HalfBlockRenderer implements Renderer
{
    use \SugarCraft\Mosaic\Concerns\RenderValidationTrait;

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

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
                    // Both transparent: a plain space keeps the cell blank
                    // (a bare ▀ would still print the terminal default fg in
                    // the upper half, showing a stripe over the backdrop).
                    $line .= ' ';
                } elseif ($topTransparent) {
                    // Top pixel transparent, bottom opaque: ▄ (U+2584) whose
                    // FOREGROUND fills the LOWER half, so the bottom colour
                    // rides fg and the upper half stays terminal default.
                    $line .= Ansi::fgRgb($botR, $botG, $botB)
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
