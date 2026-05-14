<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A Partition chart component for hierarchical proportional visualization.
 *
 * Features:
 * - Icicle/treemap style hierarchical display
 * - Proportional segment sizes based on values
 * - Multiple levels of nesting
 * - Color customization per segment
 * - Labels and values
 *
 * Mirrors partition/icicle chart patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class Partition implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private ?PartitionSegment $root = null;
    private bool $showLabels = true;
    private bool $showValues = false;
    private bool $horizontal = true;
    private string $style = 'rounded';

    public function __construct(
        private ?Color $segmentColor = null,
        private ?Color $borderColor = null,
        private ?Color $textColor = null,
    ) {}

    /**
     * Create a new partition chart with default styling.
     */
    public static function new(): self
    {
        return new self(
            segmentColor: Color::hex('#89B4FA'),
            borderColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
        );
    }

    /**
     * Create a sample partition chart for demonstration.
     */
    public static function sample(): self
    {
        $electronics = new PartitionSegment('electronics', 'Electronics', 500, Color::hex('#89B4FA'));
        $clothing = new PartitionSegment('clothing', 'Clothing', 300, Color::hex('#CBA6F7'));
        $food = new PartitionSegment('food', 'Food', 200, Color::hex('#A6E3A1'));

        $phones = (new PartitionSegment('phones', 'Phones', 300, Color::hex('#94E2D5')))->withChildren([
            new PartitionSegment('apple', 'Apple', 180, Color::hex('#F5C2E7')),
            new PartitionSegment('samsung', 'Samsung', 120, Color::hex('#F9E2AF')),
        ]);
        $laptops = (new PartitionSegment('laptops', 'Laptops', 200, Color::hex('#74C7EC')))->withChildren([
            new PartitionSegment('dell', 'Dell', 100, Color::hex('#F38BA8')),
            new PartitionSegment('hp', 'HP', 100, Color::hex('#FAB387')),
        ]);

        $electronics = $electronics->withChildren([$phones, $laptops]);

        $root = (new PartitionSegment('root', 'Categories', 1000))
            ->withChildren([$electronics, $clothing, $food]);

        return self::new()->withRoot($root);
    }

    /**
     * Set the allocated dimensions for this partition chart.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the root segment.
     */
    public function withRoot(PartitionSegment $root): self
    {
        $clone = clone $this;
        $clone->root = $root;
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
     * Use horizontal layout (icicle top-down).
     */
    public function withHorizontal(bool $horizontal): self
    {
        $clone = clone $this;
        $clone->horizontal = $horizontal;
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
     * Render the partition chart as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 20 || $useHeight < 8 || $this->root === null) {
            return '';
        }

        if ($this->horizontal) {
            return $this->renderHorizontal($useWidth, $useHeight);
        }

        return $this->renderVertical($useWidth, $useHeight);
    }

    /**
     * Render horizontal (icicle) partition.
     */
    private function renderHorizontal(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $segmentColor = $this->segmentColor ?? Color::hex('#89B4FA');
        $borderColor = $this->borderColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Partition';
        $titlePadding = intval(($width - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $width - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartHeight = $height - 4;
        $chartWidth = $width - 4;

        // Calculate total value
        $totalValue = $this->root->getTotalValue();
        if ($totalValue <= 0) {
            $totalValue = 1.0;
        }

        // Render the partition
        $this->renderHorizontalSegment(
            $result,
            $this->root,
            1,
            $chartWidth - 2,
            1,
            $chartHeight - 2,
            $totalValue,
            $segmentColor,
            $borderColor,
            $textColor,
            0,
        );

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Render a horizontal segment recursively.
     */
    private function renderHorizontalSegment(
        string &$result,
        PartitionSegment $segment,
        int $x,
        int $width,
        int $y,
        int $height,
        float $parentTotal,
        Color $segmentColor,
        Color $borderColor,
        Color $textColor,
        int $depth,
    ): void {
        if ($depth > 10 || $width < 2 || $height < 1) {
            return;
        }

        [, , , , , $v] = $this->getStyleChars();

        $isLeaf = count($segment->children) === 0;
        $color = $segment->color ?? $segmentColor;

        // Calculate proportional width
        $proportion = $segment->getTotalValue() / max(1, $parentTotal);
        $segmentWidth = max(2, intval($proportion * $width));

        if ($segmentWidth < 2) {
            return;
        }

        // Draw segment
        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        // Top border
        $topBorder = $this->getTopBorderChar($depth);
        $result .= $topBorder . str_repeat('─', $segmentWidth - 2) . $this->getTopBorderChar($depth, true) . "\n";

        // Middle rows with label
        $middleHeight = $height - 2;
        for ($row = 0; $row < $middleHeight; $row++) {
            $result .= $v;

            if ($this->showLabels && $row === intval($middleHeight / 2)) {
                // Show label in middle row
                $label = $this->showValues
                    ? $segment->label . ' ' . sprintf('(%.0f)', $segment->value)
                    : $segment->label;
                $label = mb_substr($label, 0, $segmentWidth - 2, 'UTF-8');
                $padding = $segmentWidth - 2 - mb_strlen($label, 'UTF-8');
                $result .= ' ' . $label . str_repeat(' ', max(0, $padding)) . $v . "\n";
            } else {
                $result .= str_repeat(' ', $segmentWidth - 2) . $v . "\n";
            }
        }

        // Bottom border
        $bottomBorder = $this->getBottomBorderChar($depth);
        $result .= $bottomBorder . str_repeat('─', $segmentWidth - 2) . $this->getBottomBorderChar($depth, true) . "\n";

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        // Render children
        if (!$isLeaf && $segmentWidth >= 10) {
            $childX = $x + 1;
            $remainingWidth = $segmentWidth - 2;

            foreach ($segment->children as $child) {
                $childProportion = $child->getTotalValue() / max(1, $segment->getTotalValue());
                $childWidth = max(2, intval($childProportion * $remainingWidth));

                $this->renderHorizontalSegment(
                    $result,
                    $child,
                    $childX,
                    $childWidth,
                    $y + 1,
                    $height - 2,
                    $segment->getTotalValue(),
                    $segmentColor,
                    $borderColor,
                    $textColor,
                    $depth + 1,
                );

                $childX += $childWidth;
                $remainingWidth -= $childWidth;
            }
        }
    }

    /**
     * Render vertical (treemap-style) partition.
     */
    private function renderVertical(int $width, int $height): string
    {
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $segmentColor = $this->segmentColor ?? Color::hex('#89B4FA');
        $borderColor = $this->borderColor ?? Color::hex('#45475A');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        $result = '';

        // Title
        $title = 'Partition';
        $titlePadding = intval(($width - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $width - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        $chartHeight = $height - 4;
        $chartWidth = $width - 4;

        // Calculate total value
        $totalValue = $this->root->getTotalValue();
        if ($totalValue <= 0) {
            $totalValue = 1.0;
        }

        // Render root segment
        $this->renderVerticalSegment(
            $result,
            $this->root,
            1,
            1,
            $chartWidth,
            $chartHeight,
            $totalValue,
            $segmentColor,
            $borderColor,
            $textColor,
            0,
        );

        // Bottom border
        $result .= $bl . str_repeat('─', $width - 2) . $br;

        return $result;
    }

    /**
     * Render a vertical segment recursively.
     */
    private function renderVerticalSegment(
        string &$result,
        PartitionSegment $segment,
        int $x,
        int $y,
        int $width,
        int $height,
        float $parentTotal,
        Color $segmentColor,
        Color $borderColor,
        Color $textColor,
        int $depth,
    ): void {
        if ($depth > 10 || $width < 3 || $height < 2) {
            return;
        }

        [, , , , , $v] = $this->getStyleChars();

        $isLeaf = count($segment->children) === 0;
        $color = $segment->color ?? $segmentColor;

        // Draw segment box
        if ($color !== null) {
            $result .= $color->toFg(ColorProfile::TrueColor);
        }

        // Top border
        $result .= str_repeat(' ', $x - 1) . $this->getTopBorderChar($depth);
        $result .= str_repeat('─', $width);
        $result .= $this->getTopBorderChar($depth, true) . "\n";

        // Middle rows
        for ($row = 0; $row < $height - 2; $row++) {
            $result .= str_repeat(' ', $x - 1) . $v;

            if ($this->showLabels && $row === intval(($height - 2) / 2)) {
                $label = $this->showValues
                    ? $segment->label . ' ' . sprintf('(%.0f)', $segment->value)
                    : $segment->label;
                $label = mb_substr($label, 0, $width - 2, 'UTF-8');
                $padding = $width - 2 - mb_strlen($label, 'UTF-8');
                $result .= ' ' . $label . str_repeat(' ', max(0, $padding)) . ' ' . $v . "\n";
            } else {
                $result .= str_repeat(' ', $width) . $v . "\n";
            }
        }

        // Bottom border
        $result .= str_repeat(' ', $x - 1) . $this->getBottomBorderChar($depth);
        $result .= str_repeat('─', $width);
        $result .= $this->getBottomBorderChar($depth, true) . "\n";

        if ($color !== null) {
            $result .= Ansi::reset();
        }

        // Render children in a grid
        if (!$isLeaf) {
            $childAreaWidth = $width - 2;
            $childAreaHeight = $height - 2;

            // Simple horizontal split among children
            $childCount = count($segment->children);
            $childWidth = max(3, intval($childAreaWidth / $childCount));

            $childX = $x + 1;
            foreach ($segment->children as $index => $child) {
                $isLastChild = ($index === $childCount - 1);
                $actualChildWidth = $isLastChild
                    ? $childAreaWidth - ($childCount - 1) * $childWidth
                    : $childWidth;

                $this->renderVerticalSegment(
                    $result,
                    $child,
                    $childX,
                    $y + 1,
                    $actualChildWidth,
                    $childAreaHeight,
                    $segment->getTotalValue(),
                    $segmentColor,
                    $borderColor,
                    $textColor,
                    $depth + 1,
                );

                $childX += $actualChildWidth;
            }
        }
    }

    /**
     * Get top border character based on depth.
     */
    private function getTopBorderChar(int $depth, bool $right = false): string
    {
        if ($this->style === 'empty') {
            return ' ';
        }

        $chars = match ($depth % 4) {
            0 => $right ? '╮' : '╭',
            1 => $right ? '╮' : '╭',
            2 => $right ? '┐' : '┌',
            3 => $right ? '┐' : '┌',
            default => $right ? '╮' : '╭',
        };

        if ($this->style === 'double') {
            $chars = $right ? '╗' : '╔';
        } elseif ($this->style === 'bold') {
            $chars = $right ? '┓' : '┏';
        } elseif ($this->style === 'single') {
            $chars = $right ? '┐' : '┌';
        }

        return $chars;
    }

    /**
     * Get bottom border character based on depth.
     */
    private function getBottomBorderChar(int $depth, bool $right = false): string
    {
        if ($this->style === 'empty') {
            return ' ';
        }

        $chars = match ($depth % 4) {
            0 => $right ? '╯' : '╰',
            1 => $right ? '╯' : '╰',
            2 => $right ? '┘' : '└',
            3 => $right ? '┘' : '└',
            default => $right ? '╯' : '╰',
        };

        if ($this->style === 'double') {
            $chars = $right ? '╝' : '╚';
        } elseif ($this->style === 'bold') {
            $chars = $right ? '┛' : '┗';
        } elseif ($this->style === 'single') {
            $chars = $right ? '┘' : '└';
        }

        return $chars;
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
     * Calculate the natural dimensions of this partition chart.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 20;

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
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->borderColor = $color;
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
}
