<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A horizontal progress bar / gauge component.
 *
 * Displays a ratio as a filled bar with optional percentage label.
 * Supports custom widths, colors for filled/empty portions,
 * and text formatting (percentage shown/hidden, ratio display).
 *
 * Mirrors the gauge concept from bubble-gauge/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Gauge implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $ratio,
        private readonly ?int $widthConstraint = null,
        private readonly bool $showPercentage = true,
        private readonly ?Color $filledColor = null,
        private readonly ?Color $emptyColor = null,
        private readonly string $filledChar = '█',
        private readonly string $emptyChar = '░',
    ) {}

    /**
     * Create a new gauge with default styling.
     *
     * Default: purple filled bar, shows percentage, 40 chars wide.
     */
    public static function new(float $ratio): self
    {
        // Clamp ratio to valid range
        $ratio = max(0.0, min(1.0, $ratio));
        return new self(
            ratio: $ratio,
            widthConstraint: 40,
            showPercentage: true,
            filledColor: Color::hex('#874BFD'),
            emptyColor: null,
            filledChar: '█',
            emptyChar: '░',
        );
    }

    /**
     * Set the allocated dimensions for this gauge.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the gauge as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        $ratio = max(0.0, min(1.0, $this->ratio));
        $filledWidth = (int) floor($ratio * $width);
        $emptyWidth = $width - $filledWidth;
        $percentage = (int) round($ratio * 100);

        // Build the filled and empty portions
        $filledPart = str_repeat($this->filledChar, $filledWidth);
        $emptyPart = str_repeat($this->emptyChar, $emptyWidth);

        // Build the bar with colors
        $bar = '';
        if ($this->filledColor !== null && $filledWidth > 0) {
            $bar .= $this->filledColor->toFg(ColorProfile::TrueColor);
            $bar .= $filledPart;
        } else {
            $bar .= $filledPart;
        }

        if ($this->emptyColor !== null && $emptyWidth > 0) {
            $bar .= Ansi::reset();
            $bar .= $this->emptyColor->toFg(ColorProfile::TrueColor);
            $bar .= $emptyPart;
        } elseif ($emptyWidth > 0) {
            $bar .= $emptyPart;
        }

        // Add percentage text if enabled - label appears after the bar, before final reset
        if ($this->showPercentage) {
            $label = sprintf(' %d%% ', $percentage);
            $bar .= $label;
        }

        // Add ANSI reset at the very end if we used any colors
        if ($this->filledColor !== null || $this->emptyColor !== null) {
            $bar .= Ansi::reset();
        }

        return $bar;
    }

    /**
     * Get the width to use for the gauge.
     */
    private function getWidth(): int
    {
        // Priority: setSize width > widthConstraint from constructor
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? 0;
    }

    /**
     * Calculate the natural dimensions of this gauge.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        // Gauge is a single-line component
        $labelOffset = $this->showPercentage ? 5 : 0; // " 100% " = 5 chars max
        return [max(0, $width + $labelOffset), 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the width constraint (number of bar characters).
     */
    public function withWidth(int $width): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $width,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Show or hide the percentage label.
     */
    public function withPercentage(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $show,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the color for the filled portion.
     */
    public function withFilledColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $color,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the color for the empty portion.
     */
    public function withEmptyColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $color,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set custom characters for filled and empty portions.
     */
    public function withChars(string $filled, string $empty): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $filled,
            emptyChar: $empty,
        );
    }

    /**
     * Set a new ratio value.
     */
    public function withRatio(float $ratio): self
    {
        // Clamp ratio to valid range
        $ratio = max(0.0, min(1.0, $ratio));
        return new self(
            ratio: $ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }
}
