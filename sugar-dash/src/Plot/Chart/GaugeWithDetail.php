<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;

/**
 * A horizontal gauge bar with detail text overlaid in the center.
 *
 * Renders as: [label] [filled blocks] [detail text] [empty blocks] [pct]
 * Example: DATA   ███████ 105G/250G ░░░░░░░░  42%
 *
 * Mirrors Homedash internal_ui_components_gauge.go:36-89
 */
final class GaugeWithDetail implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $label = '',
        private readonly float $value = 0,
        private readonly float $max = 100,
        private readonly string $detail = '',
        private readonly int $widthConstraint = 40,
    ) {}

    /**
     * Create a new gauge with detail overlay.
     */
    public static function new(
        string $label = '',
        float $value = 0,
        float $max = 100,
        string $detail = '',
        int $width = 40,
    ): self {
        return new self($label, $value, $max, $detail, $width);
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
     * Render the gauge with detail overlay as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        $filledRatio = $this->max > 0 ? $this->value / $this->max : 0.0;
        $filledRatio = max(0.0, min(1.0, $filledRatio));

        $pctStr = sprintf('%3.0f%%', $filledRatio * 100);

        // Bar width = total width - label area (7) - detail - space before pct - pct
        $detailLen = \strlen($this->detail);
        $barWidth = $width - 7 - $detailLen - 1 - 4;

        // Minimum bar width of 5
        if ($barWidth < 5) {
            $barWidth = 5;
        }

        // If bar is wide enough for detail (+2 for surrounding spaces)
        if ($detailLen + 2 <= $barWidth) {
            $filled = (int) floor($filledRatio * $barWidth);
            if ($filled > $barWidth) {
                $filled = $barWidth;
            }

            // Center position for the detail text
            $pad = (int) (($barWidth - $detailLen) / 2);
            $padRight = $barWidth - $detailLen - $pad;

            // Build the bar string
            $bar = '';

            // Left portion of bar (before detail)
            if ($pad <= $filled) {
                $bar .= str_repeat('█', $pad);
            } else {
                $bar .= str_repeat('█', $filled);
                $bar .= str_repeat('░', $pad - $filled);
            }

            // Detail text (bold)
            $bar .= Ansi::sgr(Ansi::BOLD);
            $bar .= $this->detail;
            $bar .= Ansi::reset();

            // Right portion of bar (after detail)
            $rightStart = $pad + $detailLen;
            if ($rightStart < $filled) {
                $bar .= str_repeat('█', $filled - $rightStart);
                $bar .= str_repeat('░', $padRight - ($filled - $rightStart));
            } else {
                $remaining = $barWidth - $rightStart;
                if ($remaining < 0) {
                    $remaining = 0;
                }
                $bar .= str_repeat('░', $remaining);
            }

            // Assemble final output: label + space + bar + space + pct
            return $this->renderLabel() . ' ' . $bar . ' ' . $pctStr;
        }

        // Fall back to compact gauge without detail
        return $this->renderCompact($width, $filledRatio, $pctStr);
    }

    /**
     * Render the label portion.
     */
    private function renderLabel(): string
    {
        $labelLen = \strlen($this->label);
        // Pad label to 6 chars (4 + 2 spaces) for visual alignment
        $paddedLabel = $this->label;
        if ($labelLen < 4) {
            $paddedLabel = str_pad($this->label, 4, ' ', STR_PAD_RIGHT);
        } elseif ($labelLen > 4) {
            // Truncate if longer
            $paddedLabel = substr($this->label, 0, 4);
        }

        // Apply bold styling and return with trailing space
        return Ansi::sgr(Ansi::BOLD) . $paddedLabel . Ansi::reset() . '  ';
    }

    /**
     * Render a compact gauge without detail text.
     */
    private function renderCompact(int $width, float $filledRatio, string $pctStr): string
    {
        $barWidth = $width - 7 - 1 - 4; // label area + space + pct
        if ($barWidth < 5) {
            $barWidth = 5;
        }

        $filled = (int) floor($filledRatio * $barWidth);
        $empty = $barWidth - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return $this->renderLabel() . $bar . ' ' . $pctStr;
    }

    /**
     * Calculate the natural dimensions of this gauge.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        // Height is always 1 for horizontal gauge
        return [$width > 0 ? $width : $this->widthConstraint, 1];
    }

    /**
     * Get the width to use for the gauge.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the current value.
     */
    public function withValue(float $value): self
    {
        $clone = new self(
            label: $this->label,
            value: $value,
            max: $this->max,
            detail: $this->detail,
            widthConstraint: $this->widthConstraint,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    /**
     * Set the maximum value.
     */
    public function withMax(float $max): self
    {
        $clone = new self(
            label: $this->label,
            value: $this->value,
            max: $max,
            detail: $this->detail,
            widthConstraint: $this->widthConstraint,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    /**
     * Set the detail text.
     */
    public function withDetail(string $detail): self
    {
        $clone = new self(
            label: $this->label,
            value: $this->value,
            max: $this->max,
            detail: $detail,
            widthConstraint: $this->widthConstraint,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        $clone = new self(
            label: $this->label,
            value: $this->value,
            max: $this->max,
            detail: $this->detail,
            widthConstraint: $width,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    /**
     * Set the label text.
     */
    public function withLabel(string $label): self
    {
        $clone = new self(
            label: $label,
            value: $this->value,
            max: $this->max,
            detail: $this->detail,
            widthConstraint: $this->widthConstraint,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }
}
