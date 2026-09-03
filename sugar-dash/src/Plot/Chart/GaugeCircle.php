<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

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
    private const NEEDLE = '❮';
    private const CENTER = '◆';

    /**
     * Terminal cells are roughly twice as wide as they are tall, so raw-cell
     * circles squash into vertical ellipses. 2.0 divides the vertical leg of
     * every stamped offset so the dial reads visually round — the Donut
     * default, adopted for gauges by the v5 R2 ruling (withAspect(1.0) is the
     * legacy raw-cell escape). Ported from Donut::DEFAULT_ASPECT.
     */
    private const DEFAULT_ASPECT = 2.0;

    /**
     * Sub-cell sample offsets for the smooth rim, in raw cell units on each
     * axis before the aspect scaling is applied, paired with the coverage bit
     * each quadrant contributes: bit0 top-left, bit1 top-right, bit2
     * bottom-left, bit3 bottom-right. A quadrant counts as covered when the
     * arc-band test passes at its sub-centre. Ported verbatim from
     * Donut::RIM_SAMPLES (private there — DRY extraction into a shared trait
     * is out of the v5 D3 ceiling, noted in chart_v5_plan.md).
     */
    private const RIM_SAMPLES = [
        [-0.25, -0.25, 1], // TL
        [0.25, -0.25, 2],  // TR
        [-0.25, 0.25, 4],  // BL
        [0.25, 0.25, 8],   // BR
    ];

    /**
     * Coverage bitmask → quadrant/block-drawing rune. Singles ▘▝▖▗, orthogonal
     * pairs ▀▄▌▐, diagonal pairs ▚▞, three-of-four ▛▜▙▟, full █. Mask 0 is
     * never looked up: the cell simply stays blank. Ported verbatim from
     * Donut::QUADRANT_RUNES.
     */
    private const QUADRANT_RUNES = [
        1 => '▘',
        2 => '▝',
        3 => '▀',
        4 => '▖',
        5 => '▌',
        6 => '▞',
        7 => '▛',
        8 => '▗',
        9 => '▚',
        10 => '▐',
        11 => '▜',
        12 => '▄',
        13 => '▙',
        14 => '▟',
        15 => '█',
    ];

    public function __construct(
        private readonly float $ratio,
        private readonly int $radius = 6,
        private readonly bool $showNeedle = true,
        private readonly bool $showTicks = true,
        private readonly bool $showLabel = true,
        private readonly ?Color $arcColor = null,
        private readonly ?Color $needleColor = null,
        private readonly ?Color $labelColor = null,
        // Appended after labelColor: 9 positional `new GaugeCircle(...)` test
        // call-sites (GaugeCircleTest :99/:108/:381-:404) make any earlier
        // insertion a TypeError cascade (chart_v5_plan.md D2/D3 constraint).
        private readonly ?float $aspect = null,
        private readonly bool $smoothRim = false,
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
     *
     * Once set, render() and getInnerSize() fit the dial to the allocation
     * instead of the constructor radius (v5 D2 geometry activation).
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Effective dial radius: allocation-fitted when setSize() has run
     * (v5 D2 geometry activation), otherwise the constructor radius —
     * the no-allocation path stays radius-driven (D3 moved its byte-identity
     * gate to withAspect(1.0); the default now renders aspect-corrected).
     *
     * Frame always fits the allocation at odd heights: usable =
     * min(width, height − labelRow), diameter = 2·floor((usable−1)/2)+1 ≤
     * usable. Floor 3 mirrors the withRadius() clamp. Raw-cell geometry:
     * radius stays in horizontal-cell units — the 2:1 terminal-cell aspect
     * correction (v5 D3) divides only the stamped vertical legs
     * (arc/ticks/needle), never this value, so the horizontal diameter and
     * the withAspect(1.0) legacy path stay untouched.
     */
    private function effectiveRadius(): int
    {
        if ($this->width === null || $this->sizerHeight === null) {
            return $this->radius;
        }

        $labelRows = $this->showLabel ? 1 : 0;
        $usable = min($this->width, $this->sizerHeight - $labelRows);

        return max(3, intdiv(max(0, $usable - 1), 2));
    }

    /**
     * Effective aspect ratio: explicit `withAspect()` value, else the default.
     * Mirrors Donut::aspect(); null ⇒ DEFAULT_ASPECT (R2: gauges ship round).
     */
    private function aspect(): float
    {
        return $this->aspect ?? self::DEFAULT_ASPECT;
    }

    /**
     * Render the circular gauge.
     */
    public function render(): string
    {
        $ratio = max(0.0, min(1.0, $this->ratio));
        $radius = $this->effectiveRadius();
        $diameter = ($radius * 2) + 1;
        $centerX = $radius;
        $centerY = $radius;
        $aspect = $this->aspect();

        // Initialize grid. Each cell carries its rune plus the Color that
        // paints it: quadrant-rim cells (D3) can share a mask rune between
        // filled and remainder sweeps, so colour identity must live on the
        // cell, not be re-derived from the rune.
        $blank = ['char' => ' ', 'color' => null];
        $grid = array_fill(0, $diameter, array_fill(0, $diameter, $blank));

        // Arc span: 270 degrees (from 135° to 45° clockwise, or 3*PI/4 to -PI/4)
        $arcStartAngle = 0.75 * M_PI; // 135° in standard math (bottom-left)
        $arcEndAngle = -0.25 * M_PI;  // -45° in standard math (bottom-right)
        $arcSpan = (2 * M_PI) - ($arcEndAngle - $arcStartAngle); // This should be 1.5 * PI

        // Draw the outer arc
        $arcAngle = 270; // degrees
        $arcPoints = (int) ($arcAngle * max(1, $radius));

        if ($this->smoothRim) {
            $grid = $this->stampSmoothArc($grid, $diameter, $centerX, $centerY, $radius, $aspect, $ratio);
        } else {
            for ($i = 0; $i <= $arcPoints; $i++) {
                $angleRatio = $i / $arcPoints;
                $angle = $arcStartAngle - ($angleRatio * 1.5 * M_PI); // 270° span going clockwise

                $x = $centerX + (int) round(cos($angle) * $radius);
                // Vertical leg divided by the aspect ratio (Donut ellipseCell
                // idiom) so the dial reads round on ~2:1 cells; division by
                // the 1.0 legacy value is bit-exact, freezing withAspect(1.0)
                // bytes to the pre-D3 geometry.
                $y = $centerY - (int) round(sin($angle) * $radius / $aspect);

                if ($x >= 0 && $x < $diameter && $y >= 0 && $y < $diameter) {
                    // Determine if this part of arc is "filled" based on ratio
                    $isFilled = $angleRatio <= $ratio;

                    // Uncolored mode keeps the colored path's shape contract:
                    // same $isFilled predicate, ○ for the remainder (C8a ring
                    // bug). Color null on both runes ⇒ bare emission.
                    $grid[$y][$x] = ['char' => $isFilled ? '●' : '○', 'color' => $this->arcColor];
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
                $ty = $centerY - (int) round(sin($tickAngle) * $tickRadiusOuter / $aspect);

                if ($tx >= 0 && $tx < $diameter && $ty >= 0 && $ty < $diameter) {
                    $grid[$ty][$tx] = ['char' => $t % 2 === 0 ? '┬' : '│', 'color' => null];
                }

                // Tick is slightly inside the arc
                $tickRadiusInner = $radius - 1;
                $tx = $centerX + (int) round(cos($tickAngle) * $tickRadiusInner);
                $ty = $centerY - (int) round(sin($tickAngle) * $tickRadiusInner / $aspect);

                if ($tx >= 0 && $tx < $diameter && $ty >= 0 && $ty < $diameter) {
                    $grid[$ty][$tx] = ['char' => $t % 2 === 0 ? '┴' : '│', 'color' => null];
                }
            }
        }

        // Draw the needle
        if ($this->showNeedle) {
            $needleAngle = $arcStartAngle - ($ratio * 1.5 * M_PI);
            $needleLength = $radius - 2;

            $nx = $centerX + (int) round(cos($needleAngle) * $needleLength);
            $ny = $centerY - (int) round(sin($needleAngle) * $needleLength / $aspect);

            if ($nx >= 0 && $nx < $diameter && $ny >= 0 && $ny < $diameter) {
                $grid[$ny][$nx] = ['char' => self::NEEDLE, 'color' => $this->needleColor];
            }
        }

        // Draw center point
        $grid[$centerY][$centerX] = ['char' => self::CENTER, 'color' => null];

        // Convert grid to string
        $result = '';
        for ($y = 0; $y < $diameter; $y++) {
            for ($x = 0; $x < $diameter; $x++) {
                $char = $grid[$y][$x]['char'];
                if ($char === ' ') {
                    $result .= ' ';
                    continue;
                }
                if ($grid[$y][$x]['color'] !== null) {
                    $result .= $grid[$y][$x]['color']->toFg(ColorProfile::TrueColor) . $char . Ansi::reset();
                    continue;
                }
                $result .= $char;
                // Legacy quirk kept byte-exact: uncolored ●/○/❮ still close
                // with a reset; ticks, the hub and bare quadrant runes never do.
                if (in_array($char, ['●', '○', self::NEEDLE], true)) {
                    $result .= Ansi::reset();
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
     * Supersample the arc band with quadrant-block runes (opt-in, D3 —
     * analogue of Donut::smoothRimCell()). GaugeCircle is a 270° arc, not a
     * ring, so there is no hole guard: the coverage shell is the ±0.5-cell
     * band around the arc radius, evaluated in the same aspect-scaled space
     * the binary stamping walks (offsets in raw cell units, vertical leg
     * multiplied by the aspect here — the scan-side face of the stamp-side
     * division). A sample counts only when it also lands inside the sweep;
     * the filled decision reuses the binary path's single predicate,
     * angleRatio ≤ ratio — majority of covered samples wins, a tie falls
     * back to the cell-centre predicate exactly like Donut's centre-segment
     * tie-break, so the C8a rule is never forked.
     *
     * @param array<int, array<int, array{char: string, color: ?Color}>> $grid
     * @return array<int, array<int, array{char: string, color: ?Color}>>
     */
    private function stampSmoothArc(array $grid, int $diameter, int $centerX, int $centerY, int $radius, float $aspect, float $ratio): array
    {
        // Cheap-reject margin: a sub-centre sample sits at raw offset
        // (±0.25, ±0.25) whose vertical leg is aspect-scaled on the stamp
        // side, so the sample drifts at most sqrt(0.25² + (0.25·aspect)²)
        // = 0.25·sqrt(1+aspect²) from its cell centre (≈0.559 at the
        // default 2.0 — the raw-cell √2 figure 0.354 would only hold for
        // aspect 1.0). A sample only ever counts inside the ±0.5 radius
        // band, so a centre farther than 0.5 + that drift from the arc
        // radius cannot contribute a covered quadrant. Derived from the
        // live $aspect rather than a constant: withAspect is public and
        // unbounded, and any fixed margin goes unsound past aspect ≈2.4.
        $rejectBand = 0.5 + 0.25 * sqrt(1.0 + $aspect * $aspect);

        for ($y = 0; $y < $diameter; $y++) {
            for ($x = 0; $x < $diameter; $x++) {
                $dx = (float) ($x - $centerX);
                $dy = -(($y - $centerY) * $aspect); // screen-down cells → math-up (binary loop's convention)

                $centerDist = sqrt($dx * $dx + $dy * $dy);
                if ($centerDist < $radius - $rejectBand || $centerDist > $radius + $rejectBand) {
                    continue;
                }

                $mask = 0;
                $covered = 0;
                $coveredFilled = 0;
                $firstFilled = null;

                foreach (self::RIM_SAMPLES as [$offsetX, $offsetY, $bit]) {
                    $sampleDx = $dx + $offsetX;
                    $sampleDy = $dy - $offsetY * $aspect; // RIM_SAMPLES offsets are screen-space (TL = −0.25,−0.25)
                    $sampleDist = sqrt($sampleDx * $sampleDx + $sampleDy * $sampleDy);

                    if ($sampleDist < $radius - 0.5 || $sampleDist > $radius + 0.5) {
                        continue;
                    }

                    $angleRatio = $this->arcRatioAt($sampleDx, $sampleDy);
                    if ($angleRatio === null) {
                        continue; // sample sits inside the 90° opening
                    }

                    $mask |= $bit;
                    $covered++;
                    $isFilled = $angleRatio <= $ratio;
                    if ($isFilled) {
                        $coveredFilled++;
                    }
                    $firstFilled ??= $isFilled;
                }

                if ($mask === 0) {
                    continue;
                }

                if ($coveredFilled * 2 > $covered) {
                    $cellFilled = true;
                } elseif ($coveredFilled * 2 < $covered) {
                    $cellFilled = false;
                } else {
                    $centerRatio = $this->arcRatioAt($dx, $dy);
                    $cellFilled = $centerRatio !== null
                        ? $centerRatio <= $ratio
                        : ($firstFilled ?? false); // centre sits in the opening → first covered sample decides
                }

                $grid[$y][$x] = [
                    'char' => self::QUADRANT_RUNES[$mask],
                    // Remainder quadrants stay uncolored (the ○ analogue);
                    // in uncolored mode the rim reads as pure AA shape.
                    'color' => $cellFilled ? $this->arcColor : null,
                ];
            }
        }

        return $grid;
    }

    /**
     * Position along the 270° sweep (0 = start at 135°, 1 = end at −135°) for
     * an aspect-scaled math-up offset, or null when the point falls inside
     * the 90° opening. Inverts the binary loop's `angle = arcStartAngle −
     * angleRatio · 1.5π` so both rim paths share one sweep mapping and one
     * filled predicate (angleRatio ≤ ratio).
     */
    private function arcRatioAt(float $dx, float $dyMath): ?float
    {
        $angle = atan2($dyMath, $dx); // −π..π, math convention
        $ratio = (0.75 * M_PI - $angle) / (1.5 * M_PI);

        return ($ratio >= 0.0 && $ratio <= 1.0) ? $ratio : null;
    }

    /**
     * Calculate the natural dimensions of this gauge.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $diameter = ($this->effectiveRadius() * 2) + 1;
        $labelHeight = $this->showLabel ? 1 : 0;
        return [$diameter, $diameter + $labelHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────
    //
    // Allocation-preserving pattern (GaugeWithDetail::withValue precedent):
    // build the new self, then re-apply setSize() when both dimensions are
    // stored — otherwise a wither silently drops the layout allocation.

    /**
     * Set the radius of the gauge (the no-allocation fallback size).
     */
    public function withRadius(int $radius): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: max(3, $radius),
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Show or hide the needle.
     */
    public function withShowNeedle(bool $show): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $show,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Show or hide tick marks.
     */
    public function withShowTicks(bool $show): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $show,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Show or hide the percentage label.
     */
    public function withShowLabel(bool $show): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $show,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
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
        $clone = new self(
            ratio: max(0.0, min(1.0, $ratio)),
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Set the arc color.
     */
    public function withArcColor(?Color $color): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $color,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Set the needle color.
     */
    public function withNeedleColor(?Color $color): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $color,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $color,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Correct the 2:1 terminal-cell squash so the dial reads visually round.
     *
     * null (and a bare call) render at DEFAULT_ASPECT 2.0 — the Donut default
     * adopted for gauges by the v5 R2 ruling; withAspect(1.0) restores the
     * legacy raw-cell geometry byte-exactly.
     *
     * A non-positive or non-finite ratio would collapse or invert the ellipse
     * (or poison every stamping computation with NaN), silently distorting the
     * dial rather than failing — so the value is rejected up front, mirroring
     * the B5 throw guard of Donut::withAspect().
     *
     * @throws \InvalidArgumentException on a non-finite or non-positive ratio
     */
    public function withAspect(float $ratio = self::DEFAULT_ASPECT): self
    {
        if (!is_finite($ratio) || $ratio <= 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid gauge aspect ratio "%s"; expected a finite positive float.',
                $ratio
            ));
        }

        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $ratio,
            smoothRim: $this->smoothRim,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Supersample the arc rim with quadrant-block runes.
     *
     * The default rim is binary (●/○ at each stamped cell centre), which
     * staircases one whole cell per step along the arc edge. Testing the
     * ±0.5-cell band at each cell's four quadrant sub-centres (Donut's
     * sampling, adapted to the 270° sweep) and emitting the matching
     * ▘▝▖▗▀▄▌▐▚▞▛▜▙▟ rune doubles the perceived radial resolution.
     *
     * Default off: the legacy byte-identical ●/○ rim is kept for existing
     * consumers and goldens (Donut precedent).
     */
    public function withSmoothRim(bool $on = true): self
    {
        $clone = new self(
            ratio: $this->ratio,
            radius: $this->radius,
            showNeedle: $this->showNeedle,
            showTicks: $this->showTicks,
            showLabel: $this->showLabel,
            arcColor: $this->arcColor,
            needleColor: $this->needleColor,
            labelColor: $this->labelColor,
            aspect: $this->aspect,
            smoothRim: $on,
        );

        return $this->preserveAllocation($clone);
    }

    /**
     * Re-apply this gauge's layout allocation onto a wither clone so
     * setSize() survives the fluent chain (StackedGrid consumes
     * setSize(...)->render(); a dropped allocation silently reverts the
     * dial to ctor-radius geometry).
     */
    private function preserveAllocation(self $clone): self
    {
        if ($this->width !== null && $this->sizerHeight !== null) {
            return $clone->setSize($this->width, $this->sizerHeight);
        }

        return $clone;
    }
}
