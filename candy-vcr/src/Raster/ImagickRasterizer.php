<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Theme;

/**
 * Alternative rasterizer using ext-imagick.
 *
 * Provides better anti-aliasing than gd for text rendering. Colors are
 * resolved through the configured {@see Theme} so user-selected themes
 * (TokyoNight, Dracula, etc.) reach the GIF instead of the default VGA
 * palette.
 *
 * Mirrors charmbracelet/x/vhs ImagickRasterizer.
 */
final class ImagickRasterizer implements Rasterizer
{
    private Theme $theme;

    public function __construct(
        private int $fontSize = 14,
        private string $fontFamily = 'JetBrainsMono',
        ?Theme $theme = null,
    ) {
        $this->theme = $theme ?? new Theme();
    }

    public function withTheme(Theme $theme): self
    {
        return new self($this->fontSize, $this->fontFamily, $theme);
    }

    public function rasterize(Snapshot $snapshot, int $cellW, int $cellH, ?FontLoader $fonts = null): \Imagick
    {
        $fonts ??= new FontLoader();
        $grid = $snapshot->grid;
        $cursor = $snapshot->cursor;
        $cols = $grid->cols;
        $rows = $grid->rows;

        $width = $cols * $cellW;
        $height = $rows * $cellH;

        $imagick = new \Imagick();
        $imagick->newImage($width, $height, new \ImagickPixel($this->indexToHex($this->theme->defaultBg)));
        $imagick->setImageFormat('png');

        for ($row = 0; $row < $rows; $row++) {
            $col = 0;
            while ($col < $cols) {
                $cell = $grid->get($row, $col);

                $isWide = $this->isWideChar($cell->char);

                if ($col + ($isWide ? 1 : 0) >= $cols) {
                    $col++;
                    continue;
                }

                $tile = $this->renderCellTile($cell, $cellW, $cellH, $fonts, $isWide ? $cellW * 2 : $cellW);
                $imagick->compositeImage($tile, \Imagick::COMPOSITE_OVER, $col * $cellW, $row * $cellH);
                $tile->clear();

                $col += $isWide ? 2 : 1;
            }
        }

        if ($cursor->visible) {
            $this->renderCursor($imagick, $cursor, $grid, $cellW, $cellH);
        }

        return $imagick;
    }

    private function renderCellTile(Cell $cell, int $cellW, int $cellH, FontLoader $fonts, int $tileW): \Imagick
    {
        $inverse = ($cell->attrs & Cell::ATTR_INVERSE) !== 0;
        $fgIdx = $inverse ? $cell->bg : $cell->fg;
        $bgIdx = $inverse ? $cell->fg : $cell->bg;

        $tile = new \Imagick();
        $tile->newImage($tileW, $cellH, new \ImagickPixel($this->indexToHex($bgIdx)));
        $tile->setImageFormat('png');

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($this->indexToHex($fgIdx)));

        if (($cell->attrs & Cell::ATTR_BOLD) !== 0) {
            $draw->setFontWeight(700);
        } else {
            $draw->setFontWeight(400);
        }

        if (($cell->attrs & Cell::ATTR_ITALIC) !== 0) {
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

        $draw->annotation($xOffset, (int) floor($cellH * 0.85), $cell->char);

        if (($cell->attrs & Cell::ATTR_UNDERLINE) !== 0) {
            $underlineY = (int) floor($cellH * 0.75);
            $draw2 = new \ImagickDraw();
            $draw2->setFillColor(new \ImagickPixel($this->indexToHex($fgIdx)));
            $draw2->line(0, $underlineY, $tileW - 1, $underlineY);
            $tile->drawImage($draw2);
        }

        $tile->drawImage($draw);

        return $tile;
    }

    private function renderCursor(
        \Imagick $imagick,
        Cursor $cursor,
        CellGrid $grid,
        int $cellW,
        int $cellH,
    ): void {
        $row = $cursor->row;
        $col = $cursor->col;

        if ($row < 0 || $row >= $grid->rows || $col < 0 || $col >= $grid->cols) {
            return;
        }

        $cell = $grid->get($row, $col);
        $x = $col * $cellW;
        $y = $row * $cellH;

        $cursorIdx = (($cell->attrs & Cell::ATTR_INVERSE) !== 0)
            ? $cell->bg
            : ($cell->fg === 0 ? $this->theme->defaultFg : $cell->fg);

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($this->indexToHex($cursorIdx)));

        match ($cursor->shape) {
            1 => $this->drawBlockCursor($draw, $x, $y, $cellW, $cellH),
            2 => $this->drawUnderlineCursor($draw, $x, $y, $cellW, $cellH),
            3 => $this->drawBarCursor($draw, $x, $y, $cellW, $cellH),
            default => $this->drawBlockCursor($draw, $x, $y, $cellW, $cellH),
        };

        $imagick->drawImage($draw);
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
        $rgb = $this->theme->color($index);
        return sprintf('#%06x', $rgb & 0xffffff);
    }
}
