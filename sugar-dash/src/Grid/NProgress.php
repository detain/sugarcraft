<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A YouTube-style thin progress bar component.
 *
 * Renders a slim horizontal progress indicator commonly used for page loading,
 * file upload progress, or step completion tracking.
 *
 * Mirrors the nprogress concept adapted to PHP with wither-style immutable setters.
 */
final class NProgress implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly float $ratio = 0.0,
        private readonly ?Color $color = null,
        private readonly ?Color $trackColor = null,
        private readonly bool $showPercentage = false,
    ) {}

    /**
     * Create a new nprogress bar with default styling.
     *
     * Default: purple bar on dark track.
     */
    public static function new(float $ratio = 0.0): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            color: Color::hex('#874BFD'),
            trackColor: Color::hex('#3F3F46'),
            showPercentage: false,
        );
    }

    /**
     * Create a loading-state nprogress (indeterminate).
     *
     * Uses a pulsing animation effect.
     */
    public static function loading(): self
    {
        return new self(
            ratio: -1.0, // Indeterminate
            color: Color::hex('#874BFD'),
            trackColor: Color::hex('#3F3F46'),
            showPercentage: false,
        );
    }

    /**
     * Set the allocated dimensions for this progress bar.
     */
    public function setSize(int $width, int $height): Sizer
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

        $result = '';

        // Track (background)
        if ($this->trackColor !== null) {
            $result .= $this->trackColor->toBg(ColorProfile::TrueColor);
        }

        $barWidth = $this->getBarWidth($useWidth);

        if ($this->ratio < 0) {
            // Indeterminate state - pulsing bar
            $result .= $this->renderIndeterminate($useWidth);
        } else {
            // Determinate state - filled bar
            if ($this->color !== null) {
                $result .= $this->color->toBg(ColorProfile::TrueColor);
            }
            $result .= str_repeat('█', $barWidth);

            // Remaining track
            if ($this->trackColor !== null) {
                $result .= Ansi::reset();
                $result .= $this->trackColor->toBg(ColorProfile::TrueColor);
            }
            $result .= str_repeat('░', $useWidth - $barWidth);
        }

        // Percentage text
        if ($this->showPercentage && $this->ratio >= 0) {
            $pct = (int) round($this->ratio * 100);
            $pctStr = sprintf(' %d%%', $pct);
            $result .= Ansi::reset();
            if ($this->color !== null) {
                $result .= $this->color->toFg(ColorProfile::TrueColor);
            }
            $result .= $pctStr;
        }

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render indeterminate (loading) state with a moving bar.
     */
    private function renderIndeterminate(int $totalWidth): string
    {
        // Calculate a position based on current time for animation effect
        $tick = (int) (microtime(true) * 1000);
        $position = ($tick / 20) % ($totalWidth + 20) - 10;
        $barWidth = (int) ($totalWidth * 0.3); // Bar is 30% of track

        $barStart = max(0, (int) $position);
        $barEnd = min($totalWidth, $barStart + $barWidth);

        $output = '';
        for ($i = 0; $i < $totalWidth; $i++) {
            if ($i >= $barStart && $i < $barEnd) {
                if ($this->color !== null) {
                    $output .= $this->color->toBg(ColorProfile::TrueColor);
                }
                $output .= '█';
            } else {
                if ($this->trackColor !== null) {
                    $output .= $this->trackColor->toBg(ColorProfile::TrueColor);
                }
                $output .= '░';
            }
        }

        return $output;
    }

    /**
     * Get the width of the filled bar portion.
     */
    private function getBarWidth(int $totalWidth): int
    {
        if ($this->ratio < 0) {
            return 0;
        }
        return (int) round($this->ratio * $totalWidth);
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
        $percentageOffset = $this->showPercentage && $this->ratio >= 0 ? 5 : 0;
        return [$width + $percentageOffset, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the progress ratio (0.0 to 1.0).
     */
    public function withRatio(float $ratio): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            color: $this->color,
            trackColor: $this->trackColor,
            showPercentage: $this->showPercentage,
        );
    }

    /**
     * Set the bar color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            color: $color,
            trackColor: $this->trackColor,
            showPercentage: $this->showPercentage,
        );
    }

    /**
     * Set the track (background) color.
     */
    public function withTrackColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            color: $this->color,
            trackColor: $color,
            showPercentage: $this->showPercentage,
        );
    }

    /**
     * Show or hide the percentage text.
     */
    public function withShowPercentage(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            color: $this->color,
            trackColor: $this->trackColor,
            showPercentage: $show,
        );
    }
}
