<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A waterfall chart component for visualizing cumulative changes.
 *
 * Features:
 * - Positive and negative value bars
 * - Running total calculation
 * - Total and subtotal bars
 * - Customizable colors
 * - Connector lines between bars
 *
 * Mirrors waterfall chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Waterfall implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /** @var list<WaterfallItem> */
    private array $items = [];

    private bool $showConnectors = true;
    private bool $showValues = true;
    private bool $showGrid = true;
    private ?float $minValue = null;
    private ?float $maxValue = null;

    public function __construct(
        private ?int $maxItems = null,
        private ?Color $positiveColor = null,
        private ?Color $negativeColor = null,
        private ?Color $totalColor = null,
        private ?Color $subtotalColor = null,
        private ?Color $gridColor = null,
        private ?Color $textColor = null,
        private ?Color $backgroundColor = null,
        private string $style = 'rounded',
    ) {}

    /**
     * Create a new waterfall chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxItems: null,
            positiveColor: Color::hex('#A6E3A1'),
            negativeColor: Color::hex('#F38BA8'),
            totalColor: Color::hex('#89B4FA'),
            subtotalColor: Color::hex('#CBA6F7'),
            gridColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this waterfall chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Add an item to the chart.
     */
    public function withItem(WaterfallItem $item): self
    {
        $clone = clone $this;
        $clone->items[] = $item;
        $clone->recalculateMinMax();
        return $clone;
    }

    /**
     * Add an item by parameters.
     */
    public function addItem(string $label, float $value, WaterfallBarType $type = WaterfallBarType::Positive): self
    {
        $item = new WaterfallItem($label, $value, $type);
        return $this->withItem($item);
    }

    /**
     * Set all items at once.
     *
     * @param list<WaterfallItem> $items
     */
    public function withItems(array $items): self
    {
        $clone = clone $this;
        $clone->items = $items;
        $clone->recalculateMinMax();
        return $clone;
    }

    /**
     * Show or hide connector lines.
     */
    public function withShowConnectors(bool $show): self
    {
        $clone = clone $this;
        $clone->showConnectors = $show;
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
     * Show or hide grid.
     */
    public function withShowGrid(bool $show): self
    {
        $clone = clone $this;
        $clone->showGrid = $show;
        return $clone;
    }

    /**
     * Set fixed min/max values.
     */
    public function withValueRange(float $min, float $max): self
    {
        $clone = clone $this;
        $clone->minValue = $min;
        $clone->maxValue = $max;
        return $clone;
    }

    /**
     * Recalculate min/max values from data.
     */
    private function recalculateMinMax(): void
    {
        if ($this->items === []) {
            return;
        }

        $runningTotal = 0.0;
        $values = [];

        foreach ($this->items as $item) {
            if ($item->type === WaterfallBarType::Total || $item->type === WaterfallBarType::Subtotal) {
                $runningTotal = $item->value;
            } else {
                $runningTotal += $item->value;
            }
            $values[] = $runningTotal;
            if ($item->type !== WaterfallBarType::Total && $item->type !== WaterfallBarType::Subtotal) {
                $values[] = $item->value;
            }
        }

        $this->minValue = min($values);
        $this->maxValue = max($values);

        // Add padding
        $range = $this->maxValue - $this->minValue;
        if ($range > 0) {
            $this->minValue = $this->minValue - $range * 0.1;
            $this->maxValue = $this->maxValue + $range * 0.1;
        }
    }

    /**
     * Render the waterfall chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 70;
        $useHeight = $this->height ?? 15;

        if ($useWidth < 25 || $useHeight < 6) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $gridColor = $this->gridColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Waterfall Chart';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat(' ', $titlePadding) . $title . str_repeat(' ', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        // Grid
        $chartHeight = $useHeight - 4;
        $chartWidth = $useWidth - 2;

        // Header row with labels
        $labelWidth = 8;
        $barAreaWidth = $chartWidth - $labelWidth - 1;

        $result .= $v . str_pad('Label', $labelWidth);
        $result .= $v . str_repeat('─', $barAreaWidth) . $v . "\n";

        // Grid lines
        if ($this->showGrid) {
            $gridLines = min($chartHeight - 1, 5);
            for ($i = 0; $i < $gridLines; $i++) {
                $y = intval($i * ($chartHeight - 1) / max(1, $gridLines - 1));
                $labelY = $this->maxValue - (($this->maxValue - $this->minValue) * $i / max(1, $gridLines - 1));
                $labelStr = sprintf('%5.1f', $labelY);
                $line = str_repeat('·', $barAreaWidth);
                $result .= $v . $labelStr . $v . $line . $v . "\n";
            }
        }

        // Data bars
        $runningTotal = 0.0;
        $barCount = count($this->items);
        $barWidth = max(3, intval($barAreaWidth / max(1, $barCount)) - 1);

        foreach ($this->items as $index => $item) {
            $isLast = $index === $barCount - 1;

            if ($item->type === WaterfallBarType::Total || $item->type === WaterfallBarType::Subtotal) {
                $runningTotal = $item->value;
            } else {
                $runningTotal += $item->value;
            }

            $barColor = $item->color ?? match ($item->type) {
                WaterfallBarType::Positive => $this->positiveColor ?? Color::hex('#A6E3A1'),
                WaterfallBarType::Negative => $this->negativeColor ?? Color::hex('#F38BA8'),
                WaterfallBarType::Total => $this->totalColor ?? Color::hex('#89B4FA'),
                WaterfallBarType::Subtotal => $this->subtotalColor ?? Color::hex('#CBA6F7'),
            };

            $label = mb_substr($item->label, 0, $labelWidth - 1);
            $barStr = '';

            if ($item->type === WaterfallBarType::Total || $item->type === WaterfallBarType::Subtotal) {
                // Full height bar for totals
                $barStr = str_repeat('█', $barWidth);
            } else {
                // Partial bar based on value
                $normalizedValue = $item->value / max(0.01, $this->maxValue - $this->minValue);
                $filledHeight = intval($chartHeight * abs($normalizedValue));
                $filledHeight = max(1, min($chartHeight - 1, $filledHeight));

                for ($row = 0; $row < $chartHeight - 1; $row++) {
                    $rowFromBottom = $row;
                    $barChar = ($item->type === WaterfallBarType::Negative && $rowFromBottom < $filledHeight) ? '▓' : '█';
                    $barStr .= str_repeat($barChar, $barWidth);
                    if ($row < $chartHeight - 2) {
                        $barStr .= "\n";
                    }
                }
            }

            $shortLabel = str_pad($label, $labelWidth);
            $result .= $v . $shortLabel . $v . str_pad($barStr, $barAreaWidth) . $v . "\n";

            // Connector line
            if ($this->showConnectors && !$isLast && $item->type !== WaterfallBarType::Total && $item->type !== WaterfallBarType::Subtotal) {
                $connectorY = intval(($this->maxValue - $runningTotal) / max(0.01, $this->maxValue - $this->minValue) * ($chartHeight - 1));
                $connectorLine = str_repeat('─', $barAreaWidth);
                $result .= $v . str_repeat(' ', $labelWidth) . $v . $connectorLine . $v . "\n";
            }
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
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
     * Calculate the natural dimensions of this waterfall chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 70;
        $height = $this->height ?? max(10, count($this->items) + 5);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the positive color.
     */
    public function withPositiveColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->positiveColor = $color;
        return $clone;
    }

    /**
     * Set the negative color.
     */
    public function withNegativeColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->negativeColor = $color;
        return $clone;
    }

    /**
     * Set the total color.
     */
    public function withTotalColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->totalColor = $color;
        return $clone;
    }

    /**
     * Set the subtotal color.
     */
    public function withSubtotalColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->subtotalColor = $color;
        return $clone;
    }

    /**
     * Set the grid color.
     */
    public function withGridColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->gridColor = $color;
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
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->backgroundColor = $color;
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
}
