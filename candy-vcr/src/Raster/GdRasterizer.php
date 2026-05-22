<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Default rasterizer using ext-gd.
 *
 * Creates a pixel image from a terminal Snapshot by blitting
 * pre-rendered glyph tiles onto a canvas.
 *
 * Mirrors charmbracelet/x/vhs GdRasterizer.
 */
final class GdRasterizer implements Rasterizer
{
    private const DEFAULT_FONT_SIZE = 14;
    private const DEFAULT_FONT_FAMILY = 'JetBrainsMono';

    public function __construct(
        private int $fontSize = self::DEFAULT_FONT_SIZE,
        private string $fontFamily = self::DEFAULT_FONT_FAMILY,
    ) {
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
        $canvas = imagecreatetruecolor((int) $width, (int) $height);
        if ($canvas === false) {
            throw new \RuntimeException('Failed to create canvas image');
        }

        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);

        $defaultBgColor = $this->allocateColor($canvas, 0);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $defaultBgColor);

        $glyphs = new Glyphs($cellW, $cellH, $fonts, $this->fontFamily, $this->fontSize);

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

                if ($isWide) {
                    $tile = $glyphs->tileWide($cell->char, $cell->fg, $cell->bg, $style['bold'], $style['italic'], $style['underline']);
                } else {
                    $tile = $glyphs->tile($cell->char, $cell->fg, $cell->bg, $style['bold'], $style['italic'], $style['underline']);
                }

                $dx = $col * $cellW;
                $dy = $row * $cellH;
                imagecopy($canvas, $tile, $dx, $dy, 0, 0, imagesx($tile), imagesy($tile));

                $col += $isWide ? 2 : 1;
            }
        }

        if ($cursor->visible) {
            $this->renderCursor($canvas, $cursor, $grid, $cellW, $cellH, $glyphs, $fonts);
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
        FontLoader $fonts,
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
        $cursorColor = $this->cursorColor($cell);
        $color = $this->allocateColor($canvas, $cursorColor);
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
        $cursorColor = $this->cursorColor($cell);
        $color = $this->allocateColor($canvas, $cursorColor);
        $bw = max(2, (int) floor($cellW * 0.15));
        imagefilledrectangle($canvas, $x, $y, $x + $bw - 1, $y + $cellH - 1, $color);
    }

    private function cursorColor(Cell $cell): int
    {
        if (($cell->attrs & Cell::ATTR_INVERSE) !== 0) {
            return $cell->fg;
        }
        return $cell->fg === 0 ? 15 : $cell->fg;
    }

    private function isWideChar(string $char): bool
    {
        return mb_strwidth($char) > 1;
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
            return $this->defaultPalette()[$index] ?? [0, 0, 0];
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
