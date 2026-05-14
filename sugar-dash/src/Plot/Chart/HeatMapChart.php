<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A heatmap component for displaying data intensity in a grid format.
 *
 * Features:
 * - 2D grid of values with color gradient
 * - Customizable color scales (sequential, diverging)
 * - Row/column labels
 * - Cell sizing and spacing
 * - Value formatting
 *
 * Mirrors heatmap patterns adapted to PHP with wither-style immutable setters.
 */
final class HeatMapChart implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Block characters for intensity levels (low to high).
     */
    private const HEAT_BLOCKS = ['░', '▒', '▓', '█'];

    public function __construct(
        /**
         * 2D array of values [row][col] = value.
         * @param list<list<float>> $data
         */
        private readonly array $data,
        /**
         * @param list<string>|null $rowLabels
         */
        private readonly ?array $rowLabels = null,
        /**
         * @param list<string>|null $columnLabels
         */
        private readonly ?array $columnLabels = null,
        private readonly ?Color $lowColor = null,
        private readonly ?Color $highColor = null,
        private readonly string $scale = 'sequential',
        private readonly bool $showLabels = true,
        private readonly int $cellWidth = 1,
        private readonly int $cellHeight = 1,
    ) {}

    /**
     * Create a new heatmap.
     *
     * @param list<list<float>> $data 2D array of values, typically 0.0 to 1.0
     */
    public static function new(array $data): self
    {
        return new self(
            data: self::normalizeData($data),
            rowLabels: null,
            columnLabels: null,
            lowColor: Color::hex('#1E1E2E'),
            highColor: Color::hex('#F38BA8'),
            scale: 'sequential',
            showLabels: true,
            cellWidth: 1,
            cellHeight: 1,
        );
    }

    /**
     * Create a sample heatmap for demonstration.
     */
    public static function sample(int $rows = 5, int $cols = 7): self
    {
        $data = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                // Generate realistic-looking activity with some variation
                $value = 0.2 + (mt_rand(0, 80) / 100);
                $row[] = max(0.0, min(1.0, $value));
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
     * Set the allocated dimensions for this heatmap.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
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
        if ($value < 0.2) {
            return ' ';
        } elseif ($value < 0.4) {
            return self::HEAT_BLOCKS[0];
        } elseif ($value < 0.6) {
            return self::HEAT_BLOCKS[1];
        } elseif ($value < 0.8) {
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

        if ($char === ' ') {
            return $char;
        }

        $color = $this->interpolateColor($value);

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
        }

        return $char;
    }

    /**
     * Render the heatmap.
     */
    public function render(): string
    {
        if (empty($this->data) || empty($this->data[0] ?? [])) {
            return '';
        }

        $rows = count($this->data);
        $cols = count($this->data[0]);

        $result = '';

        // Column labels header
        if ($this->showLabels && $this->columnLabels !== null) {
            $labelRow = str_repeat(' ', 4); // Row label space
            for ($c = 0; $c < $cols; $c++) {
                $label = $this->columnLabels[$c] ?? '';
                $labelRow .= sprintf('%-2s ', mb_substr($label, 0, 2, 'UTF-8'));
            }
            $result .= $labelRow . "\n";
        }

        // Render each row
        for ($r = 0; $r < $rows; $r++) {
            $row = '';

            // Row label
            if ($this->showLabels && $this->rowLabels !== null) {
                $label = $this->rowLabels[$r] ?? '';
                $row .= sprintf('%-3s ', mb_substr($label, 0, 3, 'UTF-8'));
            }

            // Cells
            for ($c = 0; $c < $cols; $c++) {
                $value = $this->data[$r][$c] ?? 0.0;
                $row .= $this->renderCell($value);
                if ($c < $cols - 1) {
                    $row .= ' '; // Gap between cells
                }
            }

            $result .= $row . "\n";
        }

        // Color scale legend
        if ($this->showLabels) {
            $result .= $this->renderLegend();
        }

        return rtrim($result, "\n");
    }

    /**
     * Render the color legend.
     */
    private function renderLegend(): string
    {
        $legendStr = "\n";
        $legendStr .= 'Low ';
        for ($i = 0; $i <= 4; $i++) {
            $value = $i / 4.0;
            if ($value < 0.2) {
                $legendStr .= ' ';
            } else {
                $legendStr .= $this->renderCell($value);
            }
            $legendStr .= ' ';
        }
        $legendStr .= 'High';

        return $legendStr;
    }

    /**
     * Calculate the natural dimensions of this heatmap.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->data) || empty($this->data[0] ?? [])) {
            return [0, 0];
        }

        $rows = count($this->data);
        $cols = count($this->data[0]);

        $colLabelWidth = $this->showLabels && $this->columnLabels !== null ? 4 : 0;
        $rowLabelWidth = $this->showLabels && $this->rowLabels !== null ? 4 : 0;

        $width = $colLabelWidth + $rowLabelWidth + $cols * 2;
        $height = $rows + ($this->showLabels ? 2 : 0); // +1 for header, +1 for legend

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set row labels.
     *
     * @param list<string>|null $labels
     */
    public function withRowLabels(?array $labels): self
    {
        return new self(
            data: $this->data,
            rowLabels: $labels,
            columnLabels: $this->columnLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            scale: $this->scale,
            showLabels: $this->showLabels,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }

    /**
     * Set column labels.
     *
     * @param list<string>|null $labels
     */
    public function withColumnLabels(?array $labels): self
    {
        return new self(
            data: $this->data,
            rowLabels: $this->rowLabels,
            columnLabels: $labels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            scale: $this->scale,
            showLabels: $this->showLabels,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }

    /**
     * Set the color scale.
     */
    public function withScale(string $scale): self
    {
        return new self(
            data: $this->data,
            rowLabels: $this->rowLabels,
            columnLabels: $this->columnLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            scale: $scale,
            showLabels: $this->showLabels,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }

    /**
     * Set the low color.
     */
    public function withLowColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            rowLabels: $this->rowLabels,
            columnLabels: $this->columnLabels,
            lowColor: $color,
            highColor: $this->highColor,
            scale: $this->scale,
            showLabels: $this->showLabels,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }

    /**
     * Set the high color.
     */
    public function withHighColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            rowLabels: $this->rowLabels,
            columnLabels: $this->columnLabels,
            lowColor: $this->lowColor,
            highColor: $color,
            scale: $this->scale,
            showLabels: $this->showLabels,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            data: $this->data,
            rowLabels: $this->rowLabels,
            columnLabels: $this->columnLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            scale: $this->scale,
            showLabels: $show,
            cellWidth: $this->cellWidth,
            cellHeight: $this->cellHeight,
        );
    }
}
