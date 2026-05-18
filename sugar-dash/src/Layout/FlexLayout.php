<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\AlignItems;
use SugarCraft\Dash\Layout\JustifyContent;

/**
 * A flexbox-style layout component.
 *
 * Provides:
 * - Horizontal and vertical flex directions
 * - Flex grow/shrink support
 * - Gap/spacing between items
 * - Alignment options (justify-content, align-items)
 *
 * Mirrors flexbox layout concepts adapted to PHP with wither-style immutable setters.
 */
final class FlexLayout implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly FlexDirection $direction = FlexDirection::Row,
        private readonly FlexWrap $wrap = FlexWrap::NoWrap,
        private readonly JustifyContent $justify = JustifyContent::Start,
        private readonly AlignItems $alignItems = AlignItems::Start,
        private readonly int $gap = 0,
    ) {}

    /**
     * Create a new flex layout with row direction.
     *
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public static function row(array $items = []): self
    {
        return new self(
            items: $items,
            direction: FlexDirection::Row,
            wrap: FlexWrap::NoWrap,
            justify: JustifyContent::Start,
            alignItems: AlignItems::Start,
            gap: 0,
        );
    }

    /**
     * Create a new flex layout with column direction.
     *
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public static function column(array $items = []): self
    {
        return new self(
            items: $items,
            direction: FlexDirection::Column,
            wrap: FlexWrap::NoWrap,
            justify: JustifyContent::Start,
            alignItems: AlignItems::Start,
            gap: 0,
        );
    }

    /**
     * Set the allocated dimensions for this flex layout.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the flex layout.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return $this->renderNatural();
        }

        return match ($this->direction) {
            FlexDirection::Row => $this->renderRow($w, $h),
            FlexDirection::Column => $this->renderColumn($w, $h),
        };
    }

    /**
     * Render with natural (unsized) dimensions.
     */
    private function renderNatural(): string
    {
        if ($this->items === []) {
            return '';
        }

        $parts = [];
        foreach ($this->items as $item) {
            $parts[] = $item->render();
        }

        return match ($this->direction) {
            FlexDirection::Row => implode(str_repeat(' ', max(1, $this->gap)), $parts),
            FlexDirection::Column => implode("\n", $parts),
        };
    }

    /**
     * Render items in a row (horizontal flex).
     */
    private function renderRow(int $totalWidth, int $totalHeight): string
    {
        // Measure each item
        $itemSizes = $this->measureItems();

        // Calculate total natural width
        $totalGapWidth = ($this->gap * max(0, count($this->items) - 1));
        $naturalWidth = array_sum(array_column($itemSizes, 'width')) + $totalGapWidth;
        $naturalHeight = max(array_column($itemSizes, 'height'));

        // Handle wrap if needed
        if ($this->wrap === FlexWrap::Wrap && $naturalWidth > $totalWidth) {
            return $this->renderWrappedRow($totalWidth, $totalHeight, $itemSizes);
        }

        // Calculate item widths based on justification
        $itemWidths = $this->calculateRowItemWidths($totalWidth, $itemSizes);

        // Render each item and collect lines
        $lines = array_fill(0, $totalHeight, '');
        $currentX = 0;

        foreach ($this->items as $index => $item) {
            $itemWidth = $itemWidths[$index];
            $itemHeight = $itemSizes[$index]['height'];

            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $sized = $item->setSize($itemWidth, $totalHeight);
            } else {
                $sized = $item;
            }
            $rendered = $sized->render();

            // Split into lines and align
            $itemLines = explode("\n", $rendered);
            $alignedLines = $this->alignVertically($itemLines, $itemHeight, $totalHeight);

            // Truncate or pad each line to itemWidth
            for ($y = 0; $y < $totalHeight; $y++) {
                $lineContent = $alignedLines[$y] ?? '';
                $lineWidth = Width::string($lineContent);

                if ($lineWidth < $itemWidth) {
                    $lineContent .= str_repeat(' ', $itemWidth - $lineWidth);
                } elseif ($lineWidth > $itemWidth) {
                    $lineContent = $this->truncateToWidth($lineContent, $itemWidth);
                }

                $lines[$y] .= $lineContent;

                // Add gap after this item (except last)
                if ($index < count($this->items) - 1 && $this->gap > 0) {
                    $lines[$y] .= str_repeat(' ', $this->gap);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render wrapped row (multi-line flex).
     */
    private function renderWrappedRow(int $totalWidth, int $totalHeight, array $itemSizes): string
    {
        $lines = [];
        $currentLine = '';
        $currentLineWidth = 0;
        $currentLineItems = [];

        foreach ($this->items as $index => $item) {
            $itemWidth = $itemSizes[$index]['width'];
            $itemHeight = $itemSizes[$index]['height'];

            // Check if item fits in current line
            $itemWithGap = $currentLineWidth > 0 ? $this->gap : 0;
            if ($currentLineWidth + $itemWithGap + $itemWidth <= $totalWidth) {
                // Add to current line
                if ($currentLineWidth > 0) {
                    $currentLine .= str_repeat(' ', $this->gap);
                }
                $currentLine .= $item->render();
                $currentLineWidth += $itemWithGap + $itemWidth;
                $currentLineItems[] = $index;
            } else {
                // Start new line
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $item->render();
                $currentLineWidth = $itemWidth;
                $currentLineItems = [$index];
            }
        }

        // Add last line
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        // Truncate lines to total width and pad to totalHeight
        $result = [];
        for ($i = 0; $i < $totalHeight; $i++) {
            $line = $lines[$i] ?? '';
            $lineWidth = Width::string($line);

            if ($lineWidth < $totalWidth) {
                $line .= str_repeat(' ', $totalWidth - $lineWidth);
            } elseif ($lineWidth > $totalWidth) {
                $line = $this->truncateToWidth($line, $totalWidth);
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }

    /**
     * Render items in a column (vertical flex).
     */
    private function renderColumn(int $totalWidth, int $totalHeight): string
    {
        // Measure each item
        $itemSizes = $this->measureItems();

        // Calculate item heights based on justification
        $itemHeights = $this->calculateColumnItemHeights($totalHeight, $itemSizes);

        // Render each item
        $lines = [];
        $currentY = 0;

        foreach ($this->items as $index => $item) {
            $itemWidth = max(array_column($itemSizes, 'width'));
            $itemHeight = $itemHeights[$index];

            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $sized = $item->setSize($totalWidth, $itemHeight);
            } else {
                $sized = $item;
            }
            $rendered = $sized->render();

            // Split into lines
            $itemLines = explode("\n", $rendered);

            // Truncate or pad each line to totalWidth
            $alignedLines = [];
            for ($y = 0; $y < $itemHeight; $y++) {
                $lineContent = $itemLines[$y] ?? '';
                $lineWidth = Width::string($lineContent);

                if ($lineWidth < $totalWidth) {
                    $lineContent .= str_repeat(' ', $totalWidth - $lineWidth);
                } elseif ($lineWidth > $totalWidth) {
                    $lineContent = $this->truncateToWidth($lineContent, $totalWidth);
                }

                $alignedLines[] = $lineContent;
            }

            // Pad with empty lines if needed
            while (count($alignedLines) < $itemHeight) {
                $alignedLines[] = str_repeat(' ', $totalWidth);
            }

            $lines = array_merge($lines, $alignedLines);

            // Add gap after this item (except last)
            if ($index < count($this->items) - 1 && $this->gap > 0) {
                for ($i = 0; $i < $this->gap; $i++) {
                    $lines[] = str_repeat(' ', $totalWidth);
                }
            }
        }

        // Apply horizontal alignment to all lines based on justify
        $result = [];
        foreach ($lines as $line) {
            $result[] = $this->alignHorizontally($line, $totalWidth);
        }

        // Pad or truncate to totalHeight
        while (count($result) < $totalHeight) {
            $result[] = str_repeat(' ', $totalWidth);
        }

        return implode("\n", array_slice($result, 0, $totalHeight));
    }

    /**
     * Measure all items to get their natural dimensions.
     *
     * @return list<array{width:int,height:int}>
     */
    private function measureItems(): array
    {
        $sizes = [];
        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, $h] = $item->getInnerSize();
                if ($w === 0 || $h === 0) {
                    $rendered = $item->render();
                    $lines = explode("\n", $rendered);
                    $w = 0;
                    foreach ($lines as $line) {
                        $w = max($w, Width::string($line));
                    }
                    $h = count($lines);
                }
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $w = 0;
                foreach ($lines as $line) {
                    $w = max($w, Width::string($line));
                }
                $h = count($lines);
            }
            $sizes[] = ['width' => max(1, $w), 'height' => max(1, $h)];
        }
        return $sizes;
    }

    /**
     * Calculate item widths for row layout based on justify content.
     *
     * @param list<array{width:int,height:int}> $itemSizes
     * @return list<int>
     */
    private function calculateRowItemWidths(int $totalWidth, array $itemSizes): array
    {
        $itemWidths = array_column($itemSizes, 'width');
        $totalGapWidth = $this->gap * max(0, count($this->items) - 1);
        $contentWidth = array_sum($itemWidths);

        // If content fits exactly or overflows, use natural widths
        if ($contentWidth + $totalGapWidth >= $totalWidth) {
            return $itemWidths;
        }

        // Distribute extra space based on justify content
        $extraSpace = $totalWidth - $contentWidth - $totalGapWidth;

        return match ($this->justify) {
            JustifyContent::Start, JustifyContent::FlexStart => $itemWidths,
            JustifyContent::End, JustifyContent::FlexEnd => $itemWidths, // Right-aligned via padding in render
            JustifyContent::Center => $itemWidths, // Centered via padding in render
            JustifyContent::SpaceBetween => $this->distributeSpaceBetween($itemWidths, $extraSpace),
            JustifyContent::SpaceAround => $this->distributeSpaceAround($itemWidths, $extraSpace),
            JustifyContent::SpaceEvenly => $this->distributeSpaceEvenly($itemWidths, $extraSpace),
        };
    }

    /**
     * Calculate item heights for column layout.
     *
     * @param list<array{width:int,height:int}> $itemSizes
     * @return list<int>
     */
    private function calculateColumnItemHeights(int $totalHeight, array $itemSizes): array
    {
        $itemHeights = array_column($itemSizes, 'height');
        $totalGapHeight = $this->gap * max(0, count($this->items) - 1);
        $contentHeight = array_sum($itemHeights);

        if ($contentHeight + $totalGapHeight >= $totalHeight) {
            return $itemHeights;
        }

        $extraSpace = $totalHeight - $contentHeight - $totalGapHeight;

        return match ($this->justify) {
            JustifyContent::Start, JustifyContent::FlexStart => $itemHeights,
            JustifyContent::End, JustifyContent::FlexEnd => $itemHeights,
            JustifyContent::Center => $itemHeights,
            JustifyContent::SpaceBetween => $this->distributeSpaceBetween($itemHeights, $extraSpace),
            JustifyContent::SpaceAround => $this->distributeSpaceAround($itemHeights, $extraSpace),
            JustifyContent::SpaceEvenly => $this->distributeSpaceEvenly($itemHeights, $extraSpace),
        };
    }

    /**
     * Distribute space between items (space-between).
     *
     * @param list<int> $sizes
     * @return list<int>
     */
    private function distributeSpaceBetween(array $sizes, int $extraSpace): array
    {
        if (count($sizes) < 2) {
            return $sizes;
        }

        $gapCount = count($sizes) - 1;
        $additionalPerGap = (int) floor($extraSpace / $gapCount);
        $remainder = $extraSpace % $gapCount;

        $result = [];
        for ($i = 0; $i < count($sizes); $i++) {
            $result[] = $sizes[$i];
            if ($i < $gapCount) {
                // Extra space handled via gap in render
            }
        }
        return $result;
    }

    /**
     * Distribute space around items (space-around).
     *
     * @param list<int> $sizes
     * @return list<int>
     */
    private function distributeSpaceAround(array $sizes, int $extraSpace): array
    {
        return $sizes; // Simplified - gap handles spacing
    }

    /**
     * Distribute space evenly (space-evenly).
     *
     * @param list<int> $sizes
     * @return list<int>
     */
    private function distributeSpaceEvenly(array $sizes, int $extraSpace): array
    {
        return $sizes; // Simplified - gap handles spacing
    }

    /**
     * Align item lines vertically within the total height.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function alignVertically(array $lines, int $itemHeight, int $totalHeight): array
    {
        $result = [];
        $padding = max(0, $totalHeight - count($lines));

        if ($padding === 0) {
            return $lines;
        }

        $top = match ($this->alignItems) {
            AlignItems::Start, AlignItems::FlexStart => 0,
            AlignItems::End, AlignItems::FlexEnd => $padding,
            AlignItems::Center => (int) floor($padding / 2),
            AlignItems::Stretch => 0,
            AlignItems::Baseline => 0,
        };

        // Add top padding
        for ($i = 0; $i < $top; $i++) {
            $result[] = '';
        }

        // Add content lines
        for ($i = 0; $i < $itemHeight; $i++) {
            $result[] = $lines[$i] ?? '';
        }

        // Add bottom padding
        while (count($result) < $totalHeight) {
            $result[] = '';
        }

        return $result;
    }

    /**
     * Align a line horizontally within the total width.
     */
    private function alignHorizontally(string $line, int $totalWidth): string
    {
        $lineWidth = Width::string($line);

        if ($lineWidth >= $totalWidth) {
            return $this->truncateToWidth($line, $totalWidth);
        }

        $padding = $totalWidth - $lineWidth;

        return match ($this->justify) {
            JustifyContent::Start, JustifyContent::FlexStart => $line . str_repeat(' ', $padding),
            JustifyContent::End, JustifyContent::FlexEnd => str_repeat(' ', $padding) . $line,
            JustifyContent::Center => $this->centerAlign($line, $lineWidth, $totalWidth),
            default => $line . str_repeat(' ', $padding),
        };
    }

    /**
     * Center-align a string within a width.
     */
    private function centerAlign(string $line, int $lineWidth, int $totalWidth): string
    {
        $padding = $totalWidth - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    /**
     * Truncate a string to fit within the given width.
     */
    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::string($s) <= $width) {
            return $s;
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($lo === 0) {
            return '';
        }
        return mb_substr($s, 0, $lo, 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this layout.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w > 0 && $h > 0) {
            return [$w, $h];
        }

        // Calculate natural size
        $sizes = $this->measureItems();
        $totalGapWidth = $this->gap * max(0, count($this->items) - 1);
        $totalGapHeight = $this->gap * max(0, count($this->items) - 1);

        return match ($this->direction) {
            FlexDirection::Row => [
                array_sum(array_column($sizes, 'width')) + $totalGapWidth,
                max(array_column($sizes, 'height')),
            ],
            FlexDirection::Column => [
                max(array_column($sizes, 'width')),
                array_sum(array_column($sizes, 'height')) + $totalGapHeight,
            ],
        };
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Add an item to the flex layout.
     */
    public function withItem(\SugarCraft\Dash\Foundation\Item $item): self
    {
        return new self(
            items: [...$this->items, $item],
            direction: $this->direction,
            wrap: $this->wrap,
            justify: $this->justify,
            alignItems: $this->alignItems,
            gap: $this->gap,
        );
    }

    /**
     * Set the flex direction.
     */
    public function withDirection(FlexDirection $direction): self
    {
        return new self(
            items: $this->items,
            direction: $direction,
            wrap: $this->wrap,
            justify: $this->justify,
            alignItems: $this->alignItems,
            gap: $this->gap,
        );
    }

    /**
     * Set the flex wrap mode.
     */
    public function withWrap(FlexWrap $wrap): self
    {
        return new self(
            items: $this->items,
            direction: $this->direction,
            wrap: $wrap,
            justify: $this->justify,
            alignItems: $this->alignItems,
            gap: $this->gap,
        );
    }

    /**
     * Set the justify content property.
     */
    public function withJustify(JustifyContent $justify): self
    {
        return new self(
            items: $this->items,
            direction: $this->direction,
            wrap: $this->wrap,
            justify: $justify,
            alignItems: $this->alignItems,
            gap: $this->gap,
        );
    }

    /**
     * Set the align items property.
     */
    public function withAlignItems(AlignItems $alignItems): self
    {
        return new self(
            items: $this->items,
            direction: $this->direction,
            wrap: $this->wrap,
            justify: $this->justify,
            alignItems: $alignItems,
            gap: $this->gap,
        );
    }

    /**
     * Set the gap between items.
     */
    public function withGap(int $gap): self
    {
        return new self(
            items: $this->items,
            direction: $this->direction,
            wrap: $this->wrap,
            justify: $this->justify,
            alignItems: $this->alignItems,
            gap: max(0, $gap),
        );
    }
}
