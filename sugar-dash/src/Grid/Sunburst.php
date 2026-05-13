<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A segment in a Sunburst chart.
 */
final class SunburstSegment
{
    /** @var list<SunburstSegment> */
    public array $children = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with children.
     *
     * @param list<SunburstSegment> $children
     */
    public function withChildren(array $children): self
    {
        $clone = clone $this;
        $clone->children = $children;
        return $clone;
    }

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $color,
        )->withChildren($this->children);
    }

    /**
     * Calculate total value including children.
     */
    public function getTotalValue(): float
    {
        $total = $this->value;
        foreach ($this->children as $child) {
            $total += $child->getTotalValue();
        }
        return $total;
    }
}

/**
 * A Sunburst chart component for radial hierarchical visualization.
 *
 * Features:
 * - Hierarchical data as concentric rings
 * - Proportional segment sizes based on values
 * - Multiple levels of nesting
 * - Color customization per segment
 * - Center label for root
 *
 * Mirrors sunburst/radial treemap patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Sunburst implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<SunburstSegment> */
    private array $segments = [];

    private string $centerLabel = 'Total';
    private bool $showLabels = true;
    private bool $showValues = false;
    private int $maxDepth = 3;
    private string $style = 'rounded';

    /**
     * Block characters for arc approximation.
     */
    private const ARC_CHARS = [
        'full' => '█',
        'left' => '▏',
        'right' => '▎',
        'top' => '▁',
        'bottom' => '▔',
    ];

    public function __construct(
        private readonly ?Color $segmentColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $centerColor = null,
    ) {}

    /**
     * Create a new Sunburst chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            segmentColor: Color::hex('#89B4FA'),
            textColor: Color::hex('#CDD6F4'),
            centerColor: Color::hex('#CBA6F7'),
        );
    }

    /**
     * Set the allocated dimensions for this Sunburst chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add a segment to the chart.
     */
    public function withSegment(SunburstSegment $segment): self
    {
        $clone = clone $this;
        $clone->segments[] = $segment;
        return $clone;
    }

    /**
     * Add a segment by parameters.
     */
    public function addSegment(string $id, string $label, float $value, ?Color $color = null): self
    {
        return $this->withSegment(new SunburstSegment($id, $label, $value, $color));
    }

    /**
     * Set all segments at once.
     *
     * @param list<SunburstSegment> $segments
     */
    public function withSegments(array $segments): self
    {
        $clone = clone $this;
        $clone->segments = $segments;
        return $clone;
    }

    /**
     * Set the center label.
     */
    public function withCenterLabel(string $label): self
    {
        $clone = clone $this;
        $clone->centerLabel = $label;
        return $clone;
    }

    /**
     * Show or hide labels.
     */
    public function withShowLabels(bool $show): self
    {
        $clone = clone $this;
        $clone->showLabels = $show;
        return $clone;
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        $clone = clone $this;
        $clone->showValues = $show;
        return $clone;
    }

    /**
     * Set maximum nesting depth.
     */
    public function withMaxDepth(int $depth): self
    {
        $clone = clone $this;
        $clone->maxDepth = max(1, $depth);
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Render the Sunburst chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 50;
        $useHeight = $this->height ?? 25;

        if ($useWidth < 20 || $useHeight < 10 || empty($this->segments)) {
            return '';
        }

        return $this->renderChart($useWidth, $useHeight);
    }

    /**
     * Render the complete Sunburst chart.
     */
    private function renderChart(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $segmentColor = $this->segmentColor ?? Color::hex('#89B4FA');
        $centerColor = $this->centerColor ?? Color::hex('#CBA6F7');

        $result = '';

        // Title
        $title = 'Sunburst Chart';
        $titleX = intval(($width - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titleX - 1) . $title . str_repeat('─', $width - 2 - $titleX - strlen($title)) . $tr . "\n";

        // Calculate total value
        $totalValue = 0.0;
        foreach ($this->segments as $segment) {
            $totalValue += $segment->getTotalValue();
        }

        if ($totalValue <= 0) {
            $totalValue = 1.0;
        }

        // Calculate ring dimensions
        $chartWidth = $width - 4;
        $chartHeight = $height - 4;
        $centerX = intval($chartWidth / 2) + 2;
        $centerY = intval($chartHeight / 2) + 2;
        $maxRadius = min($centerX, $centerY) - 1;

        // Draw the sunburst using ASCII art approximation
        // Since we can't do true arcs in ASCII, we create a radial representation
        $ringHeight = intval($maxRadius / max(1, $this->maxDepth));

        // Draw center
        $centerRow = $centerY;
        $centerCol = $centerX;

        // Center circle with label
        $centerDiameter = min(5, $ringHeight * 2);
        if ($centerDiameter < 3) {
            $centerDiameter = 3;
        }

        for ($row = $centerY - intval($centerDiameter / 2); $row <= $centerY + intval($centerDiameter / 2); $row++) {
            if ($row < 1 || $row >= $height - 1) {
                continue;
            }

            $line = $v;
            $isCenterRow = ($row === $centerY);

            for ($col = 2; $col < $width - 2; $col++) {
                $dx = $col - $centerX;
                $dy = $row - $centerY;
                $distance = sqrt($dx * $dx + $dy * $dy);

                if ($distance <= intval($centerDiameter / 2)) {
                    // Inside center circle
                    if ($isCenterRow && abs($dx) <= intval($centerDiameter / 4)) {
                        // Show label in center row
                        $labelIndex = intval($dx + intval($centerDiameter / 4));
                        if ($labelIndex >= 0 && $labelIndex < strlen($this->centerLabel)) {
                            $char = $this->centerLabel[$labelIndex];
                        } else {
                            $char = ' ';
                        }
                    } else {
                        $char = '●';
                    }

                    if ($centerColor !== null) {
                        $line .= $centerColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= $char;
                    if ($centerColor !== null) {
                        $line .= Ansi::reset();
                    }
                } else {
                    // Check if we're in a ring segment
                    $inSegment = false;

                    foreach ($this->segments as $segment) {
                        $proportion = $segment->getTotalValue() / $totalValue;
                        $segmentRadius = intval($proportion * $maxRadius);

                        if ($distance <= $segmentRadius && $distance > intval($centerDiameter / 2)) {
                            // In a ring segment
                            if ($segment->color !== null) {
                                $line .= $segment->color->toFg(ColorProfile::TrueColor);
                            } elseif ($segmentColor !== null) {
                                $line .= $segmentColor->toFg(ColorProfile::TrueColor);
                            }

                            // Choose character based on angle
                            $angle = atan2($dy, $dx);
                            $char = $this->getArcChar($angle, $distance, $segmentRadius);
                            $line .= $char;

                            if ($segment->color !== null || $segmentColor !== null) {
                                $line .= Ansi::reset();
                            }
                            $inSegment = true;
                            break;
                        }
                    }

                    if (!$inSegment) {
                        $line .= ' ';
                    }
                }
            }

            $line .= $v;
            $result .= $line . "\n";
        }

        // Draw legend
        if ($this->showLabels) {
            $legendY = $height - 2;
            $legendX = 2;
            $legendLine = '';

            foreach ($this->segments as $segment) {
                $color = $segment->color ?? $segmentColor;
                $entry = '';
                if ($color !== null) {
                    $entry .= $color->toFg(ColorProfile::TrueColor);
                }
                $entry .= '▪ ' . $segment->label;
                if ($color !== null) {
                    $entry .= Ansi::reset();
                }

                if ($legendX + strlen($entry) < $width - 2) {
                    $legendLine .= $entry . '  ';
                    $legendX += strlen($entry) + 2;
                }
            }

            if ($legendLine !== '') {
                $result .= $bl . str_pad('', $width - 2) . $br . "\n";
                $result .= $v . str_pad($legendLine, $width - 2) . $v . "\n";
            }
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Get the appropriate character for an arc position.
     */
    private function getArcChar(float $angle, float $distance, int $segmentRadius): string
    {
        // Normalize angle to 0-2PI
        if ($angle < 0) {
            $angle += 2 * M_PI;
        }

        // Determine which octant we're in
        $octant = intval(($angle / (2 * M_PI)) * 8) % 8;

        // Choose character based on octant and position in ring
        $innerRadius = $segmentRadius - 1;
        $density = ($distance - $innerRadius) / max(1, $segmentRadius - $innerRadius);

        $chars = match ($octant) {
            0 => ['╭', '─', '╮'],    // right
            1 => ['╮', '│', '┐'],    // bottom-right
            2 => ['┐', '│', '┘'],    // bottom
            3 => ['┘', '─', '╯'],    // bottom-left
            4 => ['╯', '─', '╰'],    // left
            5 => ['╰', '│', '┌'],    // top-left
            6 => ['┌', '│', '│'],    // top
            7 => ['│', '─', '╮'],    // top-right
            default => ['─', '─', '─'],
        };

        $charIndex = min(2, max(0, intval($density * 2)));
        return $chars[$charIndex];
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this Sunburst chart.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 50;
        $height = $this->height ?? 25;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the default segment color.
     */
    public function withSegmentColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->segmentColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the center circle color.
     */
    public function withCenterColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->centerColor = $color;
        return $clone;
    }
}
