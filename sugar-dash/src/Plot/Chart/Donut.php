<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\Bresenham;

/**
 * A donut chart component for displaying proportional data.
 *
 * Features:
 * - Multiple data segments with customizable colors
 * - Optional center text: up to two mb-truncated lines (a value, or the
 *   first segment's percentage, plus an optional label) inside the hole
 * - Configurable inner/outer radius
 * - Start angle for rotation
 * - Clockwise or counter-clockwise rendering
 * - Filled (default) or wireframe/outline render mode
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

    /**
     * Fill styles accepted by withFillStyle(): 'foreground' paints each filled
     * cell as a segment-coloured `█` glyph (the classic per-cell toFg wrap),
     * 'background' paints filled runs as plain spaces under the segment's
     * background SGR — the gapless trick already used by Canvas::render() and
     * Bar::render(), immune to the hairline seams adjacent `█` glyphs leave on
     * some fonts/line-spacings.
     */
    public const FILL_FOREGROUND = 'foreground';
    public const FILL_BACKGROUND = 'background';

    /**
     * Render modes accepted by withRenderMode(): 'filled' paints the annulus
     * cells (the classic S4-S6 behaviour, byte-identical default), 'wireframe'
     * draws only the outline primitives — tangent-bucketed rim runes, one
     * radial divider per segment boundary, and a hub cell — so the chart reads
     * on B/W terminals and at small sizes where block fills smear into a blob.
     */
    public const RENDER_FILLED = 'filled';
    public const RENDER_WIREFRAME = 'wireframe';

    /**
     * Axis-aligned arc pieces for the wireframe rim: corner, horizontal,
     * corner, vertical, corner, horizontal... order is TL, ─, TR, │, BR, BL.
     * Formerly mirrored from GaugeCircle::ARC_CHARS, a private table B12
     * deleted — this class is now its only home (v5 D3 provenance fix); used
     * for the flat/steep runs the tangent buckets emit and as the
     * cardinal-box-aligned corner fallback.
     */
    private const ARC_CHARS = ['╭', '─', '╮', '│', '╯', '╰'];

    /**
     * Cell-space slope threshold separating a rim/divider segment from its
     * neighbour bucket: ±22.5° around each axis reads as flat or steep, the
     * diagonals in between take ╱/╲ (tan 22.5° ≈ 0.414).
     */
    private const WIRE_SLOPE_FLAT = 0.414;

    /**
     * [unit-dx, unit-dy (screen-down), rounded corner, ARC_CHARS index] for
     * the four rim quadrants. The rounded ◜◝◟◞ rune reads as part of a circle
     * at normal sizes; when the corner cell lands exactly on a cardinal axis
     * (small radii where the ellipse "turns the box") the sharper ARC_CHARS
     * box corner is the honest glyph instead.
     */
    private const WIRE_CORNERS = [
        [0.70710678, -0.70710678, '◝', 2],  // NE
        [-0.70710678, -0.70710678, '◜', 0], // NW
        [-0.70710678, 0.70710678, '◟', 5],  // SW
        [0.70710678, 0.70710678, '◞', 4],   // SE
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
        private readonly string $fillStyle = self::FILL_FOREGROUND,
        private readonly string $renderMode = self::RENDER_FILLED,
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
            fillStyle: self::FILL_FOREGROUND,
            renderMode: self::RENDER_FILLED,
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
            fillStyle: self::FILL_FOREGROUND,
            renderMode: self::RENDER_FILLED,
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

        if ($this->renderMode === self::RENDER_WIREFRAME) {
            return $this->renderWireframe($total);
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
                    // $dy is already aspect-scaled above, so sector boundaries
                    // keep following the same ellipse the distance test draws,
                    // not the raw cell grid — segmentAt() owns that shared
                    // angle math (BL-9 dedupe of this former inline copy).
                    $segmentIndex = $this->segmentAt($dx, $dy, $total);
                    if ($segmentIndex !== null) {
                        $grid[$y][$x] = [
                            'char' => '█',
                            'color' => $this->segments[$segmentIndex]['color'],
                        ];
                    }
                }
            }
        }

        $grid = $this->paintCenterText($grid, $centerX, $centerY, $innerRadius, $aspect, $total);

        // Render the grid. In background mode a fully filled cell becomes a
        // space under its segment's background SGR, merged with its contiguous
        // same-colour neighbours into one run carrying exactly one open/reset
        // pair (the Canvas/Bar house idiom) — hence the gapless fill. Quadrant
        // runes and uncoloured cells cannot take a background paint, so they
        // keep the foreground glyph path and close any open run first.
        $backgroundFill = $this->fillStyle === self::FILL_BACKGROUND;
        $result = '';
        for ($y = 0; $y < $size; $y++) {
            /** @var Color|null $runColor identity of the open background run's colour */
            $runColor = null;
            for ($x = 0; $x < $size; $x++) {
                $cell = $grid[$y][$x];

                if ($backgroundFill && $cell['char'] === '█' && $cell['color'] !== null) {
                    if ($runColor !== $cell['color']) {
                        if ($runColor !== null) {
                            $result .= Ansi::reset();
                        }
                        $result .= $cell['color']->toBg(ColorProfile::TrueColor);
                        $runColor = $cell['color'];
                    }
                    $result .= ' ';
                    continue;
                }

                if ($runColor !== null) {
                    $result .= Ansi::reset();
                    $runColor = null;
                }
                if ($cell['color'] !== null) {
                    $result .= $cell['color']->toFg(ColorProfile::TrueColor);
                }
                $result .= $cell['char'];
                if ($cell['color'] !== null) {
                    $result .= Ansi::reset();
                }
            }
            if ($runColor !== null) {
                $result .= Ansi::reset();
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
     * Segment owning an aspect-scaled offset from the donut centre — the single
     * source of the sector-boundary angle math (the legacy cell-centre test in
     * render(), the smooth-rim tie-break, and the wireframe rim/corner samples
     * all route through here so a quadrant sample lands in the sector its cell
     * would have).
     *
     * The start-angle wrap is a single conditional +360, not fmod(): for a
     * startAngle outside 0..360 the adjusted angle can still land below 0
     * (startAngle > 360) or at/above 360 (startAngle < 0), leaving findSegment()
     * to decide those cells — the source of the blank "legacy CCW gap" cells
     * the wireframe rim comment describes. That quirk is pre-existing and
     * byte-pinned (BL-9 is a dedupe, not a fix); do not "tidy" it into fmod().
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
     * Paint the optional center text (BL-1, wired per the Q1 contract) into a
     * rendered ring grid: up to two lines centered in the hole.
     *
     * The primary line is centerValue; with showPercentage on and no explicit
     * value it is the first segment's share of the total, formatted
     * number_format(share, 0) . '%'. centerLabel, when set, takes a second
     * line directly below the primary. Each line is mb-truncated to the inner
     * diameter minus a 2-cell breathing margin — further narrowed to whatever
     * the hole actually spans on its row — so text never lands on ring cells.
     * A line whose row the hole cannot fit is omitted. With every knob at its
     * default (or centerLabel alone, which has no primary to hang under)
     * nothing is emitted and the render stays byte-identical.
     *
     * @param array<int, array<int, array{char: string, color: Color|null}>> $grid
     *
     * @return array<int, array<int, array{char: string, color: Color|null}>>
     */
    private function paintCenterText(array $grid, int $centerX, int $centerY, int $innerRadius, float $aspect, float $total): array
    {
        $primary = $this->centerPrimaryText($total);
        if ($primary === null) {
            return $grid;
        }

        $grid = $this->paintCenterLine($grid, $primary, $centerX, $centerY, 0, $innerRadius, $aspect);

        if ($this->centerLabel === null || $this->centerLabel === '') {
            return $grid;
        }

        return $this->paintCenterLine($grid, $this->centerLabel, $centerX, $centerY + 1, 1, $innerRadius, $aspect);
    }

    /**
     * The primary center line: explicit centerValue wins, else the
     * first-segment percentage when showPercentage is on, else null meaning
     * "no center text at all".
     */
    private function centerPrimaryText(float $total): ?string
    {
        if ($this->centerValue !== null) {
            return $this->centerValue;
        }

        if (!$this->showPercentage || $total <= 0.0 || $this->segments === []) {
            return null;
        }

        return number_format($this->segments[0]['value'] / $total * 100, 0) . '%';
    }

    /**
     * Paint one center-text line, horizontally centered on $centerX of row $y,
     * truncated to min(inner diameter - 2, the hole span of this row). Cells
     * outside the hole are never touched: the ring stays whole even if a
     * future geometry change ever widened a line past the blank span.
     *
     * @param array<int, array<int, array{char: string, color: Color|null}>> $grid
     *
     * @return array<int, array<int, array{char: string, color: Color|null}>>
     */
    private function paintCenterLine(array $grid, string $text, int $centerX, int $y, int $rowOffset, int $innerRadius, float $aspect): array
    {
        $fit = min(2 * $innerRadius - 2, $this->holeRowWidth($rowOffset, $innerRadius, $aspect));
        if ($fit < 1) {
            return $grid;
        }

        $characters = mb_str_split(mb_strlen($text) > $fit ? mb_substr($text, 0, $fit) : $text);

        $startX = $centerX - intdiv(count($characters), 2);
        foreach ($characters as $offset => $character) {
            $cell = &$grid[$y][$startX + $offset];
            if ($cell['char'] === ' ' && $cell['color'] === null) {
                $cell['char'] = $character;
            }
            unset($cell);
        }

        return $grid;
    }

    /**
     * Blank-hole width of the row at aspect-scaled distance $rowOffset from
     * the center row: the count of integer columns dx with
     * dx^2 + (rowOffset * aspect)^2 < innerRadius^2 — the strict complement of
     * render()'s ring test ($dist >= $innerRadius). 0 when the hole does not
     * reach the row at all.
     */
    private function holeRowWidth(int $rowOffset, int $innerRadius, float $aspect): int
    {
        $remaining = $innerRadius ** 2 - ($rowOffset * $aspect) ** 2;
        if ($remaining <= 0.0) {
            return 0;
        }

        // Largest integer strictly inside the bound: ceil() steps one below
        // exact squares (sqrt 16 -> last hole column 3, since dist 4 is ring).
        $halfSpan = (int) ceil(sqrt($remaining)) - 1;

        return 2 * $halfSpan + 1;
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

    /**
     * Render the outline-only view (see withRenderMode()).
     *
     * Three primitives carry the whole message: the outer rim walked around
     * the aspect-corrected ellipse with each cell bucketed by its LOCAL
     * TANGENT (─│╱╲ plus a rounded ◜◝◟◞ corner rune at the four diagonals),
     * one hub→rim divider per segment boundary, and a hub rune where the
     * spokes meet. No legend and no center text are emitted — wireframe is
     * shape-only by contract (Q1), which is also what keeps this mode legible
     * on B/W terminals, its whole raison d'être; center text belongs to the
     * filled path alone (see paintCenterText()).
     */
    private function renderWireframe(float $total): string
    {
        $size = min($this->width ?? $this->size, $this->height ?? $this->size);
        $radius = (int) floor($size / 2) - 1;
        $centerX = (int) floor($size / 2);
        $centerY = (int) floor($size / 2);
        $aspect = $this->aspect();

        $grid = [];
        for ($y = 0; $y < $size; $y++) {
            $grid[$y] = array_fill(0, $size, ['char' => ' ', 'color' => null]);
        }

        /** Ellipse cell touched by the aspect-scaled direction $angleDeg. */
        $ellipseCell = function (float $angleDeg) use ($radius, $centerX, $centerY, $aspect): array {
            $t = deg2rad($angleDeg);
            return [
                (int) round($centerX + $radius * cos($t)),
                (int) round($centerY + $radius * sin($t) / $aspect),
            ];
        };

        // Rim: ~80 samples per radius unit keeps every step far below 1/8 of
        // a cell so no rim cell is skipped; first writer wins, so a cell keeps
        // the rune of the arc section that entered it. Cells whose angle falls
        // in a legacy CCW gap stay blank, mirroring the filled path.
        $steps = max(720, $radius * 80);
        for ($i = 0; $i < $steps; $i++) {
            $t = 2 * M_PI * $i / $steps;
            $sin = sin($t);
            $cos = cos($t);
            $x = (int) round($centerX + $radius * $cos);
            $y = (int) round($centerY + $radius * $sin / $aspect);

            if ($x < 0 || $x >= $size || $y < 0 || $y >= $size || $grid[$y][$x]['char'] !== ' ') {
                continue;
            }

            $segmentIndex = $this->segmentAt($cos, $sin, $total);
            if ($segmentIndex === null) {
                continue;
            }

            $grid[$y][$x] = [
                // Tangent of (R·cos t, R·sin t / aspect) is (-sin t, cos t / aspect).
                'char' => self::directionRune(-$sin, $cos / $aspect),
                'color' => $this->segments[$segmentIndex]['color'],
            ];
        }

        // Quadrant corners: the diagonals get the rounded arc runes; when the
        // corner cell collapses onto a cardinal axis (small radii where the
        // ellipse effectively turns a box corner) the sharper ARC_CHARS corner
        // is the honest glyph instead. (The atan2 below is forward math —
        // direction→point, like $ellipseCell and the divider sweep's fmod —
        // not the inverse sector lookup segmentAt() owns, which the colour
        // pick underneath still uses.)
        foreach (self::WIRE_CORNERS as $corner) {
            [$ux, $uy, $roundRune, $arcIndex] = $corner;
            $t = atan2($aspect * $uy, $ux);
            $x = (int) round($centerX + $radius * cos($t));
            $y = (int) round($centerY + $radius * sin($t) / $aspect);

            if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                continue;
            }

            $segmentIndex = $this->segmentAt(cos($t), sin($t), $total);
            $grid[$y][$x] = [
                'char' => ($x === $centerX || $y === $centerY) ? self::ARC_CHARS[$arcIndex] : $roundRune,
                'color' => $segmentIndex === null ? null : $this->segments[$segmentIndex]['color'],
            ];
        }

        // Dividers: the leading edge of every segment is a boundary, so N
        // segments draw exactly N spokes (N=2 → the two 180°-apart halves of
        // one diameter). Bresenham runs in cell space from the hub to the rim
        // cell on the same aspect-scaled angle the ring test uses; each cell
        // takes the line's dominant direction and the colour of the segment
        // the spoke opens on — the same fg-SGR wrap the filled path emits.
        /** @var list<string> $dividerRunes */
        $dividerRunes = [];
        $swept = 0.0;
        foreach ($this->segments as $segment) {
            $angleDeg = fmod($swept + $this->startAngle, 360.0);
            $swept += $segment['value'] * 360.0 / $total;

            [$endX, $endY] = $ellipseCell($angleDeg);
            $rune = self::directionRune((float) ($endX - $centerX), (float) ($endY - $centerY));
            $dividerRunes[] = $rune;

            foreach (Bresenham::line($centerX, $centerY, $endX, $endY) as $point) {
                $x = $point->x;
                $y = $point->y;
                if ($x === $centerX && $y === $centerY) {
                    continue; // hub rune is decided last, below
                }
                if ($x < 0 || $x >= $size || $y < 0 || $y >= $size || $grid[$y][$x]['char'] !== ' ') {
                    continue; // stop short of the rim it just reached
                }
                $grid[$y][$x] = ['char' => $rune, 'color' => $segment['color']];
            }
        }

        // Hub: two non-collinear diagonals cross as ╳, one horizontal plus one
        // vertical cross as ┼, anything else gets the plain ● cap.
        $diagonals = array_unique(array_values(array_filter(
            $dividerRunes,
            static fn(string $rune): bool => $rune === '╱' || $rune === '╲',
        )));
        if (count($diagonals) >= 2) {
            $hub = '╳';
        } elseif (in_array('─', $dividerRunes, true) && in_array('│', $dividerRunes, true)) {
            $hub = '┼';
        } else {
            $hub = '●';
        }
        $grid[$centerY][$centerX] = [
            'char' => $hub,
            'color' => $this->segments[0]['color'],
        ];

        // Same foreground wrap as the filled mode: a coloured cell opens the
        // segment colour, prints its rune, and closes it; blanks stay blank.
        $result = '';
        foreach ($grid as $row) {
            foreach ($row as $cell) {
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
     * Bucket a cell-space direction (dx right, dy down) into the stroke rune
     * that reads most continuously: within ±22.5° of an axis the flat ─ or
     * steep │ (straight from the copied ARC_CHARS table), otherwise the
     * matching diagonal ╱/╲.
     */
    private static function directionRune(float $dx, float $dy): string
    {
        if (abs($dy) <= self::WIRE_SLOPE_FLAT * abs($dx)) {
            return self::ARC_CHARS[1];
        }
        if (abs($dx) <= self::WIRE_SLOPE_FLAT * abs($dy)) {
            return self::ARC_CHARS[3];
        }

        return $dx * $dy > 0 ? '╲' : '╱';
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
        );
    }

    /**
     * Set the aspect-ratio correction applied to the vertical cell axis.
     *
     * Cells are ~1:2 (w:h), so raw cell-unit distances stretch the ring
     * vertically; the ratio scales the vertical leg of every distance/angle
     * computation instead. 1.0 reproduces the legacy (uncorrected) geometry
     * byte-exactly, 2.0 is the visually-round default.
     *
     * A non-positive or non-finite ratio would collapse or invert the ellipse
     * (or poison every distance/angle computation with NaN), silently
     * distorting the ring rather than failing — so the value is rejected up
     * front, mirroring the throw precedent of the sibling withFillStyle() /
     * withRenderMode() setters.
     *
     * @throws \InvalidArgumentException on a non-finite or non-positive ratio
     */
    public function withAspect(float $ratio = self::DEFAULT_ASPECT): self
    {
        if (!is_finite($ratio) || $ratio <= 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid donut aspect ratio "%s"; expected a finite positive float.',
                $ratio
            ));
        }

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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
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
            fillStyle: $this->fillStyle,
            renderMode: $this->renderMode,
        );
    }

    /**
     * Choose how filled ring cells are painted.
     *
     * 'foreground' (default) keeps the classic `█` glyph per cell wrapped in
     * the segment's foreground SGR — byte-identical to every pre-S6 render.
     * 'background' instead emits each run of consecutive same-colour filled
     * cells as plain spaces under one background SGR opened at the run start
     * and closed with a single {@see Ansi::reset()} at its end (the
     * Canvas::render()/Bar::render() house idiom): fills stay gapless on
     * fonts/line-spacings where adjacent `█` glyphs show hairline seams.
     *
     * Interaction with withSmoothRim(): a cell carries only one background
     * colour, so sub-cell quadrant runes (▘▝▖▗▀▄▌▐▚▞▛▜▙▟) cannot be painted
     * by background fill — they keep the foreground glyph path even in
     * 'background' mode and split the surrounding runs. Fully covered (mask
     * 15 → `█`) cells are indistinguishable from legacy fill and do fold into
     * background runs. Uncoloured segments have no background to emit, so
     * their `█` blocks stay literal.
     *
     * @throws \InvalidArgumentException on an unknown style name
     */
    public function withFillStyle(string $style = self::FILL_FOREGROUND): self
    {
        if (!in_array($style, [self::FILL_FOREGROUND, self::FILL_BACKGROUND], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown donut fill style "%s"; expected "%s" or "%s".',
                $style,
                self::FILL_FOREGROUND,
                self::FILL_BACKGROUND
            ));
        }

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
            smoothRim: $this->smoothRim,
            fillStyle: $style,
            renderMode: $this->renderMode,
        );
    }

    /**
     * Choose the chart's render mode.
     *
     * 'filled' (default) is the classic annulus of S4-S6 and stays
     * byte-identical for every existing consumer and golden. 'wireframe'
     * draws the palette doc's "segmented outline circle" instead: tangent
     * rim runes, one radial divider per segment boundary, and a hub rune —
     * no fills, no legend, no centre text, so the shape alone stays legible
     * on colourless terminals and at small sizes where block fills smear.
     *
     * @throws \InvalidArgumentException on an unknown mode name
     */
    public function withRenderMode(string $mode = self::RENDER_FILLED): self
    {
        if (!in_array($mode, [self::RENDER_FILLED, self::RENDER_WIREFRAME], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown donut render mode "%s"; expected "%s" or "%s".',
                $mode,
                self::RENDER_FILLED,
                self::RENDER_WIREFRAME
            ));
        }

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
            smoothRim: $this->smoothRim,
            fillStyle: $this->fillStyle,
            renderMode: $mode,
        );
    }
}
