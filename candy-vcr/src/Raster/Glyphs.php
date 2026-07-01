<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Theme;

/**
 * Pre-rendered character tile cache.
 *
 * The single biggest performance lever: a typical terminal frame has
 * thousands of cells but only ~50 unique (char, attrs) combinations.
 * Caching tiles converts rasterization cost from O(cells) to O(unique tiles).
 *
 * Colors are resolved through the configured {@see Theme} so user-selected
 * themes reach the rendered GIF.
 *
 * Mirrors charmbracelet/x/vhs Glyphs cache.
 */
final class Glyphs
{
    /** @var array<string, \GdImage> */
    private array $cache = [];

    /** @var \SplQueue<string> FIFO queue for O(1) eviction order */
    private \SplQueue $evictionQueue;

    private const MAX_CACHE_TILES = 4096;

    private int $fontSize;

    private Theme $theme;

    /** @var array<string, string> */
    private array $fontPathCache = [];

    private int $hits = 0;

    private int $misses = 0;

    private int $evictions = 0;

    public function __construct(
        private int $cellW,
        private int $cellH,
        private FontLoader $fonts,
        private string $fontFamily = 'JetBrainsMono',
        int $fontSize = 14,
        ?Theme $theme = null,
    ) {
        $this->fontSize = $fontSize;
        $this->theme = $theme ?? new Theme();
        $this->evictionQueue = new \SplQueue();
    }

    /**
     * @return array{hits:int, misses:int, evictions:int}
     */
    public function cacheStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'evictions' => $this->evictions];
    }

    public function cellWidth(): int
    {
        return $this->cellW;
    }

    public function cellHeight(): int
    {
        return $this->cellH;
    }

    public function fontFamily(): string
    {
        return $this->fontFamily;
    }

    public function fontSize(): int
    {
        return $this->fontSize;
    }

    public function theme(): Theme
    {
        return $this->theme;
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
            $this->hits++;
            return $this->cache[$key];
        }

        $this->misses++;
        $this->evictIfNeeded();
        $tile = $this->renderTile($char, $fg, $bg, $bold, $italic, $underline, $this->cellW);
        $this->cache[$key] = $tile;
        $this->evictionQueue->enqueue($key);

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
            $this->hits++;
            return $this->cache[$key];
        }

        $this->misses++;
        $this->evictIfNeeded();
        $wideW = $this->cellW * 2;
        $tile = $this->renderTile($char, $fg, $bg, $bold, $italic, $underline, $wideW);
        $this->cache[$key] = $tile;
        $this->evictionQueue->enqueue($key);

        return $tile;
    }

    /**
     * Evict the oldest cache entry if the cache has reached the size limit.
     * Uses SplQueue for O(1) FIFO eviction order.
     */
    private function evictIfNeeded(): void
    {
        if (count($this->cache) >= self::MAX_CACHE_TILES) {
            $oldestKey = $this->evictionQueue->dequeue();
            if ($oldestKey !== null && isset($this->cache[$oldestKey])) {
                $oldImage = $this->cache[$oldestKey];
                unset($this->cache[$oldestKey]);
                imagedestroy($oldImage);
                $this->evictions++;
            }
        }
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
            // Bold is handled by resolving a -Bold font face via FontLoader::resolve()
            // rather than synthetic emboldening via imagettftext's bold parameter.
            // The $bold parameter is passed through to the font resolution path
            // (style='bold') and the resulting -Bold face is used directly.
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
        $rgb = $this->theme->color($paletteIndex);
        $r = ($rgb >> 16) & 0xff;
        $g = ($rgb >> 8) & 0xff;
        $b = $rgb & 0xff;
        $color = imagecolorallocate($image, $r, $g, $b);
        return $color !== false ? $color : 0;
    }
}
