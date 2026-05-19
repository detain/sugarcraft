<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A horizontal stack layout component.
 *
 * Arranges items horizontally in a row, with optional
 * spacing between items. All items share the same vertical space.
 *
 * Mirrors HStack layout concepts adapted to PHP with wither-style immutable setters.
 */
final class HStack implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $spacing = 0,
        private readonly HAlign $alignment = HAlign::Left,
    ) {}

    /**
     * Create a new horizontal stack with default styling.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
            alignment: HAlign::Left,
        );
    }

    /**
     * Create a horizontal stack with spacing between items.
     */
    public static function spaced(int $spacing, \SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: max(0, $spacing),
            alignment: HAlign::Left,
        );
    }

    /**
     * Create a horizontally centered stack.
     */
    public static function centered(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
            alignment: HAlign::Center,
        );
    }

    /**
     * Set the allocated dimensions for this stack.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the stack as a single line string.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $useWidth = $this->getWidth();
        $useHeight = $this->getHeight();

        // Render each item and get its dimensions
        $renderedItems = [];
        $itemWidths = [];
        $itemHeights = [];
        $maxHeight = 0;

        foreach ($this->items as $item) {
            $sizedItem = $item;
            if ($sizedItem instanceof \SugarCraft\Dash\Foundation\Sizer) {
                // Give each item natural width, fixed height
                $sizedItem = $sizedItem->setSize(0, $useHeight);
            }
            $rendered = $sizedItem->render();
            $renderedItems[] = $rendered;

            // Calculate dimensions
            $lines = explode("\n", $rendered);
            $maxLineWidth = 0;
            foreach ($lines as $line) {
                $maxLineWidth = max($maxLineWidth, Width::string($line));
            }
            $itemWidths[] = $maxLineWidth;
            $itemHeights[] = count($lines);
            $maxHeight = max($maxHeight, count($lines));
        }

        // Calculate total width
        $totalWidth = array_sum($itemWidths);
        if ($this->spacing > 0 && count($this->items) > 1) {
            $totalWidth += $this->spacing * (count($this->items) - 1);
        }

        // Build result
        $result = '';

        if ($this->alignment === HAlign::Center && $useWidth > $totalWidth) {
            $pad = (int) floor(($useWidth - $totalWidth) / 2);
            $result .= str_repeat(' ', $pad);
        } elseif ($this->alignment === HAlign::Right && $useWidth > $totalWidth) {
            $pad = $useWidth - $totalWidth;
            $result .= str_repeat(' ', $pad);
        }

        foreach ($renderedItems as $i => $rendered) {
            $result .= $rendered;
            if ($i < count($renderedItems) - 1 && $this->spacing > 0) {
                $result .= str_repeat(' ', $this->spacing);
            }
        }

        return $result;
    }

    /**
     * Get the width to use for this stack.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Get the height to use for this stack.
     */
    private function getHeight(): int
    {
        return $this->height ?? 0;
    }

    /**
     * Calculate the natural dimensions of this stack.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->items === []) {
            return [0, 0];
        }

        $totalWidth = 0;
        $maxHeight = 0;

        foreach ($this->items as $index => $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, $h] = $item->getInnerSize();
                $totalWidth += $w;
                $maxHeight = max($maxHeight, $h);
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $maxLineWidth = 0;
                foreach ($lines as $line) {
                    $maxLineWidth = max($maxLineWidth, Width::string($line));
                }
                $totalWidth += $maxLineWidth;
                $maxHeight = max($maxHeight, count($lines));
            }

            // Add spacing after each item except the last
            if ($index < count($this->items) - 1) {
                $totalWidth += $this->spacing;
            }
        }

        return [$totalWidth, $maxHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the items in this stack.
     *
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Add an item to the end of the stack.
     */
    public function withAppended(\SugarCraft\Dash\Foundation\Item $item): self
    {
        return new self(
            items: [...$this->items, $item],
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Add an item to the beginning of the stack.
     */
    public function withPrepended(\SugarCraft\Dash\Foundation\Item $item): self
    {
        return new self(
            items: [$item, ...$this->items],
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the spacing between items.
     */
    public function withSpacing(int $spacing): self
    {
        return new self(
            items: $this->items,
            spacing: max(0, $spacing),
            alignment: $this->alignment,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withAlignment(HAlign $alignment): self
    {
        return new self(
            items: $this->items,
            spacing: $this->spacing,
            alignment: $alignment,
        );
    }

    /**
     * Apply a theme, fanning it down to any theme-aware children.
     */
    public function withTheme(Theme $theme): self
    {
        $themedItems = [];
        foreach ($this->items as $item) {
            if ($item instanceof Drawable) {
                $themedItems[] = $item->withTheme($theme);
            } else {
                $themedItems[] = $item;
            }
        }

        return new self(
            items: $themedItems,
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }
}
