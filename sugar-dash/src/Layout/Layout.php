<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;

/**
 * Advanced layout algorithm for arranging multiple items.
 *
 * Features:
 * - Horizontal and vertical layouts
 * - Flex-like distribution (grow/shrink)
 * - Gap/spacing support
 * - Alignment options
 *
 * Mirrors layout from bubble-layout/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Layout implements \SugarCraft\Dash\Foundation\Sizer
{
    /**
     * @param list<LayoutItem> $children
     */
    public function __construct(
        private readonly array $children,
        private readonly LayoutDirection $direction = LayoutDirection::Horizontal,
        private readonly int $gap = 0,
        private readonly HAlign $horizontalAlign = HAlign::Left,
        private readonly VAlign $verticalAlign = VAlign::Top,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
    ) {}

    /**
     * Create a new horizontal layout.
     *
     * @param list<LayoutItem> $children
     */
    public static function horizontal(array $children = []): self
    {
        return new self(
            children: $children,
            direction: LayoutDirection::Horizontal,
            gap: 0,
            horizontalAlign: HAlign::Left,
            verticalAlign: VAlign::Top,
            width: null,
            height: null,
        );
    }

    /**
     * Create a new vertical layout.
     *
     * @param list<LayoutItem> $children
     */
    public static function vertical(array $children = []): self
    {
        return new self(
            children: $children,
            direction: LayoutDirection::Vertical,
            gap: 0,
            horizontalAlign: HAlign::Left,
            verticalAlign: VAlign::Top,
            width: null,
            height: null,
        );
    }

    /**
     * Set the allocated dimensions for this layout.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        return new self(
            children: $this->children,
            direction: $this->direction,
            gap: $this->gap,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
            width: $width,
            height: $height,
        );
    }

    /**
     * Render the layout and all children.
     */
    public function render(): string
    {
        if ($this->children === []) {
            return '';
        }

        if ($this->getWidth() <= 0 || $this->getHeight() <= 0) {
            return '';
        }

        return match ($this->direction) {
            LayoutDirection::Horizontal => $this->renderHorizontal(),
            LayoutDirection::Vertical => $this->renderVertical(),
        };
    }

    /**
     * Render children in a horizontal layout.
     */
    private function renderHorizontal(): string
    {
        $width = $this->getWidth();
        $height = $this->getHeight();

        if ($width <= 0 || $height <= 0) {
            return '';
        }

        // Calculate sizes for all children
        $sizes = $this->calculateHorizontalSizes($width, $height);

        // Render each child and collect lines
        $lines = array_fill(0, $height, '');
        $currentX = 0;

        foreach ($sizes as $index => $size) {
            $child = $this->children[$index];
            $childWidth = $size['width'];
            $childHeight = $size['height'];

            // Set size on child if it's a Sizer
            $content = $child->content;
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $content = $content->setSize($childWidth, $childHeight);
            }
            $rendered = $content->render();

            // Split into lines
            $childLines = explode("\n", $rendered);

            // Align vertically
            $alignedLines = $this->alignVertical($childLines, $childHeight, $height, $this->verticalAlign);

            // Add to output lines with horizontal spacing
            for ($y = 0; $y < $height; $y++) {
                $lineContent = $alignedLines[$y] ?? '';
                $lineWidth = Width::string($lineContent);

                // Pad or truncate line content to child width
                if ($lineWidth < $childWidth) {
                    $lineContent .= str_repeat(' ', $childWidth - $lineWidth);
                } elseif ($lineWidth > $childWidth) {
                    $lineContent = $this->truncateToWidth($lineContent, $childWidth);
                }

                $lines[$y] .= $lineContent;

                // Add gap after this child (except last)
                if ($index < count($this->children) - 1 && $this->gap > 0) {
                    $lines[$y] .= str_repeat(' ', $this->gap);
                }
            }

            $currentX += $childWidth + ($index < count($this->children) - 1 ? $this->gap : 0);
        }

        return implode("\n", $lines);
    }

    /**
     * Render children in a vertical layout.
     */
    private function renderVertical(): string
    {
        $width = $this->getWidth();
        $height = $this->getHeight();

        if ($width <= 0 || $height <= 0) {
            return '';
        }

        // Calculate sizes for all children
        $sizes = $this->calculateVerticalSizes($width, $height);

        // Render each child and collect lines
        $lines = [];

        foreach ($sizes as $index => $size) {
            $child = $this->children[$index];
            $childWidth = $size['width'];
            $childHeight = $size['height'];

            // Set size on child if it's a Sizer
            $content = $child->content;
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $content = $content->setSize($childWidth, $childHeight);
            }
            $rendered = $content->render();

            // Split into lines
            $childLines = explode("\n", $rendered);

            // Truncate or pad lines to child width
            $alignedLines = [];
            for ($y = 0; $y < $childHeight; $y++) {
                $lineContent = $childLines[$y] ?? '';
                if (Width::string($lineContent) < $childWidth) {
                    $lineContent .= str_repeat(' ', $childWidth - Width::string($lineContent));
                } elseif (Width::string($lineContent) > $childWidth) {
                    $lineContent = $this->truncateToWidth($lineContent, $childWidth);
                }
                $alignedLines[] = $lineContent;
            }

            // Pad with empty lines if needed
            while (count($alignedLines) < $childHeight) {
                $alignedLines[] = str_repeat(' ', $childWidth);
            }

            // Add to output
            $lines = array_merge($lines, $alignedLines);

            // Add gap after this child (except last)
            if ($index < count($this->children) - 1 && $this->gap > 0) {
                for ($i = 0; $i < $this->gap; $i++) {
                    $lines[] = str_repeat(' ', $width);
                }
            }
        }

        // Apply horizontal alignment to all lines
        $result = [];
        foreach ($lines as $line) {
            $result[] = $this->alignHorizontal($line, $width, $this->horizontalAlign);
        }

        return implode("\n", $result);
    }

    /**
     * Calculate sizes for horizontal layout.
     *
     * @return list<array{width:int,height:int}>
     */
    private function calculateHorizontalSizes(int $totalWidth, int $totalHeight): array
    {
        $sizes = [];
        $totalGapWidth = ($this->gap * max(0, count($this->children) - 1));
        $availableWidth = max(0, $totalWidth - $totalGapWidth);

        // Calculate flex and total weight
        $totalWeight = 0;
        $flexChildren = [];
        foreach ($this->children as $index => $child) {
            if ($child->flex > 0) {
                $totalWeight += $child->flex;
                $flexChildren[] = $index;
            }
        }

        // First pass: calculate fixed sizes and minimums
        $usedWidth = 0;
        foreach ($this->children as $index => $child) {
            if ($child->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$childWidth, $childHeight] = $child->content->getInnerSize();
            } else {
                // Non-Sizer: render to measure natural size
                $rendered = $child->content->render();
                $lines = explode("\n", $rendered);
                $childHeight = count($lines);
                $childWidth = 0;
                foreach ($lines as $line) {
                    $w = Width::string($line);
                    if ($w > $childWidth) {
                        $childWidth = $w;
                    }
                }
            }

            if ($child->flex <= 0) {
                // Fixed size - use width ratio if width is 0
                $width = $childWidth > 0 ? $childWidth : 1;
            } else {
                $width = 0; // Will be calculated based on flex
            }

            $sizes[$index] = [
                'width' => $width,
                'height' => min($childHeight, $totalHeight),
                'fixed' => $child->flex <= 0,
            ];
            $usedWidth += $width;
        }

        // Second pass: distribute flex space
        if ($totalWeight > 0 && $availableWidth > $usedWidth) {
            $flexSpace = $availableWidth - $usedWidth;
            foreach ($flexChildren as $index) {
                $child = $this->children[$index];
                $ratio = $child->flex / $totalWeight;
                $sizes[$index]['width'] = max(1, (int) floor($flexSpace * $ratio));
            }
        }

        // If still not fitting, shrink proportionally
        $totalChildWidth = array_sum(array_column($sizes, 'width'));
        if ($totalChildWidth > $availableWidth && $availableWidth > 0) {
            $scale = $availableWidth / $totalChildWidth;
            foreach ($sizes as $index => &$size) {
                $size['width'] = max(1, (int) floor($size['width'] * $scale));
            }
        }

        return $sizes;
    }

    /**
     * Calculate sizes for vertical layout.
     *
     * @return list<array{width:int,height:int}>
     */
    private function calculateVerticalSizes(int $totalWidth, int $totalHeight): array
    {
        $sizes = [];
        $totalGapHeight = ($this->gap * max(0, count($this->children) - 1));
        $availableHeight = max(0, $totalHeight - $totalGapHeight);

        // Calculate flex and total weight
        $totalWeight = 0;
        $flexChildren = [];
        foreach ($this->children as $index => $child) {
            if ($child->flex > 0) {
                $totalWeight += $child->flex;
                $flexChildren[] = $index;
            }
        }

        // First pass: calculate fixed sizes
        $usedHeight = 0;
        foreach ($this->children as $index => $child) {
            if ($child->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$childWidth, $childHeight] = $child->content->getInnerSize();
            } else {
                // Non-Sizer: render to measure natural size
                $rendered = $child->content->render();
                $lines = explode("\n", $rendered);
                $childHeight = count($lines);
                $childWidth = 0;
                foreach ($lines as $line) {
                    $w = Width::string($line);
                    if ($w > $childWidth) {
                        $childWidth = $w;
                    }
                }
            }

            $height = $childHeight > 0 ? $childHeight : 1;

            $sizes[$index] = [
                'width' => min($childWidth, $totalWidth),
                'height' => $height,
                'fixed' => $child->flex <= 0,
            ];
            $usedHeight += $height;
        }

        // Second pass: distribute flex space
        if ($totalWeight > 0 && $availableHeight > $usedHeight) {
            $flexSpace = $availableHeight - $usedHeight;
            foreach ($flexChildren as $index) {
                $child = $this->children[$index];
                $ratio = $child->flex / $totalWeight;
                $sizes[$index]['height'] = max(1, (int) floor($flexSpace * $ratio));
            }
        }

        return $sizes;
    }

    /**
     * Align child lines vertically within the layout height.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function alignVertical(array $lines, int $childHeight, int $layoutHeight, VAlign $align): array
    {
        $result = [];
        $padding = max(0, $layoutHeight - count($lines));

        if ($padding === 0) {
            return $lines;
        }

        $top = match ($align) {
            VAlign::Top => 0,
            VAlign::Bottom => $padding,
            VAlign::Middle => (int) floor($padding / 2),
        };

        // Add top padding
        for ($i = 0; $i < $top; $i++) {
            $result[] = '';
        }

        // Add content lines
        for ($i = 0; $i < $childHeight; $i++) {
            $result[] = $lines[$i] ?? '';
        }

        // Add bottom padding
        while (count($result) < $layoutHeight) {
            $result[] = '';
        }

        return $result;
    }

    /**
     * Align a line horizontally.
     */
    private function alignHorizontal(string $line, int $width, HAlign $align): string
    {
        $lineWidth = Width::string($line);

        if ($lineWidth >= $width) {
            return $this->truncateToWidth($line, $width);
        }

        $padding = $width - $lineWidth;

        return match ($align) {
            HAlign::Left => $line . str_repeat(' ', $padding),
            HAlign::Right => str_repeat(' ', $padding) . $line,
            HAlign::Center => $this->centerAlign($line, $lineWidth, $width),
        };
    }

    /**
     * Center-align a string within a width.
     */
    private function centerAlign(string $line, int $lineWidth, int $width): string
    {
        $padding = $width - $lineWidth;
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
     * Get the width for this layout.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Get the height for this layout.
     */
    private function getHeight(): int
    {
        return $this->height ?? 0;
    }

    /**
     * Calculate the natural dimensions of this layout.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->getWidth(), $this->getHeight()];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the gap between children.
     */
    public function withGap(int $gap): self
    {
        return new self(
            children: $this->children,
            direction: $this->direction,
            gap: max(0, $gap),
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withHorizontalAlign(HAlign $align): self
    {
        return new self(
            children: $this->children,
            direction: $this->direction,
            gap: $this->gap,
            horizontalAlign: $align,
            verticalAlign: $this->verticalAlign,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the vertical alignment.
     */
    public function withVerticalAlign(VAlign $align): self
    {
        return new self(
            children: $this->children,
            direction: $this->direction,
            gap: $this->gap,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $align,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the direction.
     */
    public function withDirection(LayoutDirection $direction): self
    {
        return new self(
            children: $this->children,
            direction: $direction,
            gap: $this->gap,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the children.
     *
     * @param list<LayoutItem> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            children: $children,
            direction: $this->direction,
            gap: $this->gap,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Add a child to the layout.
     */
    public function withChild(\SugarCraft\Dash\Foundation\Item $content, int $flex = 0): self
    {
        return new self(
            children: [...$this->children, new LayoutItem($content, $flex)],
            direction: $this->direction,
            gap: $this->gap,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
            width: $this->width,
            height: $this->height,
        );
    }
}
