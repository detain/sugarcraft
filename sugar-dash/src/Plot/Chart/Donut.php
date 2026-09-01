<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A donut chart component for displaying proportional data.
 *
 * Features:
 * - Multiple data segments with customizable colors
 * - Optional center text (label, value, or percentage)
 * - Configurable inner/outer radius
 * - Start angle for rotation
 * - Clockwise or counter-clockwise rendering
 *
 * Mirrors donut/pie chart patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Donut implements \SugarCraft\Dash\Foundation\Sizer
{
    /**
     * Terminal cells are roughly twice as wide as they are tall, so distances
     * measured in raw cell units squash circles into vertical ellipses. 2.0
     * pre-scales the vertical leg to make the ring read as visually round.
     */
    private const DEFAULT_ASPECT = 2.0;

    /**
     * Sub-cell sample offsets for the smooth rim, in raw cell units on each
     * axis before the aspect scaling is applied (so the supersampling test
     * runs in the same aspect-scaled space as the cell-center ring test),
     * paired with the coverage bit each quadrant contributes:
     * bit0 top-left, bit1 top-right, bit2 bottom-left, bit3 bottom-right.
     * A quadrant counts as covered when the annulus test passes at its center.
     */
    private const RIM_SAMPLES = [
        [-0.25, -0.25, 1], // TL
        [0.25, -0.25, 2],  // TR
        [-0.25, 0.25, 4],  // BL
        [0.25, 0.25, 8],   // BR
    ];

    /**
     * Coverage bitmask → quadrant/block-drawing rune. Singles ▘▝▖▗, orthogonal
     * pairs ▀▄▌▐, diagonal pairs ▚▞, three-of-four ▛▜▙▟ (missing BR/BL/TR/TL
     * per the Unicode block shapes), full █. Mask 0 is never looked up: the
     * cell simply stays blank.
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

    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, value: float, color: Color|null}> $segments
     */
    public function __construct(
        private readonly array $segments,
        private readonly int $size = 20,
        private readonly ?string $centerLabel = null,
        private readonly ?string $centerValue = null,
        private readonly ?Color $backgroundColor = null,
        private readonly bool $showPercentage = false,
        private readonly float $startAngle = 0.0,
        private readonly bool $clockwise = true,
        private readonly ?float $aspect = null,
        private readonly bool $smoothRim = false,
    ) {}

    /**
     * Create a new donut chart with the given data.
     *
     * @param list<array{label: string, value: float, color?: string|Color|null}> $data
     */
    public static function new(array $data): self
    {
        $segments = array_map(function (array $item): array {
            $color = $item['color'] ?? null;
            if (is_string($color)) {
                $color = Color::hex($color);
            }
            return [
                'label' => $item['label'],
                'value' => max(0.0, $item['value']),
                'color' => $color,
            ];
        }, $data);

        return new self(
            segments: $segments,
            size: 20,
            centerLabel: null,
            centerValue: null,
            backgroundColor: Color::hex('#313244'),
            showPercentage: false,
            startAngle: 0.0,
            clockwise: true,
            aspect: null,
            smoothRim: false,
        );
    }

    /**
     * Create a donut chart with default Catppuccin Mocha theme colors.
     *
     * @param list<array{label: string, value: float}> $data
     */
    public static function mocha(array $data): self
    {
        $colors = [
            Color::hex('#F38BA8'), // Pink
            Color::hex('#A6E3A1'), // Green
            Color::hex('#89B4FA'), // Blue
            Color::hex('#F9E2AF'), // Yellow
            Color::hex('#CBA6F7'), // Mauve
            Color::hex('#94E2D5'), // Teal
            Color::hex('#FAB387'), // Peach
            Color::hex('#74C7EC'), // Sky
        ];

        $segments = array_map(function (array $item) use ($colors, &$colorIndex): array {
            $color = $colors[$colorIndex % count($colors)];
            $colorIndex++;
            return [
                'label' => $item['label'],
                'value' => max(0.0, $item['value']),
                'color' => $color,
            ];
        }, $data);

        $colorIndex = 0;

        return new self(
            segments: $segments,
            size: 20,
            centerLabel: null,
            centerValue: null,
            backgroundColor: Color::hex('#313244'),
            showPercentage: false,
            startAngle: 0.0,
            clockwise: true,
            aspect: null,
            smoothRim: false,
        );
    }

    /**
     * Set the allocated dimensions for this chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this donut chart.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useSize = min($this->width ?? $this->size, $this->height ?? $this->size);
        return [$useSize, $useSize];
    }

    /**
     * Effective aspect ratio: explicit `withAspect()` value, else the default.
     */
    private function aspect(): float
    {
        return $this->aspect ?? self::DEFAULT_ASPECT;
    }

    /**
     * Render the donut chart using Unicode block characters.
     */
    public function render(): string
    {
        $total = array_sum(array_column($this->segments, 'value'));

        if ($total <= 0 || $this->segments === []) {
            return $this->renderEmpty();
        }

        $size = min($this->width ?? $this->size, $this->height ?? $this->size);
        $radius = (int) floor($size / 2) - 1;
        $innerRadius = (int) floor($radius * 0.5);

        // Build the donut as a grid of characters
        $grid = [];
        for ($y = 0; $y < $size; $y++) {
            $grid[$y] = array_fill(0, $size, ['char' => ' ', 'color' => null]);
        }

        $centerX = (int) floor($size / 2);
        $centerY = (int) floor($size / 2);
        $aspect = $this->aspect();

        // Fill the donut ring
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $centerX;
                $dy = ($y - $centerY) * $aspect;
                // Vertical leg scaled by the aspect ratio so the ring is round
                // on ~2:1 cells; radius stays in horizontal-cell units, keeping
                // the horizontal diameter (and the withAspect(1.0) legacy path)
                // unchanged.
                $dist = sqrt($dx * $dx + $dy * $dy);

                if ($this->smoothRim) {
                    $cell = $this->smoothRimCell($x, $y, $centerX, $centerY, $aspect, $dist, $innerRadius, $radius, $total);
                    if ($cell !== null) {
                        $grid[$y][$x] = $cell;
                    }
                    continue;
                }

                // Check if point is in the donut ring
                if ($dist >= $innerRadius && $dist <= $radius) {
                    // Aspect-normalized so sector boundaries follow the same
                    // ellipse the distance test draws, not the raw cell grid.
                    $angle = atan2($dy, $dx);
                    // Convert to degrees, normalize to 0-360
                    $angleDeg = $angle * 180 / M_PI;
                    if ($angleDeg < 0) {
                        $angleDeg += 360;
                    }

                    // Adjust for start angle
                    $adjustedAngle = $angleDeg - $this->startAngle;
                    if ($adjustedAngle < 0) {
                        $adjustedAngle += 360;
                    }

                    // Find which segment this angle belongs to
                    $segmentIndex = $this->findSegment($adjustedAngle, $total);
                    if ($segmentIndex !== null) {
                        $grid[$y][$x] = [
                            'char' => '█',
                            'color' => $this->segments[$segmentIndex]['color'],
                        ];
                    }
                }
            }
        }

        // Render the grid
        $result = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $cell = $grid[$y][$x];
                if ($cell['color'] !== null) {
                    $result .= $cell['color']->toFg(ColorProfile::TrueColor);
                }
                $result .= $cell['char'];
                if ($cell['color'] !== null) {
                    $result .= Ansi::reset();
                }
            }
            $result .= "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Quadrant supersample of one cell for the smooth rim (see withSmoothRim()).
     *
     * The hole guard runs on the cell centre — a centre already inside the
     * hole never gets a rune — while the four sub-centres decide coverage for
     * every other cell, antialiasing both the outer and the inner rim from
     * the ring side only. Samples whose angle belongs to no segment (the
     * legacy CCW gaps) stay blank, mirroring the binary path.
     *
     * @return array{char: string, color: Color|null}|null null keeps the cell blank
     */
    private function smoothRimCell(
        int $x,
        int $y,
        int $centerX,
        int $centerY,
        float $aspect,
        float $centerDist,
        int $innerRadius,
        int $radius,
        float $total,
    ): ?array {
        if ($centerDist < $innerRadius) {
            return null;
        }

        $dx = $x - $centerX;
        $dyCell = $y - $centerY;

        $mask = 0;
        /** @var array<int, int> $coverage segment index => covered quadrant count */
        $coverage = [];
        $firstSegment = null;

        foreach (self::RIM_SAMPLES as [$offsetX, $offsetY, $bit]) {
            $sampleDx = $dx + $offsetX;
            $sampleDy = ($dyCell + $offsetY) * $aspect;
            $sampleDist = sqrt($sampleDx * $sampleDx + $sampleDy * $sampleDy);

            if ($sampleDist < $innerRadius || $sampleDist > $radius) {
                continue;
            }

            $segment = $this->segmentAt($sampleDx, $sampleDy, $total);
            if ($segment === null) {
                continue;
            }

            $mask |= $bit;
            $coverage[$segment] = ($coverage[$segment] ?? 0) + 1;
            $firstSegment ??= $segment;
        }

        if ($mask === 0) {
            return null;
        }

        $maxCount = max($coverage);
        $winners = array_keys(array_filter($coverage, static fn(int $count): bool => $count === $maxCount));

        // Majority quadrant wins the cell colour; ties fall back to the
        // segment the legacy per-cell centre pick would have chosen.
        $segmentIndex = count($winners) === 1
            ? $winners[0]
            : ($this->segmentAt($dx, $dyCell * $aspect, $total) ?? $firstSegment);

        return [
            'char' => self::QUADRANT_RUNES[$mask],
            'color' => $this->segments[$segmentIndex]['color'],
        ];
    }

    /**
     * Segment owning an aspect-scaled offset from the donut centre, using the
     * same angle normalisation as the cell-centre test in render() so a
     * quadrant sample lands in the sector its cell would have.
     */
    private function segmentAt(float $dx, float $dyScaled, float $total): ?int
    {
        $angle = atan2($dyScaled, $dx);
        // Convert to degrees, normalize to 0-360
        $angleDeg = $angle * 180 / M_PI;
        if ($angleDeg < 0) {
            $angleDeg += 360;
        }

        // Adjust for start angle
        $adjustedAngle = $angleDeg - $this->startAngle;
        if ($adjustedAngle < 0) {
            $adjustedAngle += 360;
        }

        return $this->findSegment($adjustedAngle, $total);
    }

    /**
     * Render an empty donut chart.
     */
    private function renderEmpty(): string
    {
        $size = min($this->width ?? $this->size, $this->height ?? $this->size);
        $radius = (int) floor($size / 2) - 1;
        $innerRadius = (int) floor($radius * 0.5);

        $result = '';
        $centerX = (int) floor($size / 2);
        $centerY = (int) floor($size / 2);
        $aspect = $this->aspect();

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $centerX;
                // Same aspect correction as render()'s ring so the empty
                // placeholder ring matches the filled one's shape.
                $dy = ($y - $centerY) * $aspect;
                $dist = sqrt($dx * $dx + $dy * $dy);

                if ($dist >= $innerRadius && $dist <= $radius) {
                    $result .= '░';
                } else {
                    $result .= ' ';
                }
            }
            $result .= "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Find which segment an angle belongs to.
     */
    private function findSegment(float $angle, float $total): ?int
    {
        $segmentAngle = 360.0 / $total;

        $segmentCount = count($this->segments);
        for ($i = 0; $i < $segmentCount; $i++) {
            $segmentValue = $this->segments[$i]['value'];
            $segmentSpan = $segmentValue * $segmentAngle;

            if ($this->clockwise) {
                // Clockwise: 0° is at right, goes clockwise
                if ($angle < $segmentSpan) {
                    return $i;
                }
                $angle -= $segmentSpan;
            } else {
                // Counter-clockwise: 0° is at right, goes counter-clockwise
                $startAngle = 360.0 - $segmentSpan;
                if ($angle >= $startAngle || $angle < $segmentSpan - (360.0 - $startAngle)) {
                    return $i;
                }
            }
        }

        return null;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chart size.
     */
    public function withSize(int $size): self
    {
        return new self(
            segments: $this->segments,
            size: $size,
            centerLabel: $this->centerLabel,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Set the center label text.
     */
    public function withCenterLabel(?string $label): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $label,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Set the center value text.
     */
    public function withCenterValue(?string $value): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $this->centerLabel,
            centerValue: $value,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Show percentage in center.
     */
    public function withShowPercentage(bool $show): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $this->centerLabel,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $show,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Set the start angle in degrees.
     */
    public function withStartAngle(float $angle): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $this->centerLabel,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $angle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Set the aspect-ratio correction applied to the vertical cell axis.
     *
     * Cells are ~1:2 (w:h), so raw cell-unit distances stretch the ring
     * vertically; the ratio scales the vertical leg of every distance/angle
     * computation instead. 1.0 reproduces the legacy (uncorrected) geometry
     * byte-exactly, 2.0 is the visually-round default.
     */
    public function withAspect(float $ratio = self::DEFAULT_ASPECT): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $this->centerLabel,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $ratio,
            smoothRim: $this->smoothRim,
        );
    }

    /**
     * Supersample the annulus boundary with quadrant-block runes.
     *
     * The default rim is binary (a cell is `█` or blank at its centre), which
     * staircases one whole cell per step along the ring edge. Testing the ring
     * at each cell's four quadrant centres (±0.25 cell offsets, evaluated in
     * the aspect-scaled space render() uses) and emitting the matching
     * ▘▝▖▗▀▄▌▐▚▞▛▜▙▟ rune doubles the perceived radial resolution while the
     * blocks stay solid — unlike braille, which reads dotty. Applies to both
     * the outer rim and the inner-hole rim; cells whose centre falls inside
     * the hole always stay blank so smoothing never bleeds into the centre.
     *
     * Default off: the legacy byte-identical path is kept for existing
     * consumers and goldens.
     */
    public function withSmoothRim(bool $on = true): self
    {
        return new self(
            segments: $this->segments,
            size: $this->size,
            centerLabel: $this->centerLabel,
            centerValue: $this->centerValue,
            backgroundColor: $this->backgroundColor,
            showPercentage: $this->showPercentage,
            startAngle: $this->startAngle,
            clockwise: $this->clockwise,
            aspect: $this->aspect,
            smoothRim: $on,
        );
    }
}
