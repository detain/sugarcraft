<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Dashboard\Output\Truncate;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\SizedItem;
use SugarCraft\Core\Util\Width;

/**
 * A stack layout that arranges items in a stack (vertical by default).
 */
final class StackLayout implements Item, SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $spacing = 0,
        private readonly LayoutDirection $direction = LayoutDirection::Vertical,
    ) {}

    public static function vertical(Item ...$items): self
    {
        return new self($items, 0, LayoutDirection::Vertical);
    }

    public static function horizontal(Item ...$items): self
    {
        return new self($items, 0, LayoutDirection::Horizontal);
    }

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 && $h <= 0) {
            // Natural size - render each item and get max width
            $lines = [];
            foreach ($this->items as $item) {
                $lines[] = $item->render();
            }
            // D-157 (finding 219): one newline always separates two painted
            // blobs; $spacing then adds its blank rows on top, matching the
            // vertical path (old max(1, spacing) silently swallowed every
            // blank row past the first).
            return implode(str_repeat("\n", 1 + max(0, $this->spacing)), $lines);
        }

        if ($this->direction === LayoutDirection::Vertical) {
            return $this->renderVertical($w, $h);
        }

        return $this->renderHorizontal($w, $h);
    }

    private function renderVertical(int $width, int $height): string
    {
        $lines = [];
        $currentY = 0;

        foreach ($this->items as $item) {
            if ($item instanceof SizedItem) {
                $itemHeight = $item->innerSize()[1] ?? 0;
                $sized = $item->setSize($width, $itemHeight);
                $rendered = $sized->render();
            } else {
                $rendered = $item->render();
            }

            $itemLines = explode("\n", $rendered);
            foreach ($itemLines as $line) {
                if ($width > 0) {
                    $lineWidth = Width::string($line);
                    if ($lineWidth < $width) {
                        $line = $line . str_repeat(' ', $width - $lineWidth);
                    }
                }
                $lines[] = $line;
            }

            // Add spacing
            if ($this->spacing > 0) {
                for ($i = 0; $i < $this->spacing; $i++) {
                    $lines[] = $width > 0 ? str_repeat(' ', $width) : '';
                }
            }
        }

        return implode("\n", $lines);
    }

    private function renderHorizontal(int $width, int $height): string
    {
        // Finding sugar-dashboard-019: horizontal mode handed EVERY child
        // the full allocation width - setSize($width, ...) plus a pad to
        // $width - so an n-child row came back n*$width wide, overflowing
        // the frame by its own child count. Horizontal mode now divides the
        // allocation: equal split (the doc-honest default), gap cells taken
        // out of the budget first, indivisible slack given to the earliest
        // children, each child's render clipped Width-aware to its share.
        $count = count($this->items);
        $gapTotal = $this->spacing > 0 && $count > 1 ? $this->spacing * ($count - 1) : 0;
        $budget = max(0, $width - $gapTotal);
        $share = intdiv($budget, max(1, $count));
        $slack = $budget - $share * $count;

        $widthFor = static fn (int $index): int => $share + ($index < $slack ? 1 : 0);

        $allLines = [];
        $maxHeight = 0;

        // Render all items and collect lines, each at its own share
        foreach ($this->items as $index => $item) {
            $itemWidth = $widthFor($index);

            if ($item instanceof Sizer) {
                $sized = $this->sizedShare($item, $itemWidth, $height);
                $rendered = $sized->render();
            } else {
                $rendered = $item->render();
            }

            $itemLines = explode("\n", $rendered);
            $maxHeight = max($maxHeight, count($itemLines));

            $fittedLines = [];
            foreach ($itemLines as $line) {
                if ($itemWidth > 0) {
                    $lineWidth = Width::string($line);
                    if ($lineWidth > $itemWidth) {
                        $line = Truncate::truncate($line, $itemWidth, '');
                        $lineWidth = Width::string($line);
                    }
                    if ($lineWidth < $itemWidth) {
                        $line .= str_repeat(' ', $itemWidth - $lineWidth);
                    }
                }
                $fittedLines[] = $line;
            }

            $allLines[] = $fittedLines;
        }

        // Join horizontally
        $result = [];
        for ($i = 0; $i < $maxHeight; $i++) {
            $row = '';
            foreach ($allLines as $index => $itemLines) {
                $row .= $itemLines[$i] ?? str_repeat(' ', $widthFor($index));
                // Gap between items only - never trailing past the last
                // child, or the row would exceed the budget it just split.
                if ($this->spacing > 0 && $index < $count - 1) {
                    $row .= str_repeat(' ', $this->spacing);
                }
            }
            $result[] = $row;
        }

        return implode("\n", $result);
    }

    /**
     * Size a child for its share, preferring its natural extent over a
     * lying zero down the height channel (finding sugar-dashboard-032
     * family; see Stack::sizedFor - same rule, this container's copy).
     */
    private function sizedShare(Sizer $item, int $width, int $height): Sizer
    {
        if ($width > 0 && $height > 0) {
            return $item->setSize($width, $height);
        }

        if ($item instanceof \SugarCraft\Dashboard\Foundation\Measurable) {
            $natural = $item->naturalExtent($width);
        } elseif ($item instanceof SizedItem) {
            $inner = $item->innerSize();
            $natural = $inner[0] > 0 ? $inner : [0, 0];
        } else {
            $natural = [0, 0];
        }

        return $item->setSize(
            $width > 0 ? $width : $natural[0],
            $height > 0 ? $height : $natural[1],
        );
    }

    public function innerSize(): array
    {
        return [$this->width ?? 0, $this->height ?? 0];
    }

    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            spacing: $this->spacing,
            direction: $this->direction,
        );
    }

    public function withSpacing(int $spacing): self
    {
        return new self(
            items: $this->items,
            // D-157: the house clamp every sibling (HStack/VStack/Stack)
            // already applies — a negative gap is no gap.
            spacing: max(0, $spacing),
            direction: $this->direction,
        );
    }
}
