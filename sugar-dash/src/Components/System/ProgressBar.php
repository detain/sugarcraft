<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A simple horizontal progress bar component.
 *
 * Features:
 * - Displays progress as filled bar with percentage
 * - Customizable width, filled/empty characters, and colors
 * - Optional percentage label positioning (before, after, or hidden)
 * - Gradient color support based on progress ratio
 *
 * Mirrors progress bar concepts adapted to PHP with wither-style immutable setters.
 * Distinct from Gauge which is more feature-rich with label formatting.
 */
final class ProgressBar implements \SugarCraft\Dash\Foundation\Sizer
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
        private readonly bool $labelAfter = true,
    ) {}

    /**
     * Create a new progress bar with default styling.
     *
     * Default: purple filled bar, shows percentage after, 30 chars wide.
     */
    public static function new(float $ratio): self
    {
        $ratio = max(0.0, min(1.0, $ratio));
        return new self(
            ratio: $ratio,
            widthConstraint: 30,
            showPercentage: true,
            filledColor: Color::hex('#874BFD'),
            emptyColor: null,
            filledChar: '█',
            emptyChar: '░',
            labelAfter: true,
        );
    }

    /**
     * Set the allocated dimensions for this progress bar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the progress bar as a string.
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

        // Add percentage text if enabled
        if ($this->showPercentage) {
            $label = sprintf('%d%%', $percentage);
            if ($this->labelAfter) {
                $bar .= ' ' . $label;
            } else {
                $bar = $label . ' ' . $bar;
            }
        }

        // Add ANSI reset at the very end if we used any colors
        if ($this->filledColor !== null || $this->emptyColor !== null) {
            $bar .= Ansi::reset();
        }

        return $bar;
    }

    /**
     * Get the width to use for the progress bar.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? 0;
    }

    /**
     * Calculate the natural dimensions of this progress bar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        // Progress bar is a single-line component
        $labelOffset = $this->showPercentage ? 5 : 0; // " 100%" = 5 chars max
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
            labelAfter: $this->labelAfter,
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
            labelAfter: $this->labelAfter,
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
            labelAfter: $this->labelAfter,
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
            labelAfter: $this->labelAfter,
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
            labelAfter: $this->labelAfter,
        );
    }

    /**
     * Set a new ratio value.
     */
    public function withRatio(float $ratio): self
    {
        $ratio = max(0.0, min(1.0, $ratio));
        return new self(
            ratio: $ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            labelAfter: $this->labelAfter,
        );
    }

    /**
     * Set whether label appears after or before the bar.
     */
    public function withLabelAfter(bool $after): self
    {
        return new self(
            ratio: $this->ratio,
            widthConstraint: $this->widthConstraint,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            labelAfter: $after,
        );
    }
}