<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Theme;

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
 * the same shape as {@see Glyphs}. A cache hit hands the cached
 * `Imagick` straight to `compositeImage()`: the composite is measured
 * (non-mutating on its argument — signature and pixels stable across
 * repeated no-op composites), so no per-cell clone is paid. Cache
 * fingerprint matches the GD rasterizer: (cellW, cellH, theme
 * spl_object_id, fontFamily, fontSize). `__destruct` releases the
 * cached `Imagick` resources.
 *
 * No upstream counterpart: charmbracelet/vhs screenshots a live ttyd with
 * Chromium and ffmpeg — it ships no cell-grid rasterizer. This .tape→GIF
 * pipeline is original to SugarCraft; its parity contract is the
 * charmbracelet/x/vt Cell grid it consumes.
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
        // Required even more now that cache hits hand out the stored instance
        // directly: without this, clearTileCache() on a derived instance would
        // clear tiles the original instance is still compositing.
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
        // Required even more now that cache hits hand out the stored instance
        // directly: without this, clearTileCache() on a derived instance would
        // clear tiles the original instance is still compositing.
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

                // Same cell-grid contract as GdRasterizer —
                // wide iff the neighbour is a Cell::continuation() tail of
                // this head, tails paint nothing. The old mb_strwidth oracle
                // was an independent width table and drifted from the vt
                // grid in both directions (regional-indicator pairs, Unicode
                // reclassifications); the grid is the ground truth for which
                // column a glyph occupies.
                if ($cell->continuation) {
                    $col++;
                    continue;
                }
                $isWide = $col + 1 < $cols && $grid->cell($row, $col + 1)->continuation;

                $tile = $this->getTile($cell, $cellW, $cellH, $fonts, $isWide ? $cellW * 2 : $cellW, $isWide);
                $imagick->compositeImage($tile, \Imagick::COMPOSITE_OVER, $col * $cellW, $row * $cellH);

                $col += $isWide ? 2 : 1;
            }
        }

        if ($renderCursor && $cursor->visible) {
            $this->renderCursor($imagick, $cursor, $grid, $cellW, $cellH, $fonts);
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
            // compositeImage() is argument-safe (measured: signature and pixel
            // bytes stable across repeated no-op composites), so a hit hands
            // out the cached instance itself — the per-cell clone that used to
            // sit here re-copied the pixel cache times the whole grid on every
            // single frame (finding 015).
            return $this->tileCache[$key];
        }

        $this->misses++;
        $tile = $this->renderCellTile($cell->char, $fgIdx, $bgIdx, $bold, $italic, $underline, $cellW, $cellH, $fonts, $tileW, $fgRgb, $bgRgb);

        if (!$this->cacheDisabled) {
            $this->tileCache[$key] = $tile;
            return $tile;
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
        // hit/miss census — stay byte-identical to before truecolor support.
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

        // VCR-S42: the face follows the cell's style bits exactly like the
        // GD path (Glyphs::renderTile). The tile cache key already carried
        // the bold/italic flags — resolving every one of them against the
        // regular face meant bold text was rendered as regular under two
        // different keys.
        $style = match (true) {
            $bold && $italic => 'bolditalic',
            $bold => 'bold',
            $italic => 'italic',
            default => 'regular',
        };

        $fontPath = $fonts->resolve($this->fontFamily, $style);
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
        FontLoader $fonts,
    ): void {
        $row = $cursor->row;
        $col = $cursor->col;

        if ($row < 0 || $row >= $grid->rows || $col < 0 || $col >= $grid->cols) {
            return;
        }

        $cell = $grid->cell($row, $col);
        $x = $col * $cellW;
        $y = $row * $cellH;

        // VCR-S42: the cursor sits on a wide-cell head — a 2-cell glyph's
        // head is followed by its continuation tail in the grid, and a real
        // terminal inverts the WHOLE wide cell. One-cell-wide paint used to
        // corrupt the head by covering only its left half.
        $isWide = $col + 1 < $grid->cols && $grid->cell($row, $col + 1)->continuation;
        $w = $isWide ? 2 * $cellW : $cellW;

        if ($cursor->shape !== 2 && $cursor->shape !== 3) {
            // Block (shape 1 and the default): reverse-video of the cell's
            // OWN glyph, matching GdRasterizer::renderBlockCursor — raw slot
            // swap, so a cursor parked on an inverse cell flips it back to
            // normal (xterm behaviour) instead of double-swapping through
            // the cache's inverse arm. The old solid rectangle hid the
            // glyph entirely.
            $bold = ($cell->attrs & Cell::ATTR_BOLD) !== 0;
            $italic = ($cell->attrs & Cell::ATTR_ITALIC) !== 0;
            $underline = ($cell->attrs & Cell::ATTR_UNDERLINE) !== 0;
            $tile = $this->renderCellTile(
                $cell->char,
                $cell->bg,
                $cell->fg,
                $bold,
                $italic,
                $underline,
                $cellW,
                $cellH,
                $fonts,
                $w,
                CellColor::pack($cell->bgRgb()),
                CellColor::pack($cell->fgRgb()),
            );
            $imagick->compositeImage($tile, \Imagick::COMPOSITE_OVER, $x, $y);
            $tile->clear();
            return;
        }

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($this->hexFromRgb($this->cursorRgb($cell))));

        if ($cursor->shape === 2) {
            $this->drawUnderlineCursor($draw, $x, $y, $w, $cellH);
        } else {
            $this->drawBarCursor($draw, $x, $y, $cellW, $cellH);
        }

        $imagick->drawImage($draw);
    }

    /**
     * On-screen cursor colour for the underline/bar shapes as an exact
     * 24-bit value, honouring SGR 38;2 on the channel painted. VCR-S42
     * unified the inverse-cell arm on the GD rasterizer's channel choice
     * (the foreground slot): the previous background-paint was a
     * cross-backend divergence, not a feature.
     */
    private function cursorRgb(Cell $cell): int
    {
        if (($cell->attrs & Cell::ATTR_INVERSE) !== 0) {
            return CellColor::foreground($cell, $this->theme);
        }

        return CellColor::pack($cell->fgRgb())
            ?? $this->theme->color($cell->fg === 0 ? $this->theme->defaultFg : $cell->fg);
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
