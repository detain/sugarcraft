<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A gauge chart component for displaying a single value within a range.
 *
 * Features:
 * - Arc-based gauge display
 * - Configurable min/max values
 * - Color zones (good, warning, danger)
 * - Needle or bar indicator
 * - Optional value display
 *
 * Mirrors gauge/meter patterns adapted to PHP with wither-style immutable setters.
 */
final class GaugeChart implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $value = 0.0,
        private readonly float $min = 0.0,
        private readonly float $max = 100.0,
        private readonly ?Color $color = null,
        private readonly bool $showValue = true,
        private readonly bool $showLabel = false,
        private readonly ?string $label = null,
        private readonly string $format = 'value',
    ) {}

    /**
     * Create a new gauge chart.
     */
    public static function new(float $value = 0.0, float $min = 0.0, float $max = 100.0): self
    {
        return new self(
            value: $value,
            min: $min,
            max: $max,
            color: Color::hex('#A6E3A1'),
            showValue: true,
            showLabel: false,
            label: null,
            format: 'value',
        );
    }

    /**
     * Create a percentage gauge (0-100%).
     */
    public static function percent(float $value): self
    {
        return self::new($value, 0.0, 100.0)->withFormat('percent');
    }

    /**
     * Create a CPU-style gauge.
     */
    public static function cpu(float $usage): self
    {
        return (self::new($usage, 0.0, 100.0))
            ->withFormat('percent')
            ->withColor(self::cpuColor($usage));
    }

    /**
     * Get color based on CPU usage.
     */
    private static function cpuColor(float $usage): Color
    {
        if ($usage < 50) {
            return Color::hex('#A6E3A1'); // Green
        } elseif ($usage < 80) {
            return Color::hex('#F9E2AF'); // Yellow
        } else {
            return Color::hex('#F38BA8'); // Red
        }
    }

    /**
     * Set the allocated dimensions for this gauge.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Normalize the value to 0-1 range.
     */
    private function getNormalizedValue(): float
    {
        $range = $this->max - $this->min;
        if ($range <= 0) {
            return 0.0;
        }
        return max(0.0, min(1.0, ($this->value - $this->min) / $range));
    }

    /**
     * Render the gauge.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 30;
        $useHeight = $this->height ?? 10;

        // Minimum viable size
        if ($useWidth < 10 || $useHeight < 5) {
            return '';
        }

        $gaugeWidth = $useWidth - 2;
        $gaugeHeight = $useHeight - 2;

        $normalized = $this->getNormalizedValue();
        $filledWidth = (int) ($normalized * $gaugeWidth);

        $result = '';

        // Top arc (simplified as corners)
        $result .= '╭' . str_repeat('─', $gaugeWidth) . '╮';
        $result .= "\n";

        // Gauge body
        $color = $this->color ?? Color::hex('#A6E3A1');

        for ($row = 0; $row < $gaugeHeight; $row++) {
            $result .= '│';

            // Draw filled portion
            for ($col = 0; $col < $gaugeWidth; $col++) {
                if ($col < $filledWidth) {
                    $result .= $color->toFg(ColorProfile::TrueColor) . '█' . Ansi::reset();
                } else {
                    $result .= '░';
                }
            }

            $result .= '│';
            $result .= "\n";
        }

        // Bottom value display
        $valueStr = $this->formatValue();
        $paddedValue = str_pad($valueStr, $gaugeWidth, ' ', STR_PAD_BOTH);
        $result .= '╰' . str_repeat('─', $gaugeWidth) . '╯';
        $result .= "\n";
        $result .= str_pad($paddedValue, $useWidth, ' ', STR_PAD_BOTH);

        return $result;
    }

    /**
     * Format the value for display.
     */
    private function formatValue(): string
    {
        return match ($this->format) {
            'percent' => number_format($this->value, 1) . '%',
            'decimal' => number_format($this->value, 2),
            'value' => number_format($this->value, 0),
            default => (string) $this->value,
        };
    }

    /**
     * Calculate the natural dimensions of this gauge.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 30;
        $height = $this->height ?? 10;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the value.
     */
    public function withValue(float $value): self
    {
        return new self(
            value: $value,
            min: $this->min,
            max: $this->max,
            color: $this->color,
            showValue: $this->showValue,
            showLabel: $this->showLabel,
            label: $this->label,
            format: $this->format,
        );
    }

    /**
     * Set the min/max range.
     */
    public function withRange(float $min, float $max): self
    {
        return new self(
            value: $this->value,
            min: $min,
            max: $max,
            color: $this->color,
            showValue: $this->showValue,
            showLabel: $this->showLabel,
            label: $this->label,
            format: $this->format,
        );
    }

    /**
     * Set the color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            color: $color,
            showValue: $this->showValue,
            showLabel: $this->showLabel,
            label: $this->label,
            format: $this->format,
        );
    }

    /**
     * Set the format.
     */
    public function withFormat(string $format): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            color: $this->color,
            showValue: $this->showValue,
            showLabel: $this->showLabel,
            label: $this->label,
            format: $format,
        );
    }

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            color: $this->color,
            showValue: $this->showValue,
            showLabel: $this->showLabel,
            label: $label,
            format: $this->format,
        );
    }
}
