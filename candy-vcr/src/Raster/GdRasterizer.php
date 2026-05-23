<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Theme;

/**
 * Default rasterizer using ext-gd.
 *
 * Creates a pixel image from a terminal Snapshot by blitting
 * pre-rendered glyph tiles onto a canvas. The cell colors (0-255 palette
 * indices in {@see Cell}) are resolved through the configured {@see Theme}
 * so user-selected themes like TokyoNight or Dracula reach the GIF.
 *
 * Mirrors charmbracelet/x/vhs GdRasterizer.
 */
final class GdRasterizer implements Rasterizer
{
    private const DEFAULT_FONT_SIZE = 14;
    private const DEFAULT_FONT_FAMILY = 'JetBrainsMono';

    private Theme $theme;

    public function __construct(
        private int $fontSize = self::DEFAULT_FONT_SIZE,
        private string $fontFamily = self::DEFAULT_FONT_FAMILY,
        ?Theme $theme = null,
    ) {
        $this->theme = $theme ?? new Theme();
    }

    public function withTheme(Theme $theme): self
    {
        return new self($this->fontSize, $this->fontFamily, $theme);
    }

    public function rasterize(Snapshot $snapshot, int $cellW, int $cellH, ?FontLoader $fonts = null): \GdImage
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

        $glyphs = new Glyphs($cellW, $cellH, $fonts, $this->fontFamily, $this->fontSize, $this->theme);

        for ($row = 0; $row < $rows; $row++) {
            $col = 0;
            while ($col < $cols) {
                $cell = $grid->get($row, $col);

                $isWide = $this->isWideChar($cell->char);

                if ($col + ($isWide ? 1 : 0) >= $cols) {
                    $col++;
                    continue;
                }

                $style = $this->styleFromAttrs($cell->attrs);
                $inverse = ($cell->attrs & Cell::ATTR_INVERSE) !== 0;
                $fg = $inverse ? $cell->bg : $cell->fg;
                $bg = $inverse ? $cell->fg : $cell->bg;

                if ($isWide) {
                    $tile = $glyphs->tileWide($cell->char, $fg, $bg, $style['bold'], $style['italic'], $style['underline']);
                } else {
                    $tile = $glyphs->tile($cell->char, $fg, $bg, $style['bold'], $style['italic'], $style['underline']);
                }

                $dx = $col * $cellW;
                $dy = $row * $cellH;
                imagecopy($canvas, $tile, $dx, $dy, 0, 0, imagesx($tile), imagesy($tile));

                $col += $isWide ? 2 : 1;
            }
        }

        if ($cursor->visible) {
            $this->renderCursor($canvas, $cursor, $grid, $cellW, $cellH, $glyphs);
        }

        return $canvas;
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
        CellGrid $grid,
        int $cellW,
        int $cellH,
        Glyphs $glyphs,
    ): void {
        $row = $cursor->row;
        $col = $cursor->col;

        if ($row < 0 || $row >= $grid->rows || $col < 0 || $col >= $grid->cols) {
            return;
        }

        $cell = $grid->get($row, $col);
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
        $tile = $glyphs->tile($cell->char, $cell->bg, $cell->fg, $style['bold'], $style['italic'], $style['underline']);
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
        $color = $this->allocateColor($canvas, $this->cursorColor($cell));
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
        $color = $this->allocateColor($canvas, $this->cursorColor($cell));
        $bw = max(2, (int) floor($cellW * 0.15));
        imagefilledrectangle($canvas, $x, $y, $x + $bw - 1, $y + $cellH - 1, $color);
    }

    private function cursorColor(Cell $cell): int
    {
        if (($cell->attrs & Cell::ATTR_INVERSE) !== 0) {
            return $cell->fg;
        }
        return $cell->fg === 0 ? $this->theme->defaultFg : $cell->fg;
    }

    private function isWideChar(string $char): bool
    {
        return mb_strwidth($char) > 1;
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
