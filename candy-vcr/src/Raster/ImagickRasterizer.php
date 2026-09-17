<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Theme;

/**
 * Alternative rasterizer using ext-imagick.
 *
 * Provides better anti-aliasing than gd for text rendering. Each cell paints
 * with its exact 24-bit colour when SGR `38;2`/`48;2` supplied one, otherwise
 * the cell's palette slot resolved through the configured {@see Theme} (via
 * {@see CellColor}) so user-selected themes (TokyoNight, Dracula, etc.) reach
 * the GIF instead of the default VGA palette.
 *
 * Tile cache lives on the rasterizer instance so per-cell `\Imagick`
 * allocations are amortised across a tape's many snapshots — keyed on
 * the same shape as {@see Glyphs} but with the source `Imagick` cloned
 * before each `compositeImage()` call. Cache fingerprint matches the
 * GD rasterizer: (cellW, cellH, theme spl_object_id, fontFamily,
 * fontSize). `__destruct` releases the cached `Imagick` resources.
 *
 * Mirrors charmbracelet/x/vhs ImagickRasterizer.
 */
final class ImagickRasterizer implements Rasterizer
{
    private Theme $theme;

    /** @var array<string, \Imagick> */
    private array $tileCache = [];

    private ?string $tileCacheFingerprint = null;

    private int $hits = 0;

    private int $misses = 0;

    private bool $cacheDisabled = false;

    public function __construct(
        private int $fontSize = 14,
        private string $fontFamily = 'JetBrainsMono',
        ?Theme $theme = null,
    ) {
        $this->theme = $theme ?? new Theme();
    }

    public function __destruct()
    {
        $this->clearTileCache();
    }

    public function withTheme(Theme $theme): self
    {
        $clone = new self($this->fontSize, $this->fontFamily, $theme);
        $clone->cacheDisabled = $this->cacheDisabled;
        // Deep-clone tileCache so each instance has independent Imagick objects.
        // Without this, clearTileCache() on a clone would destroy tiles the
        // original instance is still using.
        foreach ($this->tileCache as $key => $tile) {
            $clone->tileCache[$key] = clone $tile;
        }
        $clone->tileCacheFingerprint = $this->tileCacheFingerprint;
        $clone->hits = $this->hits;
        $clone->misses = $this->misses;
        return $clone;
    }

    public function withFont(string $fontFamily, ?int $fontSize = null): self
    {
        $clone = new self(
            $fontSize ?? $this->fontSize,
            $fontFamily,
            $this->theme,
        );
        $clone->cacheDisabled = $this->cacheDisabled;
        // Deep-clone tileCache so each instance has independent Imagick objects.
        // Without this, clearTileCache() on a clone would destroy tiles the
        // original instance is still using.
        foreach ($this->tileCache as $key => $tile) {
            $clone->tileCache[$key] = clone $tile;
        }
        $clone->tileCacheFingerprint = $this->tileCacheFingerprint;
        $clone->hits = $this->hits;
        $clone->misses = $this->misses;
        return $clone;
    }

    /**
     * Toggle the persistent tile cache for benchmarking.
     */
    public function setCacheDisabled(bool $disabled): void
    {
        $this->cacheDisabled = $disabled;
        if ($disabled) {
            $this->clearTileCache();
        }
    }

    /**
     * @return array{hits:int, misses:int}
     */
    public function cacheStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    public function rasterize(Snapshot $snapshot, int $cellW, int $cellH, ?FontLoader $fonts = null, bool $renderCursor = true): \Imagick
    {
        $fonts ??= new FontLoader();
        $grid = $snapshot->grid;
        $cursor = $snapshot->cursor;
        $cols = $grid->cols;
        $rows = $grid->rows;

        $width = $cols * $cellW;
        $height = $rows * $cellH;

        $this->maybeInvalidateCache($cellW, $cellH);

        $imagick = new \Imagick();
        $imagick->newImage($width, $height, new \ImagickPixel($this->indexToHex($this->theme->defaultBg)));
        $imagick->setImageFormat('png');

        for ($row = 0; $row < $rows; $row++) {
            $col = 0;
            while ($col < $cols) {
                $cell = $grid->cell($row, $col);

                $isWide = $this->isWideChar($cell->char);

                if ($col + ($isWide ? 1 : 0) >= $cols) {
                    $col++;
                    continue;
                }

                $tile = $this->getTile($cell, $cellW, $cellH, $fonts, $isWide ? $cellW * 2 : $cellW, $isWide);
                $imagick->compositeImage($tile, \Imagick::COMPOSITE_OVER, $col * $cellW, $row * $cellH);

                $col += $isWide ? 2 : 1;
            }
        }

        if ($renderCursor && $cursor->visible) {
            $this->renderCursor($imagick, $cursor, $grid, $cellW, $cellH);
        }

        return $imagick;
    }

    private function maybeInvalidateCache(int $cellW, int $cellH): void
    {
        $fingerprint = $cellW . 'x' . $cellH . '|' . spl_object_id($this->theme) . '|' . $this->fontFamily . '|' . $this->fontSize;
        if ($this->tileCacheFingerprint !== $fingerprint) {
            $this->clearTileCache();
            $this->tileCacheFingerprint = $fingerprint;
        }
    }

    private function clearTileCache(): void
    {
        foreach ($this->tileCache as $tile) {
            try {
                $tile->clear();
            } catch (\ImagickException) {
                // already destroyed — ignore
            }
        }
        $this->tileCache = [];
    }

    private function getTile(Cell $cell, int $cellW, int $cellH, FontLoader $fonts, int $tileW, bool $isWide): \Imagick
    {
        $inverse = ($cell->attrs & Cell::ATTR_INVERSE) !== 0;
        $fgIdx = $inverse ? $cell->bg : $cell->fg;
        $bgIdx = $inverse ? $cell->fg : $cell->bg;
        // Exact 24-bit pen when the cell carries SGR 38;2/48;2, swapped like the
        // palette slots under inverse; null → theme palette (byte-identical old path).
        $fgRgb = CellColor::pack($inverse ? $cell->bgRgb() : $cell->fgRgb());
        $bgRgb = CellColor::pack($inverse ? $cell->fgRgb() : $cell->bgRgb());
        $bold = ($cell->attrs & Cell::ATTR_BOLD) !== 0;
        $italic = ($cell->attrs & Cell::ATTR_ITALIC) !== 0;
        $underline = ($cell->attrs & Cell::ATTR_UNDERLINE) !== 0;

        $key = $this->cacheKey($cell->char, $fgIdx, $bgIdx, $bold, $italic, $underline, $isWide, $fgRgb, $bgRgb);

        if (!$this->cacheDisabled && isset($this->tileCache[$key])) {
            $this->hits++;
            return clone $this->tileCache[$key];
        }

        $this->misses++;
        $tile = $this->renderCellTile($cell->char, $fgIdx, $bgIdx, $bold, $italic, $underline, $cellW, $cellH, $fonts, $tileW, $fgRgb, $bgRgb);

        if (!$this->cacheDisabled) {
            $this->tileCache[$key] = $tile;
            return clone $tile;
        }

        return $tile;
    }

    private function cacheKey(
        string $char,
        int $fg,
        int $bg,
        bool $bold,
        bool $italic,
        bool $underline,
        bool $wide,
        ?int $fgRgb = null,
        ?int $bgRgb = null,
    ): string {
        $key = $char . '|' . $fg . '|' . $bg . '|' . ($bold ? '1' : '0') . '|' . ($italic ? '1' : '0') . '|' . ($underline ? '1' : '0') . '|' . ($wide ? 'w' : 'n');
        // Palette-only cells append nothing, so their keys — and the cache
        // hit/miss census — stay byte-identical to before truecolour support.
        if ($fgRgb !== null || $bgRgb !== null) {
            $key .= '|tc' . ($fgRgb ?? '-') . ',' . ($bgRgb ?? '-');
        }

        return $key;
    }

    private function renderCellTile(
        string $char,
        int $fgIdx,
        int $bgIdx,
        bool $bold,
        bool $italic,
        bool $underline,
        int $cellW,
        int $cellH,
        FontLoader $fonts,
        int $tileW,
        ?int $fgRgb = null,
        ?int $bgRgb = null,
    ): \Imagick {
        $bgHex = $bgRgb !== null ? $this->hexFromRgb($bgRgb) : $this->indexToHex($bgIdx);
        $fgHex = $fgRgb !== null ? $this->hexFromRgb($fgRgb) : $this->indexToHex($fgIdx);

        $tile = new \Imagick();
        $tile->newImage($tileW, $cellH, new \ImagickPixel($bgHex));
        $tile->setImageFormat('png');

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($fgHex));

        if ($bold) {
            $draw->setFontWeight(700);
        } else {
            $draw->setFontWeight(400);
        }

        if ($italic) {
            $draw->setFontStyle(\Imagick::STYLE_ITALIC);
        } else {
            $draw->setFontStyle(\Imagick::STYLE_NORMAL);
        }

        $fontPath = $fonts->resolve($this->fontFamily, 'regular');
        if ($fontPath !== null) {
            $draw->setFont($fontPath);
        }

        $draw->setFontSize($this->fontSize);
        $draw->setTextAntialias(true);

        $xOffset = 1;
        if ($tileW !== $cellW) {
            $xOffset = (int) floor(($tileW - $cellW) / 2) + 1;
        }

        $draw->annotation($xOffset, (int) floor($cellH * 0.85), $char);

        if ($underline) {
            $underlineY = (int) floor($cellH * 0.75);
            $draw2 = new \ImagickDraw();
            $draw2->setFillColor(new \ImagickPixel($fgHex));
            $draw2->line(0, $underlineY, $tileW - 1, $underlineY);
            $tile->drawImage($draw2);
        }

        $tile->drawImage($draw);

        return $tile;
    }

    private function renderCursor(
        \Imagick $imagick,
        Cursor $cursor,
        Buffer $grid,
        int $cellW,
        int $cellH,
    ): void {
        $row = $cursor->row;
        $col = $cursor->col;

        if ($row < 0 || $row >= $grid->rows || $col < 0 || $col >= $grid->cols) {
            return;
        }

        $cell = $grid->cell($row, $col);
        $x = $col * $cellW;
        $y = $row * $cellH;

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($this->hexFromRgb($this->cursorRgb($cell))));

        match ($cursor->shape) {
            1 => $this->drawBlockCursor($draw, $x, $y, $cellW, $cellH),
            2 => $this->drawUnderlineCursor($draw, $x, $y, $cellW, $cellH),
            3 => $this->drawBarCursor($draw, $x, $y, $cellW, $cellH),
            default => $this->drawBlockCursor($draw, $x, $y, $cellW, $cellH),
        };

        $imagick->drawImage($draw);
    }

    /**
     * On-screen cursor colour as an exact 24-bit value, honouring SGR 38;2 on the
     * channel painted and preserving this rasterizer's own palette legacy: the
     * inverse case paints the background slot (unlike GdRasterizer, which paints
     * the foreground — a pre-existing cross-rasterizer difference kept intact).
     */
    private function cursorRgb(Cell $cell): int
    {
        if (($cell->attrs & Cell::ATTR_INVERSE) !== 0) {
            return CellColor::background($cell, $this->theme);
        }

        return CellColor::pack($cell->fgRgb())
            ?? $this->theme->color($cell->fg === 0 ? $this->theme->defaultFg : $cell->fg);
    }

    private function drawBlockCursor(\ImagickDraw $draw, int $x, int $y, int $w, int $h): void
    {
        $draw->rectangle($x, $y, $x + $w - 1, $y + $h - 1);
    }

    private function drawUnderlineCursor(\ImagickDraw $draw, int $x, int $y, int $w, int $h): void
    {
        $uy = $y + (int) floor($h * 0.75);
        $draw->rectangle($x, $uy - 1, $x + $w - 1, $uy + 1);
    }

    private function drawBarCursor(\ImagickDraw $draw, int $x, int $y, int $w, int $h): void
    {
        $bw = max(2, (int) floor($w * 0.15));
        $draw->rectangle($x, $y, $x + $bw - 1, $y + $h - 1);
    }

    private function isWideChar(string $char): bool
    {
        return mb_strwidth($char) > 1;
    }

    private function indexToHex(int $index): string
    {
        return $this->hexFromRgb($this->theme->color($index));
    }

    /** Format an exact `0xRRGGBB` value as an Imagick `#rrggbb` colour string. */
    private function hexFromRgb(int $rgb): string
    {
        return sprintf('#%06x', $rgb & 0xffffff);
    }
}
