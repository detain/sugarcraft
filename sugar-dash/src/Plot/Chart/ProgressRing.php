<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A circular progress ring component.
 *
 * Displays a ratio as a circular progress indicator using
 * box-drawing characters. Supports custom radii, colors,
 * and optional percentage label.
 *
 * Mirrors progress ring concepts adapted to PHP with wither-style immutable setters.
 */
final class ProgressRing implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /** Ratio in [0.0, 1.0] — enforced by the constructor, never re-checked downstream. */
    private readonly float $ratio;

    public function __construct(
        float $ratio,
        private readonly int $radius = 4,
        private readonly bool $showPercentage = true,
        private readonly ?Color $filledColor = null,
        private readonly ?Color $emptyColor = null,
    ) {
        // E733 (round 83) — the single clamp boundary. Every ratio enters the
        // object through this constructor (new() factory, withRatio() wither,
        // direct construction alike), so [0.0, 1.0] is parsed into trusted
        // state exactly once here; render() and friends consume it raw.
        $this->ratio = max(0.0, min(1.0, $ratio));
    }

    /**
     * Create a new progress ring with default styling.
     *
     * Default: purple ring, 4-char radius, shows percentage.
     */
    public static function new(float $ratio): self
    {
        return new self(
            ratio: $ratio,
            radius: 4,
            showPercentage: true,
            filledColor: Color::hex('#874BFD'),
            emptyColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this ring.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the progress ring as a string.
     *
     * Renders a circular progress indicator using box-drawing characters.
     * The ring is rendered in rows, with filled portion showing progress.
     */
    public function render(): string
    {
        // Ratio was parsed into trusted [0.0, 1.0] state by the constructor
        // (the single clamp boundary) — render reads it raw.
        $ratio = $this->ratio;
        $diameter = $this->radius * 2;
        $centerX = $this->radius;
        $centerY = $this->radius;

        // Build the ring row by row
        $rows = [];
        for ($y = 0; $y < $diameter; $y++) {
            $row = '';
            for ($x = 0; $x < $diameter; $x++) {
                $dx = $x - $centerX + 0.5;
                $dy = $y - $centerY + 0.5;
                $dist = sqrt($dx * $dx + $dy * $dy);
                $normalizedDist = $dist / $this->radius;

                // Ring is between 0.5 and 1.0 of radius
                if ($normalizedDist >= 0.45 && $normalizedDist <= 1.0) {
                    // Calculate angle (0 at top, going clockwise)
                    $angle = atan2($dy, $dx) + M_PI / 2;
                    if ($angle < 0) {
                        $angle += 2 * M_PI;
                    }
                    $angleRatio = $angle / (2 * M_PI);

                    // Determine if this position should be filled
                    $isFilled = $angleRatio <= $ratio;

                    if ($isFilled) {
                        if ($this->filledColor !== null) {
                            $row .= $this->filledColor->toFg(ColorProfile::TrueColor);
                        }
                        $row .= '●';
                        if ($this->filledColor !== null) {
                            $row .= Ansi::reset();
                        }
                    } else {
                        if ($this->emptyColor !== null) {
                            $row .= $this->emptyColor->toFg(ColorProfile::TrueColor);
                        }
                        $row .= '○';
                        if ($this->emptyColor !== null) {
                            $row .= Ansi::reset();
                        }
                    }
                } else {
                    $row .= ' ';
                }
            }
            $rows[] = $row;
        }

        $result = implode("\n", $rows);

        // Add percentage label at bottom if enabled
        if ($this->showPercentage) {
            $percentage = (int) round($ratio * 100);
            $label = sprintf(' %d%% ', $percentage);
            if ($this->filledColor !== null) {
                $label = $this->filledColor->toFg(ColorProfile::TrueColor) . $label . Ansi::reset();
            }
            $result .= "\n" . $label;
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this ring.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $diameter = $this->radius * 2;
        $labelHeight = $this->showPercentage ? 2 : 0;
        return [$diameter, $diameter + $labelHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the radius of the ring.
     */
    public function withRadius(int $radius): self
    {
        return new self(
            ratio: $this->ratio,
            radius: max(1, $radius),
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Show or hide the percentage label.
     */
    public function withPercentage(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showPercentage: $show,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Set the ratio value.
     */
    public function withRatio(float $ratio): self
    {
        // The constructor clamps — no re-clamp here (E733 single boundary).
        return new self(
            ratio: $ratio,
            radius: $this->radius,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Set the color for the filled portion.
     */
    public function withFilledColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showPercentage: $this->showPercentage,
            filledColor: $color,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Set the color for the empty portion.
     */
    public function withEmptyColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showPercentage: $this->showPercentage,
            filledColor: $this->filledColor,
            emptyColor: $color,
        );
    }
}
