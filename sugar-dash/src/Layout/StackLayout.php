<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Width;

/**
 * A stack layout that arranges items in a stack (vertical by default).
 */
final class StackLayout implements Item, Sizer
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
            return implode(str_repeat("\n", max(1, $this->spacing)), $lines);
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
            if ($item instanceof Sizer) {
                $itemHeight = $item->getInnerSize()[1] ?? 0;
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
        $allLines = [];
        $maxHeight = 0;

        // Render all items and collect lines
        foreach ($this->items as $item) {
            if ($item instanceof Sizer) {
                $sized = $item->setSize($width, $height);
                $rendered = $sized->render();
            } else {
                $rendered = $item->render();
            }

            $itemLines = explode("\n", $rendered);
            $maxHeight = max($maxHeight, count($itemLines));

            // Pad all lines to same width
            $paddedLines = [];
            foreach ($itemLines as $line) {
                if ($width > 0) {
                    $lineWidth = Width::string($line);
                    if ($lineWidth < $width) {
                        $line = $line . str_repeat(' ', $width - $lineWidth);
                    }
                }
                $paddedLines[] = $line;
            }

            $allLines[] = $paddedLines;
        }

        // Join horizontally
        $result = [];
        for ($i = 0; $i < $maxHeight; $i++) {
            $row = '';
            foreach ($allLines as $itemLines) {
                $row .= $itemLines[$i] ?? ($width > 0 ? str_repeat(' ', $width) : '');
                // Add spacing between items
                if ($this->spacing > 0) {
                    $row .= str_repeat(' ', $this->spacing);
                }
            }
            $result[] = $row;
        }

        return implode("\n", $result);
    }

    public function getInnerSize(): array
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
            spacing: $spacing,
            direction: $this->direction,
        );
    }
}
