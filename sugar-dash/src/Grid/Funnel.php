<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A stage in a funnel chart.
 */
final readonly class FunnelStage
{
    public function __construct(
        public string $label,
        public float $value,
        public ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new FunnelStage(
            $this->label,
            $this->value,
            $color,
        );
    }
}

/**
 * A funnel chart component.
 *
 * Displays data as a funnel shape where each stage is narrower
 * than the one above, representing decreasing values through
 * a process or pipeline.
 *
 * Mirrors funnel chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Funnel implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<FunnelStage> */
    private array $stages = [];

    private bool $showLabels = true;
    private bool $showValues = true;
    private bool $showPercentages = false;
    private bool $centered = true;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $color = null,
        private ?Color $borderColor = null,
        private ?Color $labelColor = null,
        private ?Color $valueColor = null,
    ) {}

    /**
     * Create a new funnel chart with default styling.
     *
     * @param list<FunnelStage> $stages
     */
    public static function new(array $stages = []): self
    {
        return (new self(
            color: Color::hex('#89B4FA'),
            borderColor: Color::hex('#45475A'),
            labelColor: Color::hex('#CDD6F4'),
            valueColor: Color::hex('#A6E3A1'),
        ))->withStages($stages);
    }

    /**
     * Create a sample funnel chart for demonstration.
     */
    public static function sample(int $stages = 5): self
    {
        $labels = ['Visitors', 'Leads', 'Qualified', 'Proposals', 'Negotiations', 'Sales'];
        $values = [1000, 500, 250, 125, 60, 30];
        $funnelStages = [];

        for ($i = 0; $i < min($stages, count($labels)); $i++) {
            $funnelStages[] = new FunnelStage(
                label: $labels[$i],
                value: $values[$i],
                color: Color::hex(['#89B4FA', '#A6E3A1', '#CBA6F7', '#F9E2AF', '#F38BA8', '#94E2D5'][$i % 6]),
            );
        }

        return self::new($funnelStages);
    }

    /**
     * Set the allocated dimensions for this funnel chart.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set all stages at once.
     *
     * @param list<FunnelStage> $stages
     */
    public function withStages(array $stages): self
    {
        $clone = clone $this;
        $clone->stages = $stages;
        return $clone;
    }

    /**
     * Add a stage.
     */
    public function withStage(FunnelStage $stage): self
    {
        $clone = clone $this;
        $clone->stages[] = $stage;
        return $clone;
    }

    /**
     * Add a stage by parameters.
     */
    public function addStage(string $label, float $value, ?Color $color = null): self
    {
        return $this->withStage(new FunnelStage($label, $value, $color ?? $this->color));
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
     * Show or hide percentages.
     */
    public function withShowPercentages(bool $show): self
    {
        $clone = clone $this;
        $clone->showPercentages = $show;
        return $clone;
    }

    /**
     * Center align the funnel.
     */
    public function withCentered(bool $centered): self
    {
        $clone = clone $this;
        $clone->centered = $centered;
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
     * Calculate total value across all stages.
     */
    private function getTotalValue(): float
    {
        $total = 0.0;
        foreach ($this->stages as $stage) {
            $total += $stage->value;
        }
        return $total > 0 ? $total : 1.0;
    }

    /**
     * Render the funnel chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 50;
        $useHeight = $this->height ?? (count($this->stages) * 3 + 2);

        if ($useWidth < 15 || $useHeight < 5 || empty($this->stages)) {
            return '';
        }

        $result = '';
        $total = $this->stages[0]->value ?? $this->getTotalValue();
        $maxWidth = $useWidth - 4;

        // Get style characters
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $borderColor = $this->borderColor ?? Color::hex('#45475A');
        $labelColor = $this->labelColor ?? Color::hex('#CDD6F4');
        $valueColor = $this->valueColor ?? Color::hex('#A6E3A1');

        // Top border
        if ($this->style !== 'empty') {
            $padding = $this->centered ? intval(($useWidth - $maxWidth) / 2) : 0;
            $result .= str_repeat(' ', $padding);
            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $tl . str_repeat($h, $maxWidth) . $tr;
            if ($borderColor !== null) {
                $result .= Ansi::reset();
            }
            $result .= "\n";
        }

        // Render each stage
        foreach ($this->stages as $index => $stage) {
            $isLast = $index === count($this->stages) - 1;

            // Calculate width based on value proportion
            $proportion = $stage->value / ($this->stages[0]->value ?: 1);
            $stageWidth = max(3, intval($proportion * $maxWidth));

            // Next stage is smaller
            if (!$isLast && isset($this->stages[$index + 1])) {
                $nextProportion = $this->stages[$index + 1]->value / ($this->stages[0]->value ?: 1);
                $nextWidth = max(3, intval($nextProportion * $maxWidth));
            } else {
                $nextWidth = max(3, intval($stageWidth * 0.5));
            }

            // Draw three rows for each stage (top curve, middle, bottom connector)
            for ($row = 0; $row < 3; $row++) {
                $padding = $this->centered ? intval(($useWidth - $stageWidth) / 2) : 0;
                $line = '';

                // Left padding
                if ($this->style !== 'empty') {
                    if ($borderColor !== null) {
                        $line .= $borderColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= $v . str_repeat(' ', $padding);
                }

                // Stage content
                if ($row === 0) {
                    // Top of stage with curve
                    $line .= $this->renderFunnelTop($stageWidth, $stage, $labelColor, $valueColor);
                } elseif ($row === 1) {
                    // Middle of stage with label/value
                    $line .= $this->renderFunnelMiddle($stageWidth, $stage, $labelColor, $valueColor);
                } else {
                    // Bottom connector to next stage
                    if ($isLast) {
                        // Bottom border
                        if ($borderColor !== null) {
                            $line .= $borderColor->toFg(ColorProfile::TrueColor);
                        }
                        $line .= $bl . str_repeat($h, $stageWidth) . $br;
                        if ($borderColor !== null) {
                            $line .= Ansi::reset();
                        }
                    } else {
                        $line .= $this->renderFunnelConnector($stageWidth, $nextWidth, $stage, $borderColor);
                    }
                }

                // Right side border
                if ($this->style !== 'empty') {
                    $line .= str_repeat(' ', $padding);
                    if ($borderColor !== null) {
                        $line .= $borderColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= $v;
                    if ($borderColor !== null) {
                        $line .= Ansi::reset();
                    }
                }

                $result .= $line . "\n";
            }
        }

        // Remove last newline if we added bottom border
        if ($this->style !== 'empty') {
            $result = rtrim($result, "\n");
        }

        return $result;
    }

    /**
     * Render the top row of a funnel stage.
     */
    private function renderFunnelTop(int $width, FunnelStage $stage, Color $labelColor, Color $valueColor): string
    {
        $color = $stage->color ?? $this->color;
        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        // Top edge with rounded corners
        if ($this->style === 'rounded') {
            $result .= '╭' . str_repeat('─', $width - 2) . '╮';
        } elseif ($this->style === 'bold') {
            $result .= '┏' . str_repeat('━', $width - 2) . '┓';
        } else {
            $result .= '┌' . str_repeat('─', $width - 2) . '┐';
        }

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render the middle row of a funnel stage with label/value.
     */
    private function renderFunnelMiddle(int $width, FunnelStage $stage, Color $labelColor, Color $valueColor): string
    {
        $color = $stage->color ?? $this->color;
        $result = '';

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }
        $result .= '█';
        if ($color !== null) {
            $result .= Ansi::reset();
        }

        // Calculate available space for content
        $contentWidth = $width - 4;

        if ($contentWidth > 0) {
            $parts = [];

            // Add label if enabled
            if ($this->showLabels) {
                $label = mb_substr($stage->label, 0, intval($contentWidth / 2), 'UTF-8');
                $parts[] = $label;
            }

            // Add value if enabled
            if ($this->showValues) {
                $valueStr = $this->formatValue($stage->value);
                if ($this->showPercentages) {
                    $total = $this->stages[0]->value ?: 1;
                    $pct = intval(($stage->value / $total) * 100);
                    $valueStr .= " ({$pct}%)";
                }
                $parts[] = $valueStr;
            }

            $content = implode(' ', $parts);
            $content = mb_substr($content, 0, $contentWidth, 'UTF-8');

            // Center the content
            $leftPad = intval(($contentWidth - mb_strlen($content, 'UTF-8')) / 2);
            $result .= str_repeat(' ', $leftPad);

            if ($this->showValues && $contentWidth > 3) {
                if ($valueColor !== null) {
                    $result .= $valueColor->toFg(ColorProfile::TrueColor);
                }
            }

            $result .= $content;

            if ($this->showValues && $contentWidth > 3) {
                if ($valueColor !== null) {
                    $result .= Ansi::reset();
                }
            }

            $result .= str_repeat(' ', max(0, $contentWidth - $leftPad - mb_strlen($content, 'UTF-8')));
        }

        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }
        $result .= '█';
        if ($color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render the connector between two funnel stages.
     */
    private function renderFunnelConnector(int $width, int $nextWidth, FunnelStage $stage, ?Color $borderColor): string
    {
        $color = $stage->color ?? $this->color;
        $result = '';

        // Left slope
        $leftPad = intval(($width - $nextWidth) / 2);

        if ($this->style !== 'empty') {
            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
        }

        // Left vertical edge
        if ($leftPad > 0) {
            $result .= '█';
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat(' ', $leftPad - 1);
            $result .= '╲';
            if ($color !== null) {
                $result .= Ansi::reset();
            }
        } else {
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= '╲';
            if ($color !== null) {
                $result .= Ansi::reset();
            }
        }

        // Middle spacing
        $midWidth = $nextWidth;
        $result .= str_repeat(' ', $midWidth);

        // Right slope
        $rightPad = $width - $nextWidth - $leftPad;
        if ($rightPad > 0) {
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= '╱';
            if ($color !== null) {
                $result .= Ansi::reset();
            }
            $result .= str_repeat(' ', $rightPad - 1);
            if ($borderColor !== null) {
                $result .= $borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= '█';
        } else {
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }
            $result .= '╱';
            if ($color !== null) {
                $result .= Ansi::reset();
            }
        }

        if ($borderColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Format a value for display.
     */
    private function formatValue(float $value): string
    {
        if (abs($value) >= 1000000) {
            return sprintf('%.1fM', $value / 1000000);
        }
        if (abs($value) >= 1000) {
            return sprintf('%.1fK', $value / 1000);
        }
        if ($value === floor($value)) {
            return sprintf('%.0f', $value);
        }
        return sprintf('%.1f', $value);
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
     * Calculate the natural dimensions of this funnel chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 50;
        $height = $this->height ?? max(8, count($this->stages) * 3 + 2);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the default color.
     */
    public function withColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->color = $color;
        return $clone;
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->borderColor = $color;
        return $clone;
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->labelColor = $color;
        return $clone;
    }

    /**
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->valueColor = $color;
        return $clone;
    }
}
