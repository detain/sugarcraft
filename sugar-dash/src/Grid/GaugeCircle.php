<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A circular gauge component (speedometer style).
 *
 * Displays a ratio as a circular gauge with a needle indicator.
 * The gauge shows a circular arc with tick marks and a needle pointing
 * to the current value. Supports custom radii and colors.
 *
 * Mirrors speedometer/gauge-circle concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class GaugeCircle implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Characters for rendering the gauge.
     */
    private const ARC_CHARS = ['╭', '─', '╮', '│', '╯', '╰'];
    private const TICK_SMALL = '·';
    private const TICK_LARGE = '┼';
    private const NEEDLE = '❮';
    private const CENTER = '◆';

    public function __construct(
        private readonly float $ratio,
        private readonly int $radius = 6,
        private readonly bool $showNeedle = true,
        private readonly bool $showTicks = true,
        private readonly bool $showLabel = true,
        private readonly ?Color $arcColor = null,
        private readonly ?Color $needleColor = null,
        private readonly ?Color $labelColor = null,
    ) {}

    /**
     * Create a new circular gauge with default styling.
     *
     * Default: purple arc, red needle, 6-char radius.
     */
    public static function new(float $ratio): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            radius: 6,
            showNeedle: true,
            showTicks: true,
            showLabel: true,
            arcColor: Color::hex('#874BFD'),
            needleColor: Color::hex('#FF6B6B'),
            labelColor: Color::hex('#FFFFFF'),
        );
    }

    /**
     * Set the allocated dimensions for this gauge.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Calculate position on the arc given an angle and radius.
     */
    private function calculateArcPosition(float $angle, int $radius): array
    {
        // Angle: 0 = top, increases clockwise
        // We span from ~225 degrees (bottom-left) to ~315 degrees (bottom-right)
        // This is a 270-degree arc (3/4 circle)
        $startAngle = 0.75 * M_PI; // 135 degrees (bottom-left)
        $endAngle = -0.25 * M_PI;  // -45 degrees (bottom-right) in standard math
        // But PHP's atan2: 0 = right, PI/2 = bottom, PI = left, -PI/2 = top
        // We want arc from bottom-left (3*PI/4) to bottom-right (-PI/4)
        // That spans 270 degrees clockwise

        $currentAngle = $startAngle - ($angle * 1.5 * M_PI); // 270 degrees total span

        $x = $radius + (int) round(cos($currentAngle) * $radius);
        $y = $radius - (int) round(sin($currentAngle) * $radius); // Invert Y for display

        return [$x, $y];
    }

    /**
     * Render the circular gauge.
     */
    public function render(): string
    {
        $ratio = max(0.0, min(1.0, $this->ratio));
        $radius = $this->radius;
        $diameter = ($radius * 2) + 1;
        $centerX = $radius;
        $centerY = $radius;

        // Initialize grid
        $grid = array_fill(0, $diameter, array_fill(0, $diameter, ' '));

        // Arc span: 270 degrees (from 135° to 45° clockwise, or 3*PI/4 to -PI/4)
        $arcStartAngle = 0.75 * M_PI; // 135° in standard math (bottom-left)
        $arcEndAngle = -0.25 * M_PI;  // -45° in standard math (bottom-right)
        $arcSpan = (2 * M_PI) - ($arcEndAngle - $arcStartAngle); // This should be 1.5 * PI

        // Draw the outer arc
        $arcAngle = 270; // degrees
        $arcPoints = (int) ($arcAngle * max(1, $radius));

        for ($i = 0; $i <= $arcPoints; $i++) {
            $angleRatio = $i / $arcPoints;
            $angle = $arcStartAngle - ($angleRatio * 1.5 * M_PI); // 270° span going clockwise

            $x = $centerX + (int) round(cos($angle) * $radius);
            $y = $centerY - (int) round(sin($angle) * $radius); // Invert Y

            if ($x >= 0 && $x < $diameter && $y >= 0 && $y < $diameter) {
                // Determine if this part of arc is "filled" based on ratio
                $isFilled = $angleRatio <= $ratio;

                if ($isFilled && $this->arcColor !== null) {
                    $grid[$y][$x] = '●';
                } elseif ($this->arcColor !== null) {
                    $grid[$y][$x] = '○';
                } else {
                    $grid[$y][$x] = '●';
                }
            }
        }

        // Draw tick marks
        if ($this->showTicks) {
            $tickCount = 11; // 0%, 10%, ... 100%
            for ($t = 0; $t <= $tickCount - 1; $t++) {
                $tickRatio = $t / ($tickCount - 1);
                $tickAngle = $arcStartAngle - ($tickRatio * 1.5 * M_PI);

                // Tick is slightly outside the arc
                $tickRadiusOuter = $radius + 1;
                $tx = $centerX + (int) round(cos($tickAngle) * $tickRadiusOuter);
                $ty = $centerY - (int) round(sin($tickAngle) * $tickRadiusOuter);

                if ($tx >= 0 && $tx < $diameter && $ty >= 0 && $ty < $diameter) {
                    $grid[$ty][$tx] = $t % 2 === 0 ? '┬' : '│';
                }

                // Tick is slightly inside the arc
                $tickRadiusInner = $radius - 1;
                $tx = $centerX + (int) round(cos($tickAngle) * $tickRadiusInner);
                $ty = $centerY - (int) round(sin($tickAngle) * $tickRadiusInner);

                if ($tx >= 0 && $tx < $diameter && $ty >= 0 && $ty < $diameter) {
                    $grid[$ty][$tx] = $t % 2 === 0 ? '┴' : '│';
                }
            }
        }

        // Draw the needle
        if ($this->showNeedle) {
            $needleAngle = $arcStartAngle - ($ratio * 1.5 * M_PI);
            $needleLength = $radius - 2;

            $nx = $centerX + (int) round(cos($needleAngle) * $needleLength);
            $ny = $centerY - (int) round(sin($needleAngle) * $needleLength);

            if ($nx >= 0 && $nx < $diameter && $ny >= 0 && $ny < $diameter) {
                $grid[$ny][$nx] = '❮';
            }
        }

        // Draw center point
        $grid[$centerY][$centerX] = '◆';

        // Convert grid to string
        $result = '';
        for ($y = 0; $y < $diameter; $y++) {
            for ($x = 0; $x < $diameter; $x++) {
                $char = $grid[$y][$x];
                if ($char !== ' ') {
                    if (in_array($char, ['●', '○'], true)) {
                        if ($this->arcColor !== null) {
                            $result .= $this->arcColor->toFg(ColorProfile::TrueColor);
                        }
                    } elseif ($char === '❮' && $this->needleColor !== null) {
                        $result .= $this->needleColor->toFg(ColorProfile::TrueColor);
                    }
                    $result .= $char;
                    if (in_array($char, ['●', '○', '❮'], true)) {
                        $result .= Ansi::reset();
                    }
                } else {
                    $result .= ' ';
                }
            }
            $result .= "\n";
        }

        // Add percentage label at bottom
        if ($this->showLabel) {
            $percentage = (int) round($ratio * 100);
            $label = sprintf(' %d%% ', $percentage);
            if ($this->labelColor !== null) {
                $label = $this->labelColor->toFg(ColorProfile::TrueColor) . $label . Ansi::reset();
            }
            $result .= $label;
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this gauge.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $diameter = ($this->radius * 2) + 1;
        $labelHeight = $this->showLabel ? 1 : 0;
        return [$diameter, $diameter + $labelHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the radius of the gauge.
     */
    public function withRadius(int $radius): self
    {
        return new self(
            ratio: $this->ratio,
            radius: max(3, $radius),
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide the needle.
     */
    public function withShowNeedle(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $show,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide tick marks.
     */
    public function withShowTicks(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $show,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide the percentage label.
     */
    public function withShowLabel(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $show,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Show or hide the percentage label (alias for withShowLabel).
     */
    public function withPercentage(bool $show): self
    {
        return $this->withShowLabel($show);
    }

    /**
     * Set the ratio value.
     */
    public function withRatio(float $ratio): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the arc color.
     */
    public function withArcColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $color,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the needle color.
     */
    public function withNeedleColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $color,
            labelColor: $this->labelColor,
        );
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $color,
        );
    }
}
