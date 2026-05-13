<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A metrics grid component for displaying a grid of stat cards.
 *
 * Features:
 * - Responsive grid layout
 * - Configurable columns
 * - Metric cards with values, labels, and trends
 * - Color-coded by metric type
 * - Compact and expanded modes
 *
 * Mirrors dashboard grid/kpi patterns adapted to PHP with wither-style immutable setters.
 */
final class MetricsGrid implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<MetricCard> $metrics
     */
    public function __construct(
        private readonly array $metrics = [],
        private readonly int $columns = 3,
        private readonly bool $showTrends = true,
        private readonly bool $showLabels = true,
        private readonly bool $compact = false,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $valueColor = null,
        private readonly ?Color $labelColor = null,
    ) {}

    /**
     * Create a new metrics grid.
     *
     * @param list<MetricCard> $metrics
     */
    public static function new(array $metrics = []): self
    {
        return new self(
            metrics: $metrics,
            columns: 3,
            showTrends: true,
            showLabels: true,
            compact: false,
            borderColor: Color::hex('#45475A'),
            valueColor: Color::hex('#F38BA8'),
            labelColor: Color::hex('#6C7086'),
        );
    }

    /**
     * Create a sample metrics grid for demonstration.
     */
    public static function sample(): self
    {
        return self::new([
            new MetricCard('Revenue', '$12,450', '+12%', 'up', Color::hex('#A6E3A1')),
            new MetricCard('Users', '1,234', '+5%', 'up', Color::hex('#89B4FA')),
            new MetricCard('Orders', '89', '-3%', 'down', Color::hex('#F38BA8')),
            new MetricCard('Conversion', '3.2%', '+0.5%', 'up', Color::hex('#CBA6F7')),
            new MetricCard('Avg. Time', '2m 34s', '-8%', 'down', Color::hex('#94E2D5')),
            new MetricCard('Bounce Rate', '24%', '-2%', 'down', Color::hex('#F9E2AF')),
        ]);
    }

    /**
     * Set the allocated dimensions for this grid.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the width of each card.
     */
    private function getCardWidth(): int
    {
        $useWidth = $this->width ?? 60;
        $totalGaps = $this->columns - 1;
        $gapWidth = 2;
        return intval(($useWidth - ($totalGaps * $gapWidth)) / $this->columns);
    }

    /**
     * Render the metrics grid.
     */
    public function render(): string
    {
        if (empty($this->metrics)) {
            return '';
        }

        $cardWidth = $this->getCardWidth();
        $numMetrics = count($this->metrics);
        $numRows = intval(ceil($numMetrics / $this->columns));

        $result = '';

        for ($row = 0; $row < $numRows; $row++) {
            $rowLines = [];

            // Collect cards for this row
            $rowCards = [];
            for ($col = 0; $col < $this->columns; $col++) {
                $index = $row * $this->columns + $col;
                if ($index < $numMetrics) {
                    $rowCards[] = $this->metrics[$index];
                } else {
                    $rowCards[] = null;
                }
            }

            // Determine how many lines this row needs
            $maxLines = 1;
            if ($this->showLabels) {
                $maxLines++;
            }
            if ($this->showTrends) {
                $maxLines++;
            }

            // Render each line of the row
            for ($line = 0; $line < $maxLines; $line++) {
                $lineStr = '';

                foreach ($rowCards as $cardIndex => $card) {
                    if ($card === null) {
                        // Empty cell
                        $lineStr .= str_repeat(' ', $cardWidth);
                    } else {
                        $lineStr .= $this->renderCardLine($card, $cardWidth, $line);
                    }

                    // Gap between columns
                    if ($cardIndex < $this->columns - 1) {
                        $lineStr .= '  ';
                    }
                }

                $result .= mb_substr($lineStr, 0, $this->width ?? mb_strlen($lineStr, 'UTF-8'), 'UTF-8');
                if ($line < $maxLines - 1) {
                    $result .= "\n";
                }
            }

            // Row separator (except for last row)
            if ($row < $numRows - 1) {
                $result .= "\n";
                // Separator line
                $separator = '';
                for ($col = 0; $col < $this->columns; $col++) {
                    $separator .= str_repeat('─', $cardWidth);
                    if ($col < $this->columns - 1) {
                        $separator .= '  ';
                    }
                }
                if ($this->borderColor !== null) {
                    $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
                }
                $result .= $separator;
                if ($this->borderColor !== null) {
                    $result .= Ansi::reset();
                }
                $result .= "\n";
            }
        }

        return rtrim($result, "\n");
    }

    /**
     * Render a single line of a card.
     */
    private function renderCardLine(MetricCard $card, int $width, int $lineIndex): string
    {
        $valueColor = $this->valueColor ?? $card->color ?? Color::hex('#F38BA8');
        $labelColor = $this->labelColor ?? Color::hex('#6C7086');

        $content = match ($lineIndex) {
            0 => $this->renderCardValue($card, $width, $valueColor),
            1 => $this->showLabels ? $this->renderCardLabel($card, $width, $labelColor) : str_repeat(' ', $width),
            2 => $this->showTrends ? $this->renderCardTrend($card, $width) : str_repeat(' ', $width),
            default => str_repeat(' ', $width),
        };

        // Pad or truncate to card width
        $contentLen = mb_strlen($content, 'UTF-8');
        if ($contentLen > $width) {
            return mb_substr($content, 0, $width, 'UTF-8');
        }
        return str_pad($content, $width, ' ', STR_PAD_LEFT);
    }

    /**
     * Render the value line of a card.
     */
    private function renderCardValue(MetricCard $card, int $width, Color $color): string
    {
        $value = $card->value ?? '';
        $valueLen = mb_strlen($value, 'UTF-8');

        if ($valueLen > $width - 2) {
            return mb_substr($value, 0, $width - 2, 'UTF-8') . '…';
        }

        return $color->toFg(ColorProfile::TrueColor) . str_pad($value, $width, ' ', STR_PAD_LEFT) . Ansi::reset();
    }

    /**
     * Render the label line of a card.
     */
    private function renderCardLabel(MetricCard $card, int $width, Color $color): string
    {
        $label = $card->label ?? '';
        $labelLen = mb_strlen($label, 'UTF-8');

        if ($labelLen > $width) {
            return mb_substr($label, 0, $width, 'UTF-8');
        }

        return $color->toFg(ColorProfile::TrueColor) . str_pad($label, $width, ' ', STR_PAD_LEFT) . Ansi::reset();
    }

    /**
     * Render the trend line of a card.
     */
    private function renderCardTrend(MetricCard $card, int $width): string
    {
        $trend = $card->trend ?? '';
        $trendValue = $card->trendValue ?? '';

        $trendStr = '';
        if ($trend === 'up') {
            $trendStr = '↑';
        } elseif ($trend === 'down') {
            $trendStr = '↓';
        }
        $trendStr .= ' ' . $trendValue;

        $trendColor = match ($trend) {
            'up' => Color::hex('#A6E3A1'),
            'down' => Color::hex('#F38BA8'),
            default => Color::hex('#6C7086'),
        };

        $trendLen = mb_strlen($trendStr, 'UTF-8');
        if ($trendLen > $width) {
            $trendStr = mb_substr($trendStr, 0, $width - 1, 'UTF-8') . '…';
        }

        return $trendColor->toFg(ColorProfile::TrueColor) . str_pad($trendStr, $width, ' ', STR_PAD_LEFT) . Ansi::reset();
    }

    /**
     * Calculate the natural dimensions of this grid.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->metrics)) {
            return [0, 0];
        }

        $cardWidth = $this->getCardWidth();
        $totalGaps = $this->columns - 1;
        $gapWidth = 2;

        $width = ($cardWidth * $this->columns) + ($totalGaps * $gapWidth);

        $numMetrics = count($this->metrics);
        $numRows = intval(ceil($numMetrics / $this->columns));

        $linesPerCard = 1;
        if ($this->showLabels) {
            $linesPerCard++;
        }
        if ($this->showTrends) {
            $linesPerCard++;
        }

        $height = ($numRows * $linesPerCard) + max(0, $numRows - 1); // +1 for separators between rows

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the metrics.
     *
     * @param list<MetricCard> $metrics
     */
    public function withMetrics(array $metrics): self
    {
        return new self(
            metrics: $metrics,
            columns: $this->columns,
            showTrends: $this->showTrends,
            showLabels: $this->showLabels,
            compact: $this->compact,
            borderColor: $this->borderColor,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the number of columns.
     */
    public function withColumns(int $columns): self
    {
        return new self(
            metrics: $this->metrics,
            columns: max(1, $columns),
            showTrends: $this->showTrends,
            showLabels: $this->showLabels,
            compact: $this->compact,
            borderColor: $this->borderColor,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide trends.
     */
    public function withShowTrends(bool $show): self
    {
        return new self(
            metrics: $this->metrics,
            columns: $this->columns,
            showTrends: $show,
            showLabels: $this->showLabels,
            compact: $this->compact,
            borderColor: $this->borderColor,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            metrics: $this->metrics,
            columns: $this->columns,
            showTrends: $this->showTrends,
            showLabels: $show,
            compact: $this->compact,
            borderColor: $this->borderColor,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set compact mode.
     */
    public function withCompact(bool $compact): self
    {
        return new self(
            metrics: $this->metrics,
            columns: $this->columns,
            showTrends: $this->showTrends,
            showLabels: $this->showLabels,
            compact: $compact,
            borderColor: $this->borderColor,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
        );
    }
}

/**
 * A metric card for the metrics grid.
 */
final readonly class MetricCard
{
    public function __construct(
        public string $label,
        public ?string $value = null,
        public ?string $trendValue = null,
        public ?string $trend = null,
        public ?Color $color = null,
    ) {}

    /**
     * Create a card from a number value.
     */
    public static function fromNumber(string $label, float $number, int $decimalPlaces = 0): self
    {
        $formatted = number_format($number, $decimalPlaces);
        return new self(label: $label, value: $formatted);
    }

    /**
     * Create a percentage card.
     */
    public static function percent(string $label, float $value): self
    {
        $formatted = number_format($value, 1) . '%';
        return new self(label: $label, value: $formatted);
    }

    /**
     * Create a currency card.
     */
    public static function currency(string $label, float $value, string $symbol = '$'): self
    {
        $formatted = $symbol . number_format($value, 2);
        return new self(label: $label, value: $formatted);
    }
}
