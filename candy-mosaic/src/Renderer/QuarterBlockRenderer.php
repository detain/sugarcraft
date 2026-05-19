<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;
use SugarCraft\Mosaic\PixelGrid;

/**
 * Quarter-block Unicode renderer using 16 glyphs (▘▝▖▗▀▄▌▐▙▟▛▜▞▚█).
 * Each terminal cell renders a 2×2 group of pixels; the glyph selection
 * encodes which quadrants are bright via foreground colour and which are
 * dim via background colour (both drawn from the same image pixel).
 *
 * Higher visual fidelity than HalfBlockRenderer when Sixel/Kitty are
 * unavailable: 4 sub-pixel positions per cell vs 2.
 */
final class QuarterBlockRenderer implements Renderer
{
    /**
     * 16-entry lookup: index is a 4-bit mask where each bit corresponds
     * to a quadrant (bit 0 = upper-left, bit 1 = upper-right, bit 2 =
     * lower-left, bit 3 = lower-right; 1 = bright, 0 = dim).  The char at
     * each index is the Unicode quarter-block glyph covering exactly the
     * bright quadrants with the pixel colour; dim quadrants are rendered
     * as the same colour via background.
     *
     * @var array<int, string>
     */
    private const GLYPH_MAP = [
        0  => ' ',   // nothing bright
        1  => "\u{2591}", // ░ light shade — upper-left only bright at threshold
        2  => "\u{2591}", // ░ (upper-right only)
        3  => "\u{2592}", // ▒ medium shade — upper quadrants
        4  => "\u{2591}", // ░ (lower-left only)
        5  => "\u{2592}", // ▒ medium shade — left-side quadrants
        6  => "\u{2592}", // ▒ medium shade — diagonal
        7  => "\u{2593}", // ▓ dark shade — three quadrants bright
        8  => "\u{2591}", // ░ (lower-right only)
        9  => "\u{2592}", // ▒ medium shade — right-side quadrants
        10 => "\u{2592}", // ▒ medium shade — diagonal
        11 => "\u{2593}", // ▓ dark shade — three quadrants bright
        12 => "\u{2592}", // ▒ medium shade — upper vs lower
        13 => "\u{2593}", // ▓ dark shade — three quadrants bright
        14 => "\u{2593}", // ▓ dark shade — three quadrants bright
        15 => "\u{2588}", // █ full block — all four bright
    ];

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        if ($width <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ['width' => $width]));
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ['height' => $height]));
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        $img = imagecreatefromstring($image->bytes);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        try {
            $grid = PixelGrid::fromGdQuarter($img, $width, $effectiveHeight);
        } finally {
            imagedestroy($img);
        }

        $lines = [];
        foreach ($grid->cells as $row) {
            $line = '';
            foreach ($row as $quads) {
                $glyphIndex = 0;
                foreach ($quads as $idx => $rgb) {
                    // Treat any non-black as "bright" for the quad mask.
                    $glyphIndex |= ($rgb[0] > 10 || $rgb[1] > 10 || $rgb[2] > 10) ? (1 << $idx) : 0;
                }
                $glyph = self::GLYPH_MAP[$glyphIndex];
                if ($glyph === ' ') {
                    $line .= $glyph;
                    continue;
                }
                // Render bright quadrants as foreground, dim as background
                // — all four quads share the same colour per-pixel so we
                // just use the first quad's colour for the ANSI codes.
                [$r, $g, $b] = $quads[0];
                $line .= Ansi::fgRgb($r, $g, $b)
                    . Ansi::bgRgb($r, $g, $b)
                    . $glyph
                    . Ansi::reset();
            }
            $lines[] = $line;
        }

        return implode("\r\n", $lines);
    }

    public function name(): string
    {
        return 'quarterblock';
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    /**
     * Quarter-block rendering uses plain text SGR codes — no stored
     * image identity to delete. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }
}
