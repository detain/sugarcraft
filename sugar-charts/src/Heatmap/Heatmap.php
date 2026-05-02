<?php

declare(strict_types=1);

namespace CandyCore\Charts\Heatmap;

use CandyCore\Charts\Canvas\Canvas;
use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Sprinkles\Style;

/**
 * 2D heatmap rendered onto a {@see Canvas}. Each grid cell becomes one
 * canvas cell whose foreground colour is linearly interpolated in RGB
 * between {@see $coldColor} (low values) and {@see $hotColor} (high
 * values).
 *
 * ```php
 * echo Heatmap::new([
 *     [0.1, 0.4, 0.8],
 *     [0.3, 0.6, 0.9],
 *     [0.5, 0.7, 1.0],
 * ])->view();
 * ```
 *
 * The grid's first row sits at the **top** of the rendered output. Pass
 * an explicit {@see $width}/{@see $height} to clip / pad; otherwise the
 * canvas mirrors the grid dimensions.
 */
final class Heatmap
{
    /** @param list<list<int|float>> $grid */
    private function __construct(
        public readonly array $grid,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $rune,
        public readonly Color $coldColor,
        public readonly Color $hotColor,
        public readonly ColorProfile $profile,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('heatmap width/height must be >= 0');
        }
    }

    /** @param list<list<int|float>> $grid */
    public static function new(array $grid = [], int $width = 0, int $height = 0): self
    {
        $rows = count($grid);
        $cols = $rows > 0 ? max(array_map('count', $grid)) : 0;
        return new self(
            grid:       array_values($grid),
            width:      $width  > 0 ? $width  : $cols,
            height:     $height > 0 ? $height : $rows,
            min:        null,
            max:        null,
            rune:       '█',
            coldColor:  Color::hex('#000050'),  // deep blue
            hotColor:   Color::hex('#ff4040'),  // red
            profile:    ColorProfile::TrueColor,
        );
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('heatmap width/height must be >= 0');
        }
        return new self($this->grid, $w, $h, $this->min, $this->max, $this->rune, $this->coldColor, $this->hotColor, $this->profile);
    }

    public function withMin(?float $m): self  { return new self($this->grid, $this->width, $this->height, $m, $this->max, $this->rune, $this->coldColor, $this->hotColor, $this->profile); }
    public function withMax(?float $m): self  { return new self($this->grid, $this->width, $this->height, $this->min, $m, $this->rune, $this->coldColor, $this->hotColor, $this->profile); }
    public function withRune(string $r): self { return new self($this->grid, $this->width, $this->height, $this->min, $this->max, $r, $this->coldColor, $this->hotColor, $this->profile); }
    public function withColors(Color $cold, Color $hot): self
    {
        return new self($this->grid, $this->width, $this->height, $this->min, $this->max, $this->rune, $cold, $hot, $this->profile);
    }
    public function withColorProfile(ColorProfile $p): self
    {
        return new self($this->grid, $this->width, $this->height, $this->min, $this->max, $this->rune, $this->coldColor, $this->hotColor, $p);
    }

    public function view(): string
    {
        if ($this->grid === [] || $this->width === 0 || $this->height === 0) {
            return (new Canvas($this->width, $this->height))->view();
        }

        // Auto-detect range when not pinned. Empty grids are caught above.
        $min = $this->min;
        $max = $this->max;
        if ($min === null || $max === null) {
            $first = true;
            foreach ($this->grid as $row) {
                foreach ($row as $v) {
                    $f = (float) $v;
                    if ($first) {
                        $min = $min ?? $f;
                        $max = $max ?? $f;
                        $first = false;
                        continue;
                    }
                    if ($min === null || $f < $min) { $min = $f; }
                    if ($max === null || $f > $max) { $max = $f; }
                }
            }
        }
        $min ??= 0.0;
        $max ??= 1.0;
        if ($max == $min) { $max = $min + 1.0; }

        $canvas = new Canvas($this->width, $this->height);
        $rowCount = count($this->grid);
        for ($y = 0; $y < $this->height; $y++) {
            if ($y >= $rowCount) {
                break;
            }
            $row = $this->grid[$y];
            $colCount = count($row);
            for ($x = 0; $x < $this->width; $x++) {
                if ($x >= $colCount) {
                    break;
                }
                $v = (float) $row[$x];
                $color = $this->lerp((float) $min, (float) $max, $v);
                $canvas->setCell($x, $y, $this->rune, Style::new()->foreground($color)->colorProfile($this->profile));
            }
        }
        return $canvas->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private function lerp(float $min, float $max, float $v): Color
    {
        $t = ($v - $min) / ($max - $min);
        $t = max(0.0, min(1.0, $t));
        $r = (int) round($this->coldColor->r + ($this->hotColor->r - $this->coldColor->r) * $t);
        $g = (int) round($this->coldColor->g + ($this->hotColor->g - $this->coldColor->g) * $t);
        $b = (int) round($this->coldColor->b + ($this->hotColor->b - $this->coldColor->b) * $t);
        return Color::rgb($r, $g, $b);
    }
}
