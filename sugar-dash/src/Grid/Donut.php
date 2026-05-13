<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

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
final class Donut implements Sizer
{
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
        );
    }

    /**
     * Set the allocated dimensions for this chart.
     */
    public function setSize(int $width, int $height): Sizer
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

        // Fill the donut ring
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $centerX;
                $dy = $y - $centerY;
                $dist = sqrt($dx * $dx + $dy * $dy);

                // Check if point is in the donut ring
                if ($dist >= $innerRadius && $dist <= $radius) {
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

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $centerX;
                $dy = $y - $centerY;
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

        for ($i = 0; $i < count($this->segments); $i++) {
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
        );
    }
}
