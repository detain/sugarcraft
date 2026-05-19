<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A vertical stack layout component with alignment options.
 *
 * Arranges items vertically with alignment options (left, center, right).
 * Each item takes its natural width, and items can be aligned within
 * the allocated width.
 *
 * Mirrors VStack layout concepts adapted to PHP with wither-style immutable setters.
 */
final class VStack implements \SugarCraft\Dash\Foundation\Sizer
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
     * Create a new vertical stack with default styling (left-aligned).
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
     * Create a vertical stack with spacing between items.
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
     * Create a vertically centered stack (items centered horizontally).
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
     * Create a right-aligned vertical stack.
     */
    public static function right(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
            alignment: HAlign::Right,
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
     * Render the stack as a string.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $useWidth = $this->getWidth();
        $results = [];

        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $item = $item->setSize($useWidth, 0);
            }
            $results[] = $item->render();
        }

        $spacer = $this->spacing > 0 ? str_repeat("\n", $this->spacing) : "\n";

        return implode($spacer, $results);
    }

    /**
     * Get the width to use for this stack.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
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

        $maxWidth = 0;
        $totalHeight = 0;

        foreach ($this->items as $index => $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, $h] = $item->getInnerSize();
                $maxWidth = max($maxWidth, $w);
                $totalHeight += $h;
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $maxLineWidth = 0;
                foreach ($lines as $line) {
                    $maxLineWidth = max($maxLineWidth, Width::string($line));
                }
                $maxWidth = max($maxWidth, $maxLineWidth);
                $totalHeight += count($lines);
            }

            // Add spacing after each item except the last
            if ($index < count($this->items) - 1) {
                $totalHeight += $this->spacing;
            }
        }

        return [$maxWidth, $totalHeight];
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
     *
     * Children that implement Drawable will receive the theme via withTheme().
     * Non-themed children are passed through unchanged.
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
