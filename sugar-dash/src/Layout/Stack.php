<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A vertical stack layout component.
 *
 * Arranges items vertically, one after another, with optional
 * spacing between items. Items are rendered in order.
 *
 * Mirrors stack layout concepts adapted to PHP with wither-style immutable setters.
 */
final class Stack implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $spacing = 0,
    ) {}

    /**
     * Create a new vertical stack with default styling.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
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
        $renderedItems = [];

        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer&& $useWidth > 0) {
                $item = $item->setSize($useWidth, 0);
            }
            $renderedItems[] = $item->render();
        }

        $spacer = $this->spacing > 0 ? str_repeat("\n", $this->spacing) : "\n";

        return implode($spacer, $renderedItems);
    }

    /**
     * Get the width to use for this stack.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return 0; // Natural width
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
                $maxWidth = max($maxWidth, max(array_map(fn($l) => Width::string($l), $lines)));
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
     * Add items to the stack.
     *
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            spacing: $this->spacing,
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
        );
    }
}
