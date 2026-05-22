<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Alternative rasterizer using ext-imagick.
 *
 * Provides better anti-aliasing than gd for text rendering.
 *
 * Mirrors charmbracelet/x/vhs ImagickRasterizer.
 */
final class ImagickRasterizer implements Rasterizer
{
    public function __construct(
        private int $fontSize = 14,
        private string $fontFamily = 'JetBrainsMono',
    ) {
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
        $imagick->newImage($width, $height, new \ImagickPixel('transparent'));
        $imagick->setImageFormat('png');
        $imagick->setImageBackgroundColor(new \ImagickPixel('transparent'));

        $tileImagick = new \Imagick();
        $tileImagick->newImage($cellW, $cellH, new \ImagickPixel('transparent'));
        $tileImagick->setImageFormat('png');

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

                if ($isWide) {
                    $col += 2;
                } else {
                    $col++;
                }
            }
        }

        if ($cursor->visible) {
            $this->renderCursor($imagick, $cursor, $grid, $cellW, $cellH);
        }

        return $imagick;
    }

    private function renderCellTile(Cell $cell, int $cellW, int $cellH, FontLoader $fonts, int $tileW): \Imagick
    {
        $tile = new \Imagick();
        $tile->newImage($tileW, $cellH, new \ImagickPixel($this->indexToHex($cell->bg)));
        $tile->setImageFormat('png');

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($this->indexToHex($cell->fg)));

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
            $draw2->setFillColor(new \ImagickPixel($this->indexToHex($cell->fg)));
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

        $cursorColor = ($cell->attrs & Cell::ATTR_INVERSE) !== 0
            ? $this->indexToHex($cell->fg)
            : $this->indexToHex($cell->fg);

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($cursorColor));

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
        if ($index < 0 || $index > 255) {
            return '#000000';
        }

        if ($index < 16) {
            return $this->defaultPalette()[$index] ?? '#000000';
        }

        if ($index < 232) {
            $adjusted = $index - 16;
            $r = (int) floor($adjusted / 36);
            $g = (int) floor(($adjusted % 36) / 6);
            $b = $adjusted % 6;
            $rs = dechex($r ? $r * 40 + 55 : 0);
            $gs = dechex($g ? $g * 40 + 55 : 0);
            $bs = dechex($b ? $b * 40 + 55 : 0);
            return '#' . str_pad($rs, 2, '0', STR_PAD_LEFT) . str_pad($gs, 2, '0', STR_PAD_LEFT) . str_pad($bs, 2, '0', STR_PAD_LEFT);
        }

        $gray = (int) floor(($index - 232) * 10 + 8);
        $gs = dechex($gray);
        return '#' . str_repeat($gs, 6);
    }

    /**
     * @return array<int, string>
     */
    private function defaultPalette(): array
    {
        return [
            '#000000',
            '#800000',
            '#008000',
            '#808000',
            '#000080',
            '#800080',
            '#008080',
            '#c0c0c0',
            '#808080',
            '#ff0000',
            '#00ff00',
            '#ffff00',
            '#0000ff',
            '#ff00ff',
            '#00ffff',
            '#ffffff',
        ];
    }
}
