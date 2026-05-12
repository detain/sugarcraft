<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A heat map visualization component.
 *
 * Displays a 2D grid of values as colored cells with a legend.
 * Supports custom color gradients and cell sizing.
 *
 * Mirrors heat map visualization concepts adapted to PHP with wither-style immutable setters.
 */
final class Heatmap implements Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Block characters for heat levels (cold to hot).
     */
    private const HEAT_BLOCKS = ['░', '▒', '▓', '█'];

    public function __construct(
        /**
         * 2D array of values (0.0 to 1.0).
         *
         * @param list<list<float>> $data
         */
        private readonly array $data,
        private readonly bool $showLegend = true,
        private readonly bool $showValues = false,
        private readonly ?Color $lowColor = null,
        private readonly ?Color $highColor = null,
        private readonly string $rowLabelFormat = '',
        private readonly string $colLabelFormat = '',
    ) {}

    /**
     * Create a new heat map with default styling.
     *
     * @param list<list<float>> $data 2D array of values between 0.0 and 1.0
     */
    public static function new(array $data): self
    {
        return new self(
            data: self::normalizeData($data),
            showLegend: true,
            showValues: false,
            lowColor: Color::hex('#3B82F6'),   // Blue (cold)
            highColor: Color::hex('#EF4444'),  // Red (hot)
            rowLabelFormat: '',
            colLabelFormat: '',
        );
    }

    /**
     * Create a sample heat map for demonstration.
     */
    public static function sample(int $rows = 5, int $cols = 7): self
    {
        $data = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                // Create a sample pattern (diagonal gradient)
                $value = ($r * $cols + $c) / ($rows * $cols);
                $row[] = $value;
            }
            $data[] = $row;
        }

        return self::new($data);
    }

    /**
     * Normalize data to ensure all values are between 0 and 1.
     *
     * @param list<list<float>> $data
     * @return list<list<float>>
     */
    private static function normalizeData(array $data): array
    {
        return array_map(function (array $row): array {
            return array_map(function (float $value): float {
                return max(0.0, min(1.0, $value));
            }, $row);
        }, $data);
    }

    /**
     * Set the allocated dimensions for this heat map.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Interpolate between two colors based on a ratio.
     */
    private function interpolateColor(float $ratio): ?Color
    {
        if ($this->lowColor === null && $this->highColor === null) {
            return null;
        }

        if ($this->lowColor === null) {
            return $this->highColor;
        }

        if ($this->highColor === null) {
            return $this->lowColor;
        }

        // Simple linear interpolation between colors
        $r1 = $this->lowColor->r;
        $g1 = $this->lowColor->g;
        $b1 = $this->lowColor->b;

        $r2 = $this->highColor->r;
        $g2 = $this->highColor->g;
        $b2 = $this->highColor->b;

        $r = (int) ($r1 + ($r2 - $r1) * $ratio);
        $g = (int) ($g1 + ($g2 - $g1) * $ratio);
        $b = (int) ($b1 + ($b2 - $b1) * $ratio);

        return Color::rgb($r, $g, $b);
    }

    /**
     * Get the heat character for a given value.
     */
    private function getHeatChar(float $value): string
    {
        if ($value < 0.25) {
            return self::HEAT_BLOCKS[0];
        } elseif ($value < 0.5) {
            return self::HEAT_BLOCKS[1];
        } elseif ($value < 0.75) {
            return self::HEAT_BLOCKS[2];
        } else {
            return self::HEAT_BLOCKS[3];
        }
    }

    /**
     * Render a single cell.
     */
    private function renderCell(float $value): string
    {
        $char = $this->getHeatChar($value);
        $color = $this->interpolateColor($value);

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
        }

        return $char;
    }

    /**
     * Render the heat map.
     */
    public function render(): string
    {
        if (empty($this->data) || empty($this->data[0])) {
            return '';
        }

        $rows = count($this->data);
        $cols = count($this->data[0]);

        $result = '';

        // Render the heat map grid
        foreach ($this->data as $rowIndex => $row) {
            $rowStr = '';
            foreach ($row as $value) {
                if ($this->showValues) {
                    // Show numeric value
                    $formatted = sprintf('%4.2f', $value);
                    $color = $this->interpolateColor($value);
                    if ($color !== null) {
                        $rowStr .= $color->toFg(ColorProfile::TrueColor);
                    }
                    $rowStr .= $formatted;
                    if ($color !== null) {
                        $rowStr .= Ansi::reset();
                    }
                } else {
                    $rowStr .= $this->renderCell($value);
                }
            }
            $result .= $rowStr . "\n";
        }

        // Add legend if enabled
        if ($this->showLegend) {
            $result .= $this->renderLegend();
        }

        return rtrim($result, "\n");
    }

    /**
     * Render the color legend.
     */
    private function renderLegend(): string
    {
        $legendStr = "\nLegend: ";

        // Render gradient bar
        for ($i = 0; $i <= 10; $i++) {
            $value = $i / 10.0;
            $legendStr .= $this->renderCell($value);
        }

        $legendStr .= ' ';
        if ($this->lowColor !== null) {
            $legendStr .= 'Low';
        }
        if ($this->lowColor !== null && $this->highColor !== null) {
            $legendStr .= ' → ';
        }
        if ($this->highColor !== null) {
            $legendStr .= 'High';
        }

        return $legendStr;
    }

    /**
     * Calculate the natural dimensions of this heat map.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->data) || empty($this->data[0])) {
            return [0, 0];
        }

        $rows = count($this->data);
        $cols = count($this->data[0]);

        $width = $this->showValues ? ($cols * 6) : $cols;
        $height = $rows + ($this->showLegend ? 2 : 0);

        return [$width, $height];
    }

    /**
     * Get the dimensions of the data grid.
     *
     * @return array{0:int,1:int} [rows, cols]
     */
    public function getDataDimensions(): array
    {
        if (empty($this->data)) {
            return [0, 0];
        }
        return [count($this->data), count($this->data[0])];
    }

    /**
     * Get the minimum value in the data.
     */
    public function getMinValue(): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($this->data as $row) {
            foreach ($row as $value) {
                $min = min($min, $value);
            }
        }
        return $min === PHP_FLOAT_MAX ? 0.0 : $min;
    }

    /**
     * Get the maximum value in the data.
     */
    public function getMaxValue(): float
    {
        $max = PHP_FLOAT_MIN;
        foreach ($this->data as $row) {
            foreach ($row as $value) {
                $max = max($max, $value);
            }
        }
        return $max === PHP_FLOAT_MIN ? 0.0 : $max;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Show or hide the legend.
     */
    public function withLegend(bool $show): self
    {
        return new self(
            data: $this->data,
            showLegend: $show,
            showValues: $this->showValues,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            rowLabelFormat: $this->rowLabelFormat,
            colLabelFormat: $this->colLabelFormat,
        );
    }

    /**
     * Show or hide cell values.
     */
    public function withValues(bool $show): self
    {
        return new self(
            data: $this->data,
            showLegend: $this->showLegend,
            showValues: $show,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            rowLabelFormat: $this->rowLabelFormat,
            colLabelFormat: $this->colLabelFormat,
        );
    }

    /**
     * Set the low (cold) color.
     */
    public function withLowColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            showLegend: $this->showLegend,
            showValues: $this->showValues,
            lowColor: $color,
            highColor: $this->highColor,
            rowLabelFormat: $this->rowLabelFormat,
            colLabelFormat: $this->colLabelFormat,
        );
    }

    /**
     * Set the high (hot) color.
     */
    public function withHighColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            showLegend: $this->showLegend,
            showValues: $this->showValues,
            lowColor: $this->lowColor,
            highColor: $color,
            rowLabelFormat: $this->rowLabelFormat,
            colLabelFormat: $this->colLabelFormat,
        );
    }

    /**
     * Set the row label format.
     */
    public function withRowLabelFormat(string $format): self
    {
        return new self(
            data: $this->data,
            showLegend: $this->showLegend,
            showValues: $this->showValues,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            rowLabelFormat: $format,
            colLabelFormat: $this->colLabelFormat,
        );
    }

    /**
     * Set the column label format.
     */
    public function withColLabelFormat(string $format): self
    {
        return new self(
            data: $this->data,
            showLegend: $this->showLegend,
            showValues: $this->showValues,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            rowLabelFormat: $this->rowLabelFormat,
            colLabelFormat: $format,
        );
    }
}
