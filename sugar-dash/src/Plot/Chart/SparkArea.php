<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A spark area chart - minimal area chart for inline use.
 *
 * Features:
 * - Compact inline sparkline area
 * - Configurable height (1-4 lines)
 * - Optional markers at min/max points
 * - Gradient or solid fill
 * - Single color for minimalism
 *
 * Mirrors sparkline/area patterns adapted to PHP with wither-style immutable setters.
 */
final class SparkArea implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly array $values,
        private readonly int $chartHeight = 3,
        private readonly ?Color $color = null,
        private readonly bool $showMinMax = false,
        private readonly bool $gradient = true,
    ) {}

    /**
     * Create a new spark area.
     *
     * @param list<float> $values
     */
    public static function new(array $values): self
    {
        return new self(
            values: $values,
            chartHeight: 3,
            color: Color::hex('#89B4FA'),
            showMinMax: false,
            gradient: true,
        );
    }

    /**
     * Set the allocated dimensions for this spark area.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this spark area.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? count($this->values) + 2;
        return [$useWidth, $this->chartHeight];
    }

    /**
     * Render the spark area.
     */
    public function render(): string
    {
        if ($this->values === []) {
            return str_repeat("\n", $this->chartHeight - 1);
        }

        $useWidth = $this->width ?? count($this->values);
        $colorStr = $this->color->toFg(ColorProfile::TrueColor);

        // Normalize values to chartHeight
        $min = min($this->values);
        $max = max($this->values);
        $range = $max - $min;
        if ($range <= 0) {
            $range = 1;
        }

        // Find min/max positions
        $minIdx = array_search($min, $this->values);
        $maxIdx = array_search($max, $this->values);

        // Build the grid
        $grid = [];
        for ($y = 0; $y < $this->chartHeight; $y++) {
            $grid[$y] = array_fill(0, $useWidth, ' ');
        }

        // Plot the area fill
        for ($x = 0; $x < count($this->values); $x++) {
            $value = $this->values[$x];
            $normalized = ($value - $min) / $range;
            $topY = (int) round($normalized * ($this->chartHeight - 1));
            $topY = $this->chartHeight - 1 - $topY; // Flip

            // Fill from topY to bottom
            for ($y = $topY; $y < $this->chartHeight; $y++) {
                if ($y >= 0 && $y < $this->chartHeight && $x < $useWidth) {
                    $grid[$y][$x] = '▄';
                }
            }
        }

        // Mark min/max if requested
        if ($this->showMinMax) {
            if ($minIdx < $useWidth) {
                $grid[$this->chartHeight - 1][$minIdx] = '▼';
            }
            if ($maxIdx < $useWidth) {
                $grid[0][$maxIdx] = '▲';
            }
        }

        // Convert to string
        $result = '';
        for ($y = 0; $y < $this->chartHeight; $y++) {
            $line = implode('', $grid[$y]);
            $result .= $colorStr . $line . Ansi::reset() . "\n";
        }

        return rtrim($result, "\n");
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chart height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            values: $this->values,
            chartHeight: max(1, min(4, $height)),
            color: $this->color,
            showMinMax: $this->showMinMax,
            gradient: $this->gradient,
        );
    }

    /**
     * Set the color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            values: $this->values,
            chartHeight: $this->chartHeight,
            color: $color,
            showMinMax: $this->showMinMax,
            gradient: $this->gradient,
        );
    }

    /**
     * Show min/max markers.
     */
    public function withShowMinMax(bool $show): self
    {
        return new self(
            values: $this->values,
            chartHeight: $this->chartHeight,
            color: $this->color,
            showMinMax: $show,
            gradient: $this->gradient,
        );
    }

    /**
     * Enable gradient fill.
     */
    public function withGradient(bool $gradient): self
    {
        return new self(
            values: $this->values,
            chartHeight: $this->chartHeight,
            color: $this->color,
            showMinMax: $this->showMinMax,
            gradient: $gradient,
        );
    }
}
