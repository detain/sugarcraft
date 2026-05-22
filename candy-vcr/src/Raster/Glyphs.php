<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

/**
 * Pre-rendered character tile cache.
 *
 * The single biggest performance lever: a typical terminal frame has
 * thousands of cells but only ~50 unique (char, attrs) combinations.
 * Caching tiles converts rasterization cost from O(cells) to O(unique tiles).
 *
 * Mirrors charmbracelet/x/vhs Glyphs cache.
 */
final class Glyphs
{
    /** @var array<string, \GdImage> */
    private array $cache = [];

    private int $fontSize;

    /** @var array<string, string> */
    private array $fontPathCache = [];

    public function __construct(
        private int $cellW,
        private int $cellH,
        private FontLoader $fonts,
        private string $fontFamily = 'JetBrainsMono',
        int $fontSize = 14,
    ) {
        $this->fontSize = $fontSize;
    }

    /**
     * Get or create a cached tile for (char, fg, bg, bold, italic, underline).
     *
     * @return \GdImage pre-rendered tile at cell dimensions
     */
    public function tile(
        string $char,
        int $fg,
        int $bg,
        bool $bold,
        bool $italic,
        bool $underline,
    ): \GdImage {
        $key = $this->cacheKey($char, $fg, $bg, $bold, $italic, $underline);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $tile = $this->renderTile($char, $fg, $bg, $bold, $italic, $underline, $this->cellW);
        $this->cache[$key] = $tile;

        return $tile;
    }

    /**
     * Get or create a cached wide-character tile (2x cell width).
     *
     * @return \GdImage pre-rendered tile at 2×cell dimensions
     */
    public function tileWide(
        string $char,
        int $fg,
        int $bg,
        bool $bold,
        bool $italic,
        bool $underline,
    ): \GdImage {
        $key = $this->cacheKey($char, $fg, $bg, $bold, $italic, $underline) . ':wide';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $wideW = $this->cellW * 2;
        $tile = $this->renderTile($char, $fg, $bg, $bold, $italic, $underline, $wideW);
        $this->cache[$key] = $tile;

        return $tile;
    }

    /**
     * @return array{0:int, 1:int}
     */
    public function measure(string $char): array
    {
        $isWide = mb_strwidth($char) > 1;
        return $isWide ? [$this->cellW * 2, $this->cellH] : [$this->cellW, $this->cellH];
    }

    private function cacheKey(string $char, int $fg, int $bg, bool $bold, bool $italic, bool $underline): string
    {
        return "{$char}|{$fg}|{$bg}|" . ($bold ? '1' : '0') . '|' . ($italic ? '1' : '0') . '|' . ($underline ? '1' : '0');
    }

    private function renderTile(
        string $char,
        int $fg,
        int $bg,
        bool $bold,
        bool $italic,
        bool $underline,
        int $width,
    ): \GdImage {
        \assert($width >= 1 && $this->cellH >= 1);
        $tile = imagecreatetruecolor($width, $this->cellH);
        if ($tile === false) {
            throw new \RuntimeException('Failed to create tile image');
        }

        imagesavealpha($tile, true);
        imagealphablending($tile, false);

        $bgColor = $this->allocateColor($tile, $bg);
        $fgColor = $this->allocateColor($tile, $fg);

        imagefilledrectangle($tile, 0, 0, $width - 1, $this->cellH - 1, $bgColor);

        $style = $bold ? 'bold' : 'regular';
        if ($italic) {
            $style = 'italic';
        }

        $fontPath = $this->resolveFontPath($style);

        $angle = $italic ? -10.0 : 0.0;
        $yOffset = (int) floor($this->cellH * 0.85);

        $xOffset = 1;
        if ($width !== $this->cellW) {
            $xOffset = (int) floor(($width - $this->cellW) / 2) + 1;
        }

        if ($fontPath !== null) {
            $boldInt = $bold ? 1 : 0;
            imagettftext($tile, (float) $this->fontSize, $angle, $xOffset, $yOffset, $fgColor, $fontPath, $char);
        }

        if ($underline) {
            $underlineY = (int) floor($this->cellH * 0.75);
            imageline($tile, 0, $underlineY, $width - 1, $underlineY, $fgColor);
        }

        return $tile;
    }

    private function resolveFontPath(string $style): ?string
    {
        if (isset($this->fontPathCache[$style])) {
            return $this->fontPathCache[$style];
        }

        try {
            $path = $this->fonts->load($this->fontFamily, (float) $this->fontSize, $style);
            $this->fontPathCache[$style] = $path;
            return $path;
        } catch (\RuntimeException) {
            foreach (['DejaVuSansMono', 'FreeMono', 'NotoSansMono'] as $fallback) {
                try {
                    $path = $this->fonts->load($fallback, (float) $this->fontSize, $style);
                    $this->fontPathCache[$style] = $path;
                    return $path;
                } catch (\RuntimeException) {
                }
            }
        }

        return null;
    }

    private function allocateColor(\GdImage $image, int $paletteIndex): int
    {
        $rgb = $this->indexToRgb($paletteIndex);
        $r = max(0, min(255, $rgb[0]));
        $g = max(0, min(255, $rgb[1]));
        $b = max(0, min(255, $rgb[2]));
        $color = imagecolorallocate($image, $r, $g, $b);
        return $color !== false ? $color : 0;
    }

    /**
     * @return array{0:int, 1:int, 2:int}
     */
    private function indexToRgb(int $index): array
    {
        if ($index < 0 || $index > 255) {
            return [0, 0, 0];
        }

        if ($index < 16) {
            return ($this->defaultPalette()[$index] ?? [0, 0, 0]);
        }

        if ($index < 232) {
            $adjusted = $index - 16;
            $r = (int) floor($adjusted / 36);
            $g = (int) floor(($adjusted % 36) / 6);
            $b = $adjusted % 6;
            return [
                $r ? $r * 40 + 55 : 0,
                $g ? $g * 40 + 55 : 0,
                $b ? $b * 40 + 55 : 0,
            ];
        }

        $gray = (int) floor(($index - 232) * 10 + 8);
        return [$gray, $gray, $gray];
    }

    /**
     * @return array<int, array{0:int, 1:int, 2:int}>
     */
    private function defaultPalette(): array
    {
        return [
            [0x00, 0x00, 0x00],
            [0x80, 0x00, 0x00],
            [0x00, 0x80, 0x00],
            [0x80, 0x80, 0x00],
            [0x00, 0x00, 0x80],
            [0x80, 0x00, 0x80],
            [0x00, 0x80, 0x80],
            [0xc0, 0xc0, 0xc0],
            [0x80, 0x80, 0x80],
            [0xff, 0x00, 0x00],
            [0x00, 0xff, 0x00],
            [0xff, 0xff, 0x00],
            [0x00, 0x00, 0xff],
            [0xff, 0x00, 0xff],
            [0x00, 0xff, 0xff],
            [0xff, 0xff, 0xff],
        ];
    }
}
