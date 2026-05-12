<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Trend direction for metrics.
 */
enum MetricTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Neutral = 'neutral';

    /**
     * Get the symbol for this trend.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Up => '▲',
            self::Down => '▼',
            self::Neutral => '●',
        };
    }

    /**
     * Get the default color for this trend.
     */
    public function defaultColor(): Color
    {
        return match ($this) {
            self::Up => Color::hex('#A6E3A1'),
            self::Down => Color::hex('#F38BA8'),
            self::Neutral => Color::hex('#6C7086'),
        };
    }

    /**
     * Determine trend from a delta value.
     */
    public static function fromDelta(float $delta, float $threshold = 0.0): self
    {
        if ($delta > $threshold) {
            return self::Up;
        }
        if ($delta < -$threshold) {
            return self::Down;
        }
        return self::Neutral;
    }
}

/**
 * A single metric/number display component.
 *
 * Displays a value with optional label, trend indicator, and color
 * coding. Supports number formatting (decimals, thousands separator),
 * left/center/right alignment, and custom formatting callbacks.
 *
 * Mirrors metric display from homedash/metrics but adapted to PHP
 * with wither-style immutable setters.
 */
final class Metric implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $value,
        private readonly ?string $label = null,
        private readonly ?int $widthConstraint = null,
        private readonly MetricTrend $trend = MetricTrend::Neutral,
        private readonly ?int $decimals = null,
        private readonly bool $showTrend = true,
        private readonly ?Color $valueColor = null,
        private readonly ?Color $labelColor = null,
        private readonly ?Color $trendColor = null,
        private readonly string $trendSpacing = ' ',
        private readonly HAlign $horizontalAlign = HAlign::Center,
    ) {}

    /**
     * Create a new metric with default styling.
     */
    public static function new(float $value, ?string $label = null): self
    {
        return new self(
            value: $value,
            label: $label,
            widthConstraint: null,
            trend: MetricTrend::Neutral,
            decimals: null,
            showTrend: true,
            valueColor: Color::hex('#CDD6F4'),
            labelColor: Color::hex('#6C7086'),
            trendColor: null,
            trendSpacing: ' ',
            horizontalAlign: HAlign::Center,
        );
    }

    /**
     * Set the allocated dimensions for this metric.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the metric as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        $formattedValue = $this->formatValue();
        $trendSymbol = $this->showTrend ? $this->trend->symbol() : '';
        $valueWithTrend = $trendSymbol !== ''
            ? $trendSymbol . $this->trendSpacing . $formattedValue
            : $formattedValue;

        $labelPart = $this->label !== null ? $this->label : '';

        // Build the full content
        $content = $labelPart !== '' ? $labelPart . "\n" . $valueWithTrend : $valueWithTrend;

        // Apply alignment
        $lines = explode("\n", $content);
        $alignedLines = [];
        foreach ($lines as $line) {
            $alignedLines[] = $this->alignLine($line, $width);
        }

        // Apply colors
        $output = implode("\n", $alignedLines);
        $output = $this->applyColors($output, $labelPart !== '' ? $labelPart : null, $valueWithTrend);

        return $output;
    }

    /**
     * Format the numeric value.
     */
    private function formatValue(): string
    {
        if ($this->decimals !== null) {
            $formatted = number_format($this->value, $this->decimals, '.', '');
        } else {
            // Auto-detect decimals based on value
            if ($this->value === floor($this->value)) {
                $formatted = number_format($this->value, 0, '.', '');
            } else {
                $formatted = number_format($this->value, 2, '.', '');
            }
        }

        return $formatted;
    }

    /**
     * Align a single line within the given width using horizontal alignment.
     */
    private function alignLine(string $line, int $width): string
    {
        $lineWidth = Width::string($line);

        if ($lineWidth >= $width) {
            return $line;
        }

        $padding = $width - $lineWidth;

        return match ($this->horizontalAlign) {
            HAlign::Left => $line . str_repeat(' ', $padding),
            HAlign::Right => str_repeat(' ', $padding) . $line,
            HAlign::Center => $this->centerAlign($line, $lineWidth, $width),
        };
    }

    /**
     * Center-align a line within the given width.
     */
    private function centerAlign(string $line, int $lineWidth, int $width): string
    {
        $padding = $width - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    /**
     * Apply ANSI colors to the output.
     */
    private function applyColors(string $output, ?string $labelPart, string $valuePart): string
    {
        if ($this->valueColor === null && $this->labelColor === null && $this->trendColor === null) {
            return $output;
        }

        $lines = explode("\n", $output);

        if ($labelPart !== null && count($lines) >= 2) {
            // First line is label, second is value+trend
            $result = [];
            $result[] = $this->applyLabelColor($lines[0]);
            $result[] = $this->applyValueAndTrendColor($lines[1], $valuePart);
            return implode("\n", $result);
        }

        // No label - all value
        return $this->applyValueAndTrendColor($output, $valuePart);
    }

    /**
     * Apply color to label line.
     */
    private function applyLabelColor(string $line): string
    {
        if ($this->labelColor === null) {
            return $line;
        }
        return $this->labelColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
    }

    /**
     * Apply colors to value and trend.
     */
    private function applyValueAndTrendColor(string $line, string $valuePart): string
    {
        if ($this->valueColor === null && $this->trendColor === null) {
            return $line;
        }

        // Find where the value part starts in the line
        $trendSymbol = $this->showTrend ? $this->trend->symbol() : '';
        $valueStart = mb_strpos($line, $valuePart, 0, 'UTF-8');

        if ($valueStart === false) {
            return $line;
        }

        $beforeValue = mb_substr($line, 0, $valueStart, 'UTF-8');
        $trendStr = $this->showTrend ? $this->trend->symbol() : '';
        $trendStrLen = mb_strlen($trendStr, 'UTF-8');
        $valueStr = mb_substr($line, $valueStart, mb_strlen($valuePart, 'UTF-8'), 'UTF-8');

        $result = $beforeValue;

        // Apply trend color
        if ($this->trendColor !== null && $trendStr !== '') {
            $trendPart = mb_substr($line, $valueStart, $trendStrLen, 'UTF-8');
            $result .= $this->trendColor->toFg(ColorProfile::TrueColor) . $trendPart . Ansi::reset();
        }

        // Apply value color
        if ($this->valueColor !== null) {
            $afterTrend = $valueStart + $trendStrLen;
            $valuePartOnly = mb_substr($line, $afterTrend, null, 'UTF-8');
            $result .= $this->valueColor->toFg(ColorProfile::TrueColor) . $valuePartOnly . Ansi::reset();
        }

        return $result;
    }

    /**
     * Get the width to use for the metric.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? 0;
    }

    /**
     * Calculate the natural dimensions of this metric.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $formattedValue = $this->formatValue();
        $trendSymbol = $this->showTrend ? $this->trend->symbol() : '';
        $valueWithTrend = $trendSymbol !== ''
            ? $trendSymbol . $this->trendSpacing . $formattedValue
            : $formattedValue;

        $width = Width::string($valueWithTrend);

        if ($this->label !== null) {
            $labelWidth = Width::string($this->label);
            $width = max($width, $labelWidth);
            return [$width, 2]; // label + value
        }

        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a new value.
     */
    public function withValue(float $value): self
    {
        return new self(
            value: $value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            value: $this->value,
            label: $label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $width,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the trend direction.
     */
    public function withTrend(MetricTrend $trend): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the number of decimal places.
     */
    public function withDecimals(?int $decimals): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Show or hide the trend indicator.
     */
    public function withShowTrend(bool $show): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $show,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $color,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $color,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the trend color.
     */
    public function withTrendColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $color,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the spacing between trend symbol and value.
     */
    public function withTrendSpacing(string $spacing): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $spacing,
            horizontalAlign: $this->horizontalAlign,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withHorizontalAlign(HAlign $align): self
    {
        return new self(
            value: $this->value,
            label: $this->label,
            widthConstraint: $this->widthConstraint,
            trend: $this->trend,
            decimals: $this->decimals,
            showTrend: $this->showTrend,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trendColor: $this->trendColor,
            trendSpacing: $this->trendSpacing,
            horizontalAlign: $align,
        );
    }
}
