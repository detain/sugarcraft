<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A multi-segment progress bar component.
 *
 * Features:
 * - Multiple colored segments
 * - Each segment has its own ratio of the total width
 * - Optional segment labels
 * - Customizable segment colors
 * - Shows overall percentage or individual segment values
 *
 * Mirrors segmented progress bar concepts adapted to PHP with wither-style immutable setters.
 */
final class Progress implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, ratio: float, color: Color|null}> $segments
     */
    public function __construct(
        private readonly array $segments,
        private readonly bool $showLabels = true,
        private readonly bool $showPercentages = true,
        private readonly string $segmentSeparator = '',
    ) {}

    /**
     * Create a new progress bar with default styling.
     *
     * Default: single purple segment filling the given ratio.
     */
    public static function new(float $ratio): self
    {
        return new self(
            segments: [['label' => '', 'ratio' => max(0.0, min(1.0, $ratio)), 'color' => Color::hex('#874BFD')]],
            showLabels: false,
            showPercentages: true,
            segmentSeparator: '',
        );
    }

    /**
     * Create a multi-segment progress bar.
     *
     * @param list<array{label: string, ratio: float}> $segments
     */
    public static function segmented(array $segments): self
    {
        $normalizedSegments = array_map(function (array $segment): array {
            return [
                'label' => $segment['label'] ?? '',
                'ratio' => max(0.0, min(1.0, $segment['ratio'])),
                'color' => $segment['color'] ?? Color::hex('#874BFD'),
            ];
        }, $segments);

        return new self(
            segments: $normalizedSegments,
            showLabels: true,
            showPercentages: true,
            segmentSeparator: '',
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
        $useWidth = $this->getWidth();

        if ($useWidth <= 0) {
            return '';
        }

        if (count($this->segments) === 0) {
            return str_repeat(' ', $useWidth);
        }

        // Calculate total ratio and individual segment widths
        $totalRatio = 0.0;
        foreach ($this->segments as $segment) {
            $totalRatio += $segment['ratio'];
        }

        // Normalize if total exceeds 1.0
        $normalizationFactor = $totalRatio > 0 ? 1.0 / $totalRatio : 1.0;

        $result = '';
        $currentPosition = 0;

        foreach ($this->segments as $segment) {
            $normalizedRatio = $segment['ratio'] * $normalizationFactor;
            $segmentWidth = (int) floor($normalizedRatio * $useWidth);

            if ($segmentWidth <= 0) {
                continue;
            }

            $color = $segment['color'];
            $label = $segment['label'];

            // Apply segment color
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }

            // Render the segment bar
            if ($this->showLabels && $label !== '') {
                // Show label in segment
                $labelWidth = Width::string($label);
                if ($labelWidth <= $segmentWidth) {
                    $result .= $label;
                    $result .= str_repeat('█', $segmentWidth - $labelWidth);
                } else {
                    // Label too long, truncate
                    $result .= mb_substr($label, 0, $segmentWidth, 'UTF-8');
                }
            } else {
                $result .= str_repeat('█', $segmentWidth);
            }

            if ($color !== null) {
                $result .= Ansi::reset();
            }

            $currentPosition += $segmentWidth;
        }

        // Fill remaining space with empty character
        $filledWidth = (int) floor(($totalRatio > 1.0 ? 1.0 : $totalRatio) * $useWidth);
        $remainingWidth = $useWidth - $filledWidth;
        if ($remainingWidth > 0) {
            $result .= str_repeat('░', $remainingWidth);
        }

        // Add percentage if enabled
        if ($this->showPercentages) {
            $percentage = (int) round(min(100, $totalRatio * 100));
            $result .= sprintf(' %d%%', $percentage);
        }

        // Reset ANSI
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Get the width to use for the progress bar.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return 40; // Default width
    }

    /**
     * Calculate the natural dimensions of this progress bar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        $percentageOffset = $this->showPercentages ? 5 : 0; // " 100%" = 5 chars max
        return [$width + $percentageOffset, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the segments.
     *
     * @param list<array{label: string, ratio: float, color: Color|null}> $segments
     */
    public function withSegments(array $segments): self
    {
        return new self(
            segments: $segments,
            showLabels: $this->showLabels,
            showPercentages: $this->showPercentages,
            segmentSeparator: $this->segmentSeparator,
        );
    }

    /**
     * Show or hide segment labels.
     */
    public function withShowLabels(bool $show): self
    {
        return new self(
            segments: $this->segments,
            showLabels: $show,
            showPercentages: $this->showPercentages,
            segmentSeparator: $this->segmentSeparator,
        );
    }

    /**
     * Show or hide percentages.
     */
    public function withShowPercentages(bool $show): self
    {
        return new self(
            segments: $this->segments,
            showLabels: $this->showLabels,
            showPercentages: $show,
            segmentSeparator: $this->segmentSeparator,
        );
    }

    /**
     * Set the width.
     */
    public function withWidth(int $width): self
    {
        return new self(
            segments: $this->segments,
            showLabels: $this->showLabels,
            showPercentages: $this->showPercentages,
            segmentSeparator: $this->segmentSeparator,
        );
    }
}
