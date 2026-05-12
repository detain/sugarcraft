<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A slider / progress control component.
 *
 * Displays a value along a horizontal or vertical track:
 * - Configurable min/max/current values
 * - Custom track and thumb characters
 * - Optional value label display
 * - Customizable colors
 *
 * Mirrors the slider concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Slider implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $value,
        private readonly float $min = 0.0,
        private readonly float $max = 100.0,
        private readonly bool $vertical = false,
        private readonly ?Color $trackColor = null,
        private readonly ?Color $thumbColor = null,
        private readonly bool $showValue = true,
        private readonly string $thumbChar = '●',
        private readonly string $trackChar = '─',
    ) {}

    /**
     * Create a new horizontal slider with default styling.
     *
     * Default: purple thumb, gray track.
     */
    public static function new(float $value, float $min = 0.0, float $max = 100.0): self
    {
        return new self(
            value: $value,
            min: $min,
            max: $max,
            vertical: false,
            trackColor: Color::ansi(8),
            thumbColor: Color::hex('#874BFD'),
            showValue: true,
            thumbChar: '●',
            trackChar: '─',
        );
    }

    /**
     * Create a vertical slider.
     */
    public static function vertical(float $value, float $min = 0.0, float $max = 100.0): self
    {
        return new self(
            value: $value,
            min: $min,
            max: $max,
            vertical: true,
            trackColor: Color::ansi(8),
            thumbColor: Color::hex('#874BFD'),
            showValue: true,
            thumbChar: '●',
            trackChar: '│',
        );
    }

    /**
     * Set the allocated dimensions for this slider.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the slider as a string.
     */
    public function render(): string
    {
        if ($this->vertical) {
            return $this->renderVertical();
        }
        return $this->renderHorizontal();
    }

    /**
     * Render a horizontal slider.
     */
    private function renderHorizontal(): string
    {
        $trackWidth = $this->width ?? 30;
        $trackWidth = max(3, $trackWidth);

        $clampedValue = max($this->min, min($this->max, $this->value));
        $range = $this->max - $this->min;
        $ratio = $range > 0 ? ($clampedValue - $this->min) / $range : 0.0;
        $thumbPosition = (int) round($ratio * ($trackWidth - 1));

        $result = '';

        // Track color if set
        if ($this->trackColor !== null) {
            $result .= $this->trackColor->toFg(ColorProfile::TrueColor);
        }

        // Build the track
        $track = '';
        for ($i = 0; $i < $trackWidth; $i++) {
            if ($i === $thumbPosition) {
                // Thumb position
                if ($this->thumbColor !== null) {
                    $track .= Ansi::reset();
                    $track .= $this->thumbColor->toFg(ColorProfile::TrueColor);
                }
                $track .= $this->thumbChar;
                if ($this->trackColor !== null) {
                    $track .= Ansi::reset();
                    $track .= $this->trackColor->toFg(ColorProfile::TrueColor);
                }
            } else {
                $track .= $this->trackChar;
            }
        }

        $result .= $track;

        // Value label
        if ($this->showValue) {
            // Reset before value so value appears at end for regex matching
            if ($this->trackColor !== null || $this->thumbColor !== null) {
                $result .= Ansi::reset();
            }
            $valueLabel = sprintf(' %d ', (int) $clampedValue);
            $result .= $valueLabel;
        }

        // Reset ANSI at end
        if ($this->trackColor !== null || $this->thumbColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a vertical slider.
     */
    private function renderVertical(): string
    {
        $trackHeight = $this->height ?? 10;
        $trackHeight = max(3, $trackHeight);

        $clampedValue = max($this->min, min($this->max, $this->value));
        $range = $this->max - $this->min;
        $ratio = $range > 0 ? ($clampedValue - $this->min) / $range : 0.0;
        $thumbPosition = (int) round($ratio * ($trackHeight - 1));

        $result = '';

        // Track color if set
        if ($this->trackColor !== null) {
            $result .= $this->trackColor->toFg(ColorProfile::TrueColor);
        }

        // Build the track (from top to bottom)
        for ($i = 0; $i < $trackHeight; $i++) {
            // Calculate which row we're on (0 = top)
            $rowFromTop = $i;
            $thumbRowFromTop = $thumbPosition;

            if ($rowFromTop === $thumbRowFromTop) {
                // Thumb position
                if ($this->thumbColor !== null) {
                    $result .= Ansi::reset();
                    $result .= $this->thumbColor->toFg(ColorProfile::TrueColor);
                }
                $result .= $this->thumbChar . "\n";
                if ($this->trackColor !== null) {
                    $result .= Ansi::reset();
                    $result .= $this->trackColor->toFg(ColorProfile::TrueColor);
                }
            } else {
                $result .= $this->trackChar . "\n";
            }
        }

        // Reset ANSI
        if ($this->trackColor !== null || $this->thumbColor !== null) {
            $result .= Ansi::reset();
        }

        // Remove trailing newline and add value label
        $result = rtrim($result, "\n");

        if ($this->showValue) {
            // Reset before value so value appears at end for regex matching
            if ($this->trackColor !== null || $this->thumbColor !== null) {
                $result .= Ansi::reset();
            }
            $valueLabel = ' ' . (int) $clampedValue;
            $result .= $valueLabel;
        }

        // Reset ANSI at end
        if ($this->trackColor !== null || $this->thumbColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this slider.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->vertical) {
            $height = $this->height ?? 10;
            $width = 1; // thumb char width
            if ($this->showValue) {
                $width += 4; // space for value
            }
            return [$width, $height];
        }

        $width = $this->width ?? 30;
        $labelOffset = $this->showValue ? 5 : 0; // space for value label
        return [$width + $labelOffset, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the current value.
     */
    public function withValue(float $value): self
    {
        return new self(
            value: $value,
            min: $this->min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set the minimum value.
     */
    public function withMin(float $min): self
    {
        return new self(
            value: $this->value,
            min: $min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set the maximum value.
     */
    public function withMax(float $max): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set the value range.
     */
    public function withRange(float $min, float $max): self
    {
        return new self(
            value: $this->value,
            min: $min,
            max: $max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Show or hide the value label.
     */
    public function withShowValue(bool $show): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $show,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set the track color.
     */
    public function withTrackColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $color,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set the thumb color.
     */
    public function withThumbColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $color,
            showValue: $this->showValue,
            thumbChar: $this->thumbChar,
            trackChar: $this->trackChar,
        );
    }

    /**
     * Set custom thumb and track characters.
     */
    public function withChars(string $thumb, string $track): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            vertical: $this->vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $thumb,
            trackChar: $track,
        );
    }

    /**
     * Make the slider vertical.
     */
    public function withVertical(bool $vertical): self
    {
        return new self(
            value: $this->value,
            min: $this->min,
            max: $this->max,
            vertical: $vertical,
            trackColor: $this->trackColor,
            thumbColor: $this->thumbColor,
            showValue: $this->showValue,
            thumbChar: $vertical ? '●' : ($this->thumbChar === '●' ? '●' : $this->thumbChar),
            trackChar: $vertical ? '│' : ($this->trackChar === '│' ? '─' : $this->trackChar),
        );
    }
}
