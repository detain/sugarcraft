<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A horizontal bullet chart component.
 *
 * Displays a primary measure (actual) compared to a target, with an
 * optional comparative measure. The chart shows whether the actual
 * value has reached, exceeded, or fallen short of the target.
 *
 * Mirrors bullet chart concepts from bubbletea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Bullet implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    public function __construct(
        private readonly float $actual,
        private readonly float $target,
        private readonly ?int $widthConstraint = null,
        private readonly bool $showTarget = true,
        private readonly bool $showComparative = false,
        private readonly ?float $comparative = null,
        private readonly ?Color $actualColor = null,
        private readonly ?Color $targetColor = null,
        private readonly ?Color $comparativeColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly string $filledChar = '█',
        private readonly string $emptyChar = '░',
        private readonly string $targetChar = '│',
        private readonly string $comparativeChar = '─',
    ) {}

    /**
     * Create a new bullet chart with default styling.
     *
     * @param float $actual The actual value achieved
     * @param float $target The target value to achieve
     */
    public static function new(float $actual, float $target): self
    {
        return new self(
            actual: $actual,
            target: $target,
            widthConstraint: 50,
            showTarget: true,
            showComparative: false,
            comparative: null,
            actualColor: Color::hex('#89B4FA'),
            targetColor: Color::hex('#F38BA8'),
            comparativeColor: Color::hex('#A6E3A1'),
            backgroundColor: null,
            filledChar: '█',
            emptyChar: '░',
            targetChar: '│',
            comparativeChar: '─',
        );
    }

    /**
     * Set the allocated dimensions for this bullet chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the bullet chart as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        // Normalize values to determine bar lengths
        $maxValue = max(abs($this->actual), abs($this->target));
        if ($this->showComparative && $this->comparative !== null) {
            $maxValue = max($maxValue, abs($this->comparative));
        }

        if ($maxValue <= 0) {
            $maxValue = 1;
        }

        // Calculate widths
        $actualWidth = (int) floor(abs($this->actual) / $maxValue * $width);
        $targetPos = (int) floor(abs($this->target) / $maxValue * $width);
        $comparativePos = $this->comparative !== null
            ? (int) floor(abs($this->comparative) / $maxValue * $width)
            : null;

        // Build the bullet bar
        $bar = '';

        // Add comparative marker if enabled
        if ($this->showComparative && $comparativePos !== null) {
            for ($i = 0; $i < $width; $i++) {
                if ($i === $comparativePos) {
                    if ($this->comparativeColor !== null) {
                        $bar .= $this->comparativeColor->toFg(ColorProfile::TrueColor);
                    }
                    $bar .= $this->comparativeChar;
                    if ($this->comparativeColor !== null) {
                        $bar .= Ansi::reset();
                    }
                } else {
                    $bar .= ' ';
                }
            }
            $bar .= "\n";
        }

        // Add the main bar (actual vs background)
        for ($i = 0; $i < $width; $i++) {
            if ($i < $actualWidth) {
                // Filled portion (actual)
                if ($this->actualColor !== null) {
                    $bar .= $this->actualColor->toFg(ColorProfile::TrueColor);
                }
                $bar .= $this->filledChar;
                if ($this->actualColor !== null) {
                    $bar .= Ansi::reset();
                }
            } else {
                // Empty portion (available range)
                if ($this->backgroundColor !== null) {
                    $bar .= $this->backgroundColor->toFg(ColorProfile::TrueColor);
                }
                $bar .= $this->emptyChar;
                if ($this->backgroundColor !== null) {
                    $bar .= Ansi::reset();
                }
            }
        }

        // Add target marker
        if ($this->showTarget && $targetPos <= $width) {
            $bar .= ' ';
            if ($this->targetColor !== null) {
                $bar .= $this->targetColor->toFg(ColorProfile::TrueColor);
            }
            $bar .= $this->targetChar . ' ';
            if ($this->targetColor !== null) {
                $bar .= Ansi::reset();
            }

            // Add target value label
            $bar .= sprintf('%.1f', $this->target);
        }

        // Add ANSI reset at the very end if we used any colors
        if ($this->actualColor !== null || $this->targetColor !== null || $this->backgroundColor !== null) {
            $bar .= Ansi::reset();
        }

        return $bar;
    }

    /**
     * Get the width to use for the bullet chart.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? 0;
    }

    /**
     * Calculate the natural dimensions of this bullet chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return [0, $this->showComparative ? 2 : 1];
        }

        // Width includes bar + target marker space
        $labelWidth = $this->showTarget ? 8 : 0; // " │ target" = up to 8 chars
        $height = $this->showComparative ? 2 : 1;

        return [$width + $labelWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the actual value.
     */
    public function withActual(float $actual): self
    {
        return new self(
            actual: $actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the target value.
     */
    public function withTarget(float $target): self
    {
        return new self(
            actual: $this->actual,
            target: $target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the width constraint.
     */
    public function withWidth(int $width): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $width,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Show or hide the target marker.
     */
    public function withShowTarget(bool $show): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $show,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Show or hide the comparative measure.
     */
    public function withShowComparative(bool $show): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $show,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the comparative value.
     */
    public function withComparative(?float $value): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $value,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the actual value color.
     */
    public function withActualColor(?Color $color): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $color,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the target color.
     */
    public function withTargetColor(?Color $color): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $color,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the comparative color.
     */
    public function withComparativeColor(?Color $color): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $color,
            backgroundColor: $this->backgroundColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $color,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }

    /**
     * Set custom characters for filled and empty portions.
     */
    public function withChars(string $filled, string $empty): self
    {
        return new self(
            actual: $this->actual,
            target: $this->target,
            widthConstraint: $this->widthConstraint,
            showTarget: $this->showTarget,
            showComparative: $this->showComparative,
            comparative: $this->comparative,
            actualColor: $this->actualColor,
            targetColor: $this->targetColor,
            comparativeColor: $this->comparativeColor,
            backgroundColor: $this->backgroundColor,
            filledChar: $filled,
            emptyChar: $empty,
            targetChar: $this->targetChar,
            comparativeChar: $this->comparativeChar,
        );
    }
}
