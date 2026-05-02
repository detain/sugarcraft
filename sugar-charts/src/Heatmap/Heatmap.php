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
    /**
     * @param list<list<int|float>> $grid
     * @param list<Color>           $palette  multi-stop colour palette;
     *                                        empty falls back to the
     *                                        cold→hot two-stop blend.
     */
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
        public readonly array $palette  = [],
        public readonly bool $showLegend = false,
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

    public function withMin(?float $m): self  { return $this->copy(min: $m, minSet: true); }
    public function withMax(?float $m): self  { return $this->copy(max: $m, maxSet: true); }
    public function withRune(string $r): self { return $this->copy(rune: $r); }
    public function withColors(Color $cold, Color $hot): self
    {
        return $this->copy(coldColor: $cold, hotColor: $hot);
    }
    public function withColorProfile(ColorProfile $p): self
    {
        return $this->copy(profile: $p);
    }

    /**
     * Multi-stop colour palette. The value range maps linearly across
     * the supplied colours; first colour = `min`, last = `max`,
     * intermediates evenly spaced. Pass `[]` to clear and revert to
     * the cold↔hot two-stop blend. Mirrors ntcharts' `SetDefaultColorScale`.
     *
     * @param list<Color> $stops at least 2 colours
     */
    public function withPalette(array $stops): self
    {
        if ($stops !== [] && count($stops) < 2) {
            throw new \InvalidArgumentException('palette needs at least 2 colours (or empty to disable)');
        }
        return $this->copy(palette: array_values($stops));
    }

    /**
     * Append a one-row gradient legend below the grid showing the
     * full cold→hot palette span. The legend renders as `width` cells
     * with min/max labels at each end. Default off.
     */
    public function withLegend(bool $on = true): self
    {
        return $this->copy(showLegend: $on);
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
                $color = $this->sample((float) $min, (float) $max, $v);
                $canvas->setCell($x, $y, $this->rune, Style::new()->foreground($color)->colorProfile($this->profile));
            }
        }
        $body = $canvas->view();
        if ($this->showLegend) {
            $body .= "\n" . $this->renderLegend((float) $min, (float) $max);
        }
        return $body;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Sample the palette at the normalised position of `$v` between
     * `$min` and `$max`. Honors a multi-stop palette when supplied;
     * otherwise blends cold↔hot linearly.
     */
    private function sample(float $min, float $max, float $v): Color
    {
        $t = ($v - $min) / ($max - $min);
        $t = max(0.0, min(1.0, $t));
        if ($this->palette !== []) {
            $count = count($this->palette);
            $segments = $count - 1;
            $pos = $t * $segments;
            $idx = min((int) floor($pos), $segments - 1);
            $local = $pos - $idx;
            return $this->palette[$idx]->blend($this->palette[$idx + 1], $local);
        }
        return $this->coldColor->blend($this->hotColor, $t);
    }

    /**
     * One-row gradient strip with min/max labels at each end, sized
     * to {@see $width}.
     */
    private function renderLegend(float $min, float $max): string
    {
        $minLabel = self::formatLabel($min);
        $maxLabel = self::formatLabel($max);
        $minLen = mb_strlen($minLabel, 'UTF-8');
        $maxLen = mb_strlen($maxLabel, 'UTF-8');
        $stripWidth = max(0, $this->width - $minLen - $maxLen - 2);
        $strip = '';
        for ($i = 0; $i < $stripWidth; $i++) {
            $t = $stripWidth <= 1 ? 0.0 : $i / ($stripWidth - 1);
            $color = $this->sample(0.0, 1.0, $t);
            $strip .= Style::new()
                ->foreground($color)
                ->colorProfile($this->profile)
                ->render($this->rune);
        }
        return $minLabel . ' ' . $strip . ' ' . $maxLabel;
    }

    private static function formatLabel(float $v): string
    {
        return $v == (int) $v
            ? (string) (int) $v
            : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Internal copy-with-overrides helper. */
    private function copy(
        ?array $grid = null,
        ?int $width = null,
        ?int $height = null,
        ?float $min = null, bool $minSet = false,
        ?float $max = null, bool $maxSet = false,
        ?string $rune = null,
        ?Color $coldColor = null,
        ?Color $hotColor = null,
        ?ColorProfile $profile = null,
        ?array $palette = null,
        ?bool $showLegend = null,
    ): self {
        return new self(
            grid:       $grid       ?? $this->grid,
            width:      $width      ?? $this->width,
            height:     $height     ?? $this->height,
            min:        $minSet     ? $min       : $this->min,
            max:        $maxSet     ? $max       : $this->max,
            rune:       $rune       ?? $this->rune,
            coldColor:  $coldColor  ?? $this->coldColor,
            hotColor:   $hotColor   ?? $this->hotColor,
            profile:    $profile    ?? $this->profile,
            palette:    $palette    ?? $this->palette,
            showLegend: $showLegend ?? $this->showLegend,
        );
    }
}
