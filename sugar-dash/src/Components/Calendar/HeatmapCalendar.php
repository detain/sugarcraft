<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Calendar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A calendar heatmap component (GitHub-style contributions).
 *
 * Displays a grid of values over time (typically weeks) as colored cells.
 * Each cell represents a day, with color intensity reflecting the value.
 * Supports custom color gradients, week/day labels, and month headers.
 *
 * Mirrors GitHub contribution graph concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class HeatmapCalendar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Block characters for heat levels (cold to hot).
     * More levels than basic heatmap for finer granularity.
     */
    private const HEAT_BLOCKS = ['░', '▒', '▓', '█'];

    /**
     * Days of week abbreviations.
     */
    private const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /**
     * Short day labels for compact display.
     */
    private const DAY_LABELS_SHORT = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    public function __construct(
        /**
         * 2D array of values organized by week (outer) and day (inner).
         * Example: $data[weekIndex][dayOfWeek] = value
         *
         * @param list<list<float>> $data
         */
        private readonly array $data,
        private readonly bool $showLabels = true,
        private readonly bool $showMonthLabels = false,
        private readonly bool $showDayLabels = true,
        private readonly ?Color $lowColor = null,
        private readonly ?Color $highColor = null,
        private readonly string $emptyChar = '·',
    ) {}

    /**
     * Create a new calendar heatmap with default styling.
     *
     * @param list<list<float>> $data 2D array of values (weeks x days), values 0.0 to 1.0
     */
    public static function new(array $data): self
    {
        return new self(
            data: self::normalizeData($data),
            showLabels: true,
            showMonthLabels: false,
            showDayLabels: true,
            lowColor: Color::hex('#161B22'),      // Dark background
            highColor: Color::hex('#39D353'),    // Green (GitHub style)
            emptyChar: '·',
        );
    }

    /**
     * Create sample data for demonstration.
     *
     * @param int $weeks Number of weeks to generate
     * @param int $baseValue Base activity level (0.0 to 1.0)
     */
    public static function sample(int $weeks = 20, float $baseValue = 0.3): self
    {
        $data = [];
        for ($w = 0; $w < $weeks; $w++) {
            $week = [];
            for ($d = 0; $d < 7; $d++) {
                // Generate realistic-looking activity with some variation
                $value = $baseValue + (mt_rand(-20, 70) / 100);
                $week[] = max(0.0, min(1.0, $value));
            }
            $data[] = $week;
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
        return array_map(function (array $week): array {
            return array_map(function (float $value): float {
                return max(0.0, min(1.0, $value));
            }, $week);
        }, $data);
    }

    /**
     * Set the allocated dimensions for this heatmap.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
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
            return $this->emptyChar;
        } elseif ($value < 0.5) {
            return self::HEAT_BLOCKS[0];
        } elseif ($value < 0.75) {
            return self::HEAT_BLOCKS[1];
        } elseif ($value < 0.9) {
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

        // Don't color empty cells
        if ($char === $this->emptyChar) {
            return $char;
        }

        $color = $this->interpolateColor($value);

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
        }

        return $char;
    }

    /**
     * Render the calendar heatmap.
     */
    public function render(): string
    {
        if (empty($this->data)) {
            return '';
        }

        $weeks = count($this->data);
        $daysPerWeek = count($this->data[0] ?? []);

        if ($daysPerWeek === 0) {
            return '';
        }

        $result = '';

        // Build day labels column
        $dayLabelWidth = $this->showDayLabels ? 4 : 0;

        // Render each week as a column of cells
        // Each row represents a day of the week (Mon, Tue, Wed, etc.)
        for ($dayIndex = 0; $dayIndex < $daysPerWeek; $dayIndex++) {
            $row = '';

            // Add day label if enabled
            if ($this->showDayLabels && $dayLabelWidth > 0) {
                $dayLabel = self::DAY_LABELS_SHORT[$dayIndex] ?? '';
                $row .= sprintf('%-3s ', $dayLabel);
            }

            // Render cells for each week
            foreach ($this->data as $weekIndex => $week) {
                if (isset($week[$dayIndex])) {
                    $row .= $this->renderCell($week[$dayIndex]);
                } else {
                    $row .= ' ';
                }
                $row .= ' '; // Gap between weeks
            }

            $result .= $row . "\n";
        }

        // Add legend if we have labels
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

        // Less → More label
        $legendStr .= 'Less ';

        // Render gradient
        for ($i = 0; $i <= 4; $i++) {
            $value = $i / 4.0;
            if ($value === 0) {
                $legendStr .= $this->emptyChar;
            } else {
                $legendStr .= $this->renderCell($value);
            }
            $legendStr .= ' ';
        }

        $legendStr .= 'More';

        return $legendStr;
    }

    /**
     * Calculate the natural dimensions of this heatmap.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->data)) {
            return [0, 0];
        }

        $weeks = count($this->data);
        $daysPerWeek = count($this->data[0] ?? []);

        if ($daysPerWeek === 0) {
            return [0, 0];
        }

        // Width: day label (4) + weeks * 2 (cell + gap)
        $width = ($this->showDayLabels ? 4 : 0) + $weeks * 2;

        // Height: days per week + legend (2 lines)
        $height = $daysPerWeek + ($this->showLabels ? 2 : 0);

        return [$width, $height];
    }

    /**
     * Get the dimensions of the data grid.
     *
     * @return array{0:int,1:int} [weeks, daysPerWeek]
     */
    public function getDataDimensions(): array
    {
        if (empty($this->data)) {
            return [0, 0];
        }
        return [count($this->data), count($this->data[0] ?? [])];
    }

    /**
     * Get the total value across all cells.
     */
    public function getTotalValue(): float
    {
        $total = 0.0;
        foreach ($this->data as $week) {
            foreach ($week as $value) {
                $total += $value;
            }
        }
        return $total;
    }

    /**
     * Get the average value across all cells.
     */
    public function getAverageValue(): float
    {
        $count = 0;
        $total = 0.0;
        foreach ($this->data as $week) {
            foreach ($week as $value) {
                $total += $value;
                $count++;
            }
        }
        return $count > 0 ? $total / $count : 0.0;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Show or hide labels.
     */
    public function withLabels(bool $show): self
    {
        return new self(
            data: $this->data,
            showLabels: $show,
            showMonthLabels: $this->showMonthLabels,
            showDayLabels: $this->showDayLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Show or hide month labels.
     */
    public function withMonthLabels(bool $show): self
    {
        return new self(
            data: $this->data,
            showLabels: $this->showLabels,
            showMonthLabels: $show,
            showDayLabels: $this->showDayLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Show or hide day labels.
     */
    public function withDayLabels(bool $show): self
    {
        return new self(
            data: $this->data,
            showLabels: $this->showLabels,
            showMonthLabels: $this->showMonthLabels,
            showDayLabels: $show,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the low (cold) color.
     */
    public function withLowColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            showLabels: $this->showLabels,
            showMonthLabels: $this->showMonthLabels,
            showDayLabels: $this->showDayLabels,
            lowColor: $color,
            highColor: $this->highColor,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the high (hot) color.
     */
    public function withHighColor(?Color $color): self
    {
        return new self(
            data: $this->data,
            showLabels: $this->showLabels,
            showMonthLabels: $this->showMonthLabels,
            showDayLabels: $this->showDayLabels,
            lowColor: $this->lowColor,
            highColor: $color,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the empty cell character.
     */
    public function withEmptyChar(string $char): self
    {
        return new self(
            data: $this->data,
            showLabels: $this->showLabels,
            showMonthLabels: $this->showMonthLabels,
            showDayLabels: $this->showDayLabels,
            lowColor: $this->lowColor,
            highColor: $this->highColor,
            emptyChar: $char,
        );
    }
}