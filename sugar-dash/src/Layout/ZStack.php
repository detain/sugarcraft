<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A layered stack layout component.
 *
 * Renders items on top of each other (layered), with the first item
 * as the bottom layer and the last item as the top layer.
 * All items are aligned and sized to the same dimensions.
 *
 * Mirrors ZStack layout concepts adapted to PHP with wither-style immutable setters.
 */
final class ZStack implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<\SugarCraft\Dash\Foundation\Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly HAlign $alignment = HAlign::Center,
        private readonly VAlign $vAlignment = VAlign::Middle,
    ) {}

    /**
     * Create a new layered stack with default styling.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            alignment: HAlign::Center,
            vAlignment: VAlign::Middle,
        );
    }

    /**
     * Create a layered stack with all items left-aligned.
     */
    public static function left(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            alignment: HAlign::Left,
            vAlignment: VAlign::Middle,
        );
    }

    /**
     * Create a layered stack with all items right-aligned.
     */
    public static function right(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            alignment: HAlign::Right,
            vAlignment: VAlign::Middle,
        );
    }

    /**
     * Create a layered stack with all items top-aligned.
     */
    public static function top(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            alignment: HAlign::Center,
            vAlignment: VAlign::Top,
        );
    }

    /**
     * Create a layered stack with all items bottom-aligned.
     */
    public static function bottom(\SugarCraft\Dash\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            alignment: HAlign::Center,
            vAlignment: VAlign::Bottom,
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
     * Render the layered stack as a string.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $useWidth = $this->getWidth();
        $useHeight = $this->getHeight();

        // Render all items at the allocated size
        $renderedItems = [];
        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $item = $item->setSize($useWidth, $useHeight);
            }
            $renderedItems[] = $item->render();
        }

        // Take the last (top) rendered item as the result
        // ZStack in most UI systems shows the top-most element
        return array_pop($renderedItems) ?? '';
    }

    /**
     * Get the width to use for this stack.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate natural width as max of all items
        $maxWidth = 0;
        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, ] = $item->getInnerSize();
                $maxWidth = max($maxWidth, $w);
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                foreach ($lines as $line) {
                    $maxWidth = max($maxWidth, Width::string($line));
                }
            }
        }

        return $maxWidth;
    }

    /**
     * Get the height to use for this stack.
     */
    private function getHeight(): int
    {
        if ($this->height !== null && $this->height > 0) {
            return $this->height;
        }

        // Calculate natural height as max of all items
        $maxHeight = 0;
        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $h] = $item->getInnerSize();
                $maxHeight = max($maxHeight, $h);
            } else {
                $rendered = $item->render();
                $maxHeight = max($maxHeight, count(explode("\n", $rendered)));
            }
        }

        return $maxHeight;
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
        $maxHeight = 0;

        foreach ($this->items as $item) {
            if ($item instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, $h] = $item->getInnerSize();
                $maxWidth = max($maxWidth, $w);
                $maxHeight = max($maxHeight, $h);
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $maxLineWidth = 0;
                foreach ($lines as $line) {
                    $maxLineWidth = max($maxLineWidth, Width::string($line));
                }
                $maxWidth = max($maxWidth, $maxLineWidth);
                $maxHeight = max($maxHeight, count($lines));
            }
        }

        return [$maxWidth, $maxHeight];
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
            alignment: $this->alignment,
            vAlignment: $this->vAlignment,
        );
    }

    /**
     * Add an item to the top of the stack.
     */
    public function withTop(\SugarCraft\Dash\Foundation\Item $item): self
    {
        return new self(
            items: [...$this->items, $item],
            alignment: $this->alignment,
            vAlignment: $this->vAlignment,
        );
    }

    /**
     * Add an item to the bottom of the stack.
     */
    public function withBottom(\SugarCraft\Dash\Foundation\Item $item): self
    {
        return new self(
            items: [$item, ...$this->items],
            alignment: $this->alignment,
            vAlignment: $this->vAlignment,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withAlignment(HAlign $alignment): self
    {
        return new self(
            items: $this->items,
            alignment: $alignment,
            vAlignment: $this->vAlignment,
        );
    }

    /**
     * Set the vertical alignment.
     */
    public function withVAlignment(VAlign $alignment): self
    {
        return new self(
            items: $this->items,
            alignment: $this->alignment,
            vAlignment: $alignment,
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
            alignment: $this->alignment,
            vAlignment: $this->vAlignment,
        );
    }
}
