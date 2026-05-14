<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Layout\HAlign;

/**
 * A single stat display component showing a label and value.
 *
 * Features:
 * - Large formatted value display
 * - Optional label and sub-label
 * - Trend indicator (up/down arrow)
 * - Customizable colors and formatting
 * - Alignment options
 *
 * Mirrors stat/metric patterns adapted to PHP with wither-style immutable setters.
 */
final class Stat implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly ?string $label,
        private readonly ?string $value,
        private readonly ?string $subLabel = null,
        private readonly ?Color $valueColor = null,
        private readonly ?Color $labelColor = null,
        private readonly ?string $trend = null, // 'up', 'down', or null
        private readonly HAlign $align = HAlign::Left,
        private readonly int $decimalPlaces = 0,
    ) {}

    /**
     * Create a new stat display.
     */
    public static function new(?string $label = null, ?string $value = null): self
    {
        return new self(
            label: $label,
            value: $value,
            subLabel: null,
            valueColor: Color::hex('#F38BA8'),
            labelColor: Color::hex('#6C7086'),
            trend: null,
            align: HAlign::Left,
            decimalPlaces: 0,
        );
    }

    /**
     * Create a stat from a number.
     */
    public static function number(?string $label, float $value, int $decimalPlaces = 0): self
    {
        $formatted = number_format($value, $decimalPlaces);
        return self::new($label, $formatted)->withDecimalPlaces($decimalPlaces);
    }

    /**
     * Create a percentage stat.
     */
    public static function percent(?string $label, float $value): self
    {
        $formatted = number_format($value, 1) . '%';
        return self::new($label, $formatted);
    }

    /**
     * Create a currency stat.
     */
    public static function currency(?string $label, float $value, string $symbol = '$'): self
    {
        $formatted = $symbol . number_format($value, 2);
        return self::new($label, $formatted);
    }

    /**
     * Set the allocated dimensions for this stat.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this stat.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? max(
            mb_strlen($this->label ?? '', 'UTF-8'),
            mb_strlen($this->value ?? '', 'UTF-8'),
            mb_strlen($this->subLabel ?? '', 'UTF-8')
        ) + 4;

        $height = 1;
        if ($this->label !== null) {
            $height++;
        }
        if ($this->subLabel !== null) {
            $height++;
        }

        return [$useWidth, $height];
    }

    /**
     * Render the stat display.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 20;

        $lines = [];

        // Label line
        if ($this->label !== null) {
            $labelStr = $this->padOrTruncate($this->label, $useWidth, $this->align);
            if ($this->labelColor !== null) {
                $labelStr = $this->labelColor->toFg(ColorProfile::TrueColor) . $labelStr . Ansi::reset();
            }
            $lines[] = $labelStr;
        }

        // Value line (can include trend)
        $valueStr = $this->value ?? '';
        if ($this->trend === 'up') {
            $valueStr .= ' ↑';
        } elseif ($this->trend === 'down') {
            $valueStr .= ' ↓';
        }
        $valueStr = $this->padOrTruncate($valueStr, $useWidth, $this->align);
        if ($this->valueColor !== null) {
            $valueStr = $this->valueColor->toFg(ColorProfile::TrueColor) . $valueStr . Ansi::reset();
        }
        $lines[] = $valueStr;

        // Sub-label line
        if ($this->subLabel !== null) {
            $subStr = $this->padOrTruncate($this->subLabel, $useWidth, $this->align);
            if ($this->labelColor !== null) {
                $subStr = $this->labelColor->toFg(ColorProfile::TrueColor) . $subStr . Ansi::reset();
            }
            $lines[] = $subStr;
        }

        return implode("\n", $lines);
    }

    /**
     * Pad or truncate a string to fit the width.
     */
    private function padOrTruncate(string $str, int $width, HAlign $align): string
    {
        $len = mb_strlen($str, 'UTF-8');
        if ($len > $width) {
            return mb_substr($str, 0, $width, 'UTF-8');
        }

        $padding = $width - $len;
        return match ($align) {
            HAlign::Left, HAlign::Center => str_repeat(' ', $padding) . $str,
            HAlign::Right => $str . str_repeat(' ', $padding),
        };
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            label: $label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the value.
     */
    public function withValue(?string $value): self
    {
        return new self(
            label: $this->label,
            value: $value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the sub-label.
     */
    public function withSubLabel(?string $subLabel): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $color,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $color,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the trend indicator.
     */
    public function withTrend(?string $trend): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $trend,
            align: $this->align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set the alignment.
     */
    public function withAlign(HAlign $align): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $align,
            decimalPlaces: $this->decimalPlaces,
        );
    }

    /**
     * Set decimal places for number formatting.
     */
    public function withDecimalPlaces(int $places): self
    {
        return new self(
            label: $this->label,
            value: $this->value,
            subLabel: $this->subLabel,
            valueColor: $this->valueColor,
            labelColor: $this->labelColor,
            trend: $this->trend,
            align: $this->align,
            decimalPlaces: $places,
        );
    }
}
