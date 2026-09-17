<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Theme;

/**
 * Default rasterizer using ext-gd.
 *
 * Creates a pixel image from a terminal Snapshot by blitting
 * pre-rendered glyph tiles onto a canvas. Each cell paints with its exact
 * 24-bit colour when SGR `38;2`/`48;2` supplied one, otherwise the cell's 0-255
 * palette slot resolved through the configured {@see Theme} (via {@see CellColor})
 * so user-selected themes like TokyoNight or Dracula reach the GIF. The shared
 * {@see Glyphs} tile cache keys on the resolved colour so a truecolour cell never
 * collides with a palette cell of the same index.
 *
 * The {@see Glyphs} cache lives on the rasterizer instance so it persists
 * across snapshot calls — a typical tape rasterizes hundreds of snapshots
 * sharing the same ~50 unique (char, attrs) tuples, so per-frame caches
 * waste ~99% of the cache's value. The cache fingerprint is keyed on
 * (cellW, cellH, theme spl_object_id, fontFamily, fontSize); the
 * fingerprint changes invalidate and rebuild the cache.
 *
 * Mirrors charmbracelet/x/vhs GdRasterizer.
 */
final class GdRasterizer implements Rasterizer
{
    private const DEFAULT_FONT_SIZE = 14;
    private const DEFAULT_FONT_FAMILY = 'JetBrainsMono';

    private Theme $theme;

    private ?Glyphs $glyphs = null;

    private ?string $glyphsFingerprint = null;

    /**
     * When true, the cache is rebuilt on every {@see rasterize()} call.
     * Used by the benchmark harness to measure the cache's value.
     */
    private bool $cacheDisabled = false;

    public function __construct(
        private int $fontSize = self::DEFAULT_FONT_SIZE,
        private string $fontFamily = self::DEFAULT_FONT_FAMILY,
        ?Theme $theme = null,
    ) {
        $this->theme = $theme ?? new Theme();
    }

    public function withTheme(Theme $theme): self
    {
        $clone = new self($this->fontSize, $this->fontFamily, $theme);
        $clone->cacheDisabled = $this->cacheDisabled;
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
        return $clone;
    }

    /**
     * Toggle the persistent Glyphs cache for benchmarking.
     * Not part of the public Rasterizer contract — callers use this to
     * measure the cache's impact via {@see scripts/bench-glyph-cache.php}.
     */
    public function setCacheDisabled(bool $disabled): void
    {
        $this->cacheDisabled = $disabled;
        if ($disabled) {
            $this->glyphs = null;
            $this->glyphsFingerprint = null;
        }
    }

    /**
     * @return array{hits:int, misses:int}
     */
    public function cacheStats(): array
    {
        return $this->glyphs?->cacheStats() ?? ['hits' => 0, 'misses' => 0];
    }

    public function rasterize(Snapshot $snapshot, int $cellW, int $cellH, ?FontLoader $fonts = null, bool $renderCursor = true): \GdImage
    {
        $fonts ??= new FontLoader();
        $grid = $snapshot->grid;
        $cursor = $snapshot->cursor;
        $cols = $grid->cols;
        $rows = $grid->rows;

        $width = $cols * $cellW;
        $height = $rows * $cellH;

        \assert($width >= 1 && $height >= 1);
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new \RuntimeException('Failed to create canvas image');
        }

        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);

        $defaultBgColor = $this->allocateColor($canvas, $this->theme->defaultBg);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $defaultBgColor);

        $glyphs = $this->getGlyphs($cellW, $cellH, $fonts);

        for ($row = 0; $row < $rows; $row++) {
            $col = 0;
            while ($col < $cols) {
                $cell = $grid->cell($row, $col);

                $isWide = $this->isWideChar($cell->char);

                if ($col + ($isWide ? 1 : 0) >= $cols) {
                    $col++;
                    continue;
                }

                $style = $this->styleFromAttrs($cell->attrs);
                $inverse = ($cell->attrs & Cell::ATTR_INVERSE) !== 0;
                $fg = $inverse ? $cell->bg : $cell->fg;
                $bg = $inverse ? $cell->fg : $cell->bg;
                // Exact 24-bit pen when the cell carries SGR 38;2/48;2, swapped the
                // same way as the palette slots under inverse; null → theme palette.
                $fgRgb = CellColor::pack($inverse ? $cell->bgRgb() : $cell->fgRgb());
                $bgRgb = CellColor::pack($inverse ? $cell->fgRgb() : $cell->bgRgb());

                if ($isWide) {
                    $tile = $glyphs->tileWide($cell->char, $fg, $bg, $style['bold'], $style['italic'], $style['underline'], $fgRgb, $bgRgb);
                } else {
                    $tile = $glyphs->tile($cell->char, $fg, $bg, $style['bold'], $style['italic'], $style['underline'], $fgRgb, $bgRgb);
                }

                $dx = $col * $cellW;
                $dy = $row * $cellH;
                imagecopy($canvas, $tile, $dx, $dy, 0, 0, imagesx($tile), imagesy($tile));

                $col += $isWide ? 2 : 1;
            }
        }

        if ($renderCursor && $cursor->visible) {
            $this->renderCursor($canvas, $cursor, $grid, $cellW, $cellH, $glyphs);
        }

        return $canvas;
    }

    /**
     * Resolve a persistent Glyphs instance for the current fingerprint.
     *
     * Theme identity uses spl_object_id — themes are immutable value
     * objects with no mutating API, so two distinct instances with
     * identical palettes still rebuild the cache. The benchmark
     * confirms this is fine: a single tape run reuses one Theme.
     */
    private function getGlyphs(int $cellW, int $cellH, FontLoader $fonts): Glyphs
    {
        $fingerprint = $this->fingerprint($cellW, $cellH);

        if ($this->cacheDisabled || $this->glyphs === null || $this->glyphsFingerprint !== $fingerprint) {
            $this->glyphs = new Glyphs($cellW, $cellH, $fonts, $this->fontFamily, $this->fontSize, $this->theme);
            $this->glyphsFingerprint = $fingerprint;
        }

        return $this->glyphs;
    }

    private function fingerprint(int $cellW, int $cellH): string
    {
        return $cellW . 'x' . $cellH . '|' . spl_object_id($this->theme) . '|' . $this->fontFamily . '|' . $this->fontSize;
    }

    /**
     * @return array{bold:bool, italic:bool, underline:bool}
     */
    private function styleFromAttrs(int $attrs): array
    {
        return [
            'bold' => (bool) ($attrs & Cell::ATTR_BOLD),
            'italic' => (bool) ($attrs & Cell::ATTR_ITALIC),
            'underline' => (bool) ($attrs & Cell::ATTR_UNDERLINE),
        ];
    }

    private function renderCursor(
        \GdImage $canvas,
        Cursor $cursor,
        Buffer $grid,
        int $cellW,
        int $cellH,
        Glyphs $glyphs,
    ): void {
        $row = $cursor->row;
        $col = $cursor->col;

        if ($row < 0 || $row >= $grid->rows || $col < 0 || $col >= $grid->cols) {
            return;
        }

        $cell = $grid->cell($row, $col);
        $x = $col * $cellW;
        $y = $row * $cellH;

        match ($cursor->shape) {
            1 => $this->renderBlockCursor($canvas, $x, $y, $cellW, $cellH, $cell, $glyphs),
            2 => $this->renderUnderlineCursor($canvas, $x, $y, $cellW, $cellH, $cell),
            3 => $this->renderBarCursor($canvas, $x, $y, $cellW, $cellH, $cell),
            default => $this->renderBlockCursor($canvas, $x, $y, $cellW, $cellH, $cell, $glyphs),
        };
    }

    private function renderBlockCursor(
        \GdImage $canvas,
        int $x,
        int $y,
        int $cellW,
        int $cellH,
        Cell $cell,
        Glyphs $glyphs,
    ): void {
        $style = $this->styleFromAttrs($cell->attrs);
        // Block cursor paints the cell in reverse video — the tile's foreground is
        // the cell's background channel (and vice-versa), truecolour included.
        $tile = $glyphs->tile(
            $cell->char,
            $cell->bg,
            $cell->fg,
            $style['bold'],
            $style['italic'],
            $style['underline'],
            CellColor::pack($cell->bgRgb()),
            CellColor::pack($cell->fgRgb()),
        );
        imagecopy($canvas, $tile, $x, $y, 0, 0, imagesx($tile), imagesy($tile));
    }

    private function renderUnderlineCursor(
        \GdImage $canvas,
        int $x,
        int $y,
        int $cellW,
        int $cellH,
        Cell $cell,
    ): void {
        $color = $this->allocateRgb($canvas, $this->cursorRgb($cell));
        $uy = $y + (int) floor($cellH * 0.75);
        imagefilledrectangle($canvas, $x, $uy - 2, $x + $cellW - 1, $uy + 1, $color);
    }

    private function renderBarCursor(
        \GdImage $canvas,
        int $x,
        int $y,
        int $cellW,
        int $cellH,
        Cell $cell,
    ): void {
        $color = $this->allocateRgb($canvas, $this->cursorRgb($cell));
        $bw = max(2, (int) floor($cellW * 0.15));
        imagefilledrectangle($canvas, $x, $y, $x + $bw - 1, $y + $cellH - 1, $color);
    }

    /**
     * On-screen cursor colour as an exact 24-bit value: honour SGR 38;2 when the
     * channel the cursor paints carries it, else the theme palette with the legacy
     * "index 0 means default fg" fallback preserved for the palette path.
     */
    private function cursorRgb(Cell $cell): int
    {
        if (($cell->attrs & Cell::ATTR_INVERSE) !== 0) {
            return CellColor::foreground($cell, $this->theme);
        }

        return CellColor::pack($cell->fgRgb())
            ?? $this->theme->color($cell->fg === 0 ? $this->theme->defaultFg : $cell->fg);
    }

    private function isWideChar(string $char): bool
    {
        return mb_strwidth($char) > 1;
    }

    private function allocateColor(\GdImage $image, int $paletteIndex): int
    {
        return $this->allocateRgb($image, $this->theme->color($paletteIndex));
    }

    /** Allocate a GD colour directly from an exact `0xRRGGBB` value. */
    private function allocateRgb(\GdImage $image, int $rgb): int
    {
        $color = imagecolorallocate($image, ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);
        return $color !== false ? $color : 0;
    }
}
