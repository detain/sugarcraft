<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A funnel chart component for displaying conversion flows.
 *
 * Features:
 * - Funnel stages with decreasing widths
 * - Optional labels and values
 * - Percentage display
 * - Customizable colors
 * - Horizontal or vertical orientation
 *
 * Mirrors funnel chart patterns adapted to PHP with wither-style immutable setters.
 */
final class FunnelChart implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, value: float, color?: Color|null}> $stages
     */
    public function __construct(
        private readonly array $stages,
        private readonly bool $horizontal = false,
        private readonly bool $showValues = true,
        private readonly bool $showPercentages = true,
        private readonly ?Color $defaultColor = null,
    ) {}

    /**
     * Create a new funnel chart.
     *
     * @param list<array{label: string, value: float, color?: string|Color|null}> $stages
     */
    public static function new(array $stages): self
    {
        $normalized = array_map(function (array $item): array {
            $color = $item['color'] ?? null;
            if (is_string($color)) {
                $color = Color::hex($color);
            }
            return [
                'label' => $item['label'],
                'value' => max(0.0, floatval($item['value'])),
                'color' => $color,
            ];
        }, $stages);

        return new self(
            stages: $normalized,
            horizontal: false,
            showValues: true,
            showPercentages: true,
            defaultColor: Color::hex('#89B4FA'),
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
     * Calculate the natural dimensions of this funnel chart.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $maxValue = 0;
        foreach ($this->stages as $stage) {
            if ($stage['value'] > $maxValue) {
                $maxValue = $stage['value'];
            }
        }

        $height = count($this->stages) * 3 + 1;
        $width = 40;

        return [$width, $height];
    }

    /**
     * Render the funnel chart.
     */
    public function render(): string
    {
        if ($this->stages === []) {
            return '';
        }

        $maxValue = max(array_column($this->stages, 'value'));
        if ($maxValue <= 0) {
            $maxValue = 1;
        }

        $useWidth = $this->width ?? 40;
        $result = [];

        for ($i = 0; $i < count($this->stages); $i++) {
            $stage = $this->stages[$i];
            $value = $stage['value'];
            $label = $stage['label'];
            $color = $stage['color'] ?? $this->defaultColor;

            // Calculate width based on value ratio
            $ratio = $value / $maxValue;
            $stageWidth = max(3, (int) round($useWidth * $ratio));

            // Build funnel shape
            $colorStr = $color->toFg(ColorProfile::TrueColor);
            $topWidth = ($i === 0) ? $stageWidth : $resultWidths[$i - 1] ?? $stageWidth;

            // Ensure each stage is smaller than the one above
            $displayWidth = min($stageWidth, $topWidth - 2);

            if ($displayWidth < 3) {
                $displayWidth = 3;
            }

            // Draw stage
            $stageLine = '│' . $colorStr . str_repeat('█', $displayWidth) . Ansi::reset() . ' ' . $label;

            // Add value/percentage
            if ($this->showValues) {
                $valueStr = number_format($value);
                if ($this->showPercentages) {
                    $pct = round($ratio * 100, 1);
                    $stageLine .= ' ' . $valueStr . ' (' . $pct . '%)';
                } else {
                    $stageLine .= ' ' . $valueStr;
                }
            }

            $result[] = $stageLine;
            $resultWidths[] = $displayWidth;
        }

        return implode("\n", $result);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set horizontal orientation.
     */
    public function withHorizontal(bool $horizontal): self
    {
        return new self(
            stages: $this->stages,
            horizontal: $horizontal,
            showValues: $this->showValues,
            showPercentages: $this->showPercentages,
            defaultColor: $this->defaultColor,
        );
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        return new self(
            stages: $this->stages,
            horizontal: $this->horizontal,
            showValues: $show,
            showPercentages: $this->showPercentages,
            defaultColor: $this->defaultColor,
        );
    }

    /**
     * Show or hide percentages.
     */
    public function withShowPercentages(bool $show): self
    {
        return new self(
            stages: $this->stages,
            horizontal: $this->horizontal,
            showValues: $this->showValues,
            showPercentages: $show,
            defaultColor: $this->defaultColor,
        );
    }
}
