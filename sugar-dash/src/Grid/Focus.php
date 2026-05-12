<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Focus management for form elements.
 *
 * Features:
 * - Track focused element
 * - Style focused element differently
 * - Navigate between elements
 * - Support for any Item as focusable content
 *
 * Mirrors focus handling from bubble-focus but adapted
 * to PHP with wither-style immutable setters.
 */
final class Focus implements Sizer
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $focusedIndex = 0,
        private readonly ?Color $focusForeground = null,
        private readonly ?Color $focusBackground = null,
        private readonly ?Color $unfocusedForeground = null,
        private readonly ?Color $unfocusedBackground = null,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
    ) {}

    /**
     * Create a new focus manager with default styling.
     */
    public static function new(array $items = []): self
    {
        return new self(
            items: $items,
            focusedIndex: 0,
            focusForeground: Color::hex('#1A1B26'),
            focusBackground: Color::hex('#874BFD'),
            unfocusedForeground: null,
            unfocusedBackground: null,
            width: null,
            height: null,
        );
    }

    /**
     * Set the allocated dimensions for this focus manager.
     */
    public function setSize(int $width, int $height): Sizer
    {
        return new self(
            items: $this->items,
            focusedIndex: $this->focusedIndex,
            focusForeground: $this->focusForeground,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $this->unfocusedBackground,
            width: $width,
            height: $height,
        );
    }

    /**
     * Render the currently focused item with styling.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $focusedIndex = $this->getValidFocusedIndex();
        $focusedItem = $this->items[$focusedIndex] ?? null;

        if ($focusedItem === null) {
            return '';
        }

        // Render the focused item with its size (if dimensions are set)
        $item = $focusedItem;
        $width = $this->getWidth();
        $height = $this->getHeight();
        if ($item instanceof Sizer && ($width > 0 || $height > 0)) {
            $item = $item->setSize($width, $height);
        }
        $content = $item->render();

        // Apply focus styling if colors are set
        if ($this->focusForeground !== null || $this->focusBackground !== null) {
            $result = '';
            if ($this->focusBackground !== null) {
                $result .= $this->focusBackground->toBg(ColorProfile::TrueColor);
            }
            if ($this->focusForeground !== null) {
                $result .= $this->focusForeground->toFg(ColorProfile::TrueColor);
            }
            $result .= $content;
            $result .= Ansi::reset();
            return $result;
        }

        return $content;
    }

    /**
     * Render all items with only the focused one having special styling.
     *
     * @return list<string>
     */
    public function renderAll(): array
    {
        if ($this->items === []) {
            return [];
        }

        $focusedIndex = $this->getValidFocusedIndex();
        $results = [];

        for ($i = 0; $i < count($this->items); $i++) {
            $item = $this->items[$i];

            if ($item instanceof Sizer) {
                $item = $item->setSize($this->getWidth(), $this->getHeight());
            }
            $content = $item->render();

            if ($i === $focusedIndex) {
                // Apply focus styling
                if ($this->focusForeground !== null || $this->focusBackground !== null) {
                    $styled = '';
                    if ($this->focusBackground !== null) {
                        $styled .= $this->focusBackground->toBg(ColorProfile::TrueColor);
                    }
                    if ($this->focusForeground !== null) {
                        $styled .= $this->focusForeground->toFg(ColorProfile::TrueColor);
                    }
                    $styled .= $content;
                    $styled .= Ansi::reset();
                    $results[] = $styled;
                } else {
                    $results[] = $content;
                }
            } else {
                // Apply unfocused styling
                if ($this->unfocusedForeground !== null || $this->unfocusedBackground !== null) {
                    $styled = '';
                    if ($this->unfocusedBackground !== null) {
                        $styled .= $this->unfocusedBackground->toBg(ColorProfile::TrueColor);
                    }
                    if ($this->unfocusedForeground !== null) {
                        $styled .= $this->unfocusedForeground->toFg(ColorProfile::TrueColor);
                    }
                    $styled .= $content;
                    $styled .= Ansi::reset();
                    $results[] = $styled;
                } else {
                    $results[] = $content;
                }
            }
        }

        return $results;
    }

    /**
     * Get the valid focused index (clamped to valid range).
     */
    private function getValidFocusedIndex(): int
    {
        $count = count($this->items);
        if ($count === 0) {
            return 0;
        }
        return max(0, min($this->focusedIndex, $count - 1));
    }

    /**
     * Get the width of the focus area.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Get the height of the focus area.
     */
    private function getHeight(): int
    {
        return $this->height ?? 0;
    }

    /**
     * Calculate the natural dimensions of this focus manager.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->items === []) {
            return [0, 0];
        }

        $focusedIndex = $this->getValidFocusedIndex();
        $focusedItem = $this->items[$focusedIndex] ?? null;

        if ($focusedItem === null) {
            return [0, 0];
        }

        return $focusedItem->getInnerSize();
    }

    // ─── Navigation ───────────────────────────────────────────────

    /**
     * Move focus to the next item (wraps around).
     */
    public function next(): self
    {
        if ($this->items === []) {
            return $this;
        }

        $nextIndex = ($this->focusedIndex + 1) % count($this->items);
        return $this->withFocusedIndex($nextIndex);
    }

    /**
     * Move focus to the previous item (wraps around).
     */
    public function previous(): self
    {
        if ($this->items === []) {
            return $this;
        }

        $count = count($this->items);
        $prevIndex = ($this->focusedIndex - 1 + $count) % $count;
        return $this->withFocusedIndex($prevIndex);
    }

    /**
     * Move focus to the first item.
     */
    public function first(): self
    {
        return $this->withFocusedIndex(0);
    }

    /**
     * Move focus to the last item.
     */
    public function last(): self
    {
        return $this->withFocusedIndex(count($this->items) - 1);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the items to focus between.
     */
    public function withItems(array $items): self
    {
        // Clamp focused index to new items range
        $newIndex = $this->focusedIndex >= count($items)
            ? max(0, count($items) - 1)
            : $this->focusedIndex;

        return new self(
            items: $items,
            focusedIndex: $newIndex,
            focusForeground: $this->focusForeground,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $this->unfocusedBackground,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the focused index.
     */
    public function withFocusedIndex(int $index): self
    {
        return new self(
            items: $this->items,
            focusedIndex: max(0, min($index, count($this->items) - 1)),
            focusForeground: $this->focusForeground,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $this->unfocusedBackground,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the focus foreground color.
     */
    public function withFocusForeground(?Color $color): self
    {
        return new self(
            items: $this->items,
            focusedIndex: $this->focusedIndex,
            focusForeground: $color,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $this->unfocusedBackground,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the focus background color.
     */
    public function withFocusBackground(?Color $color): self
    {
        return new self(
            items: $this->items,
            focusedIndex: $this->focusedIndex,
            focusForeground: $this->focusForeground,
            focusBackground: $color,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $this->unfocusedBackground,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the unfocused foreground color.
     */
    public function withUnfocusedForeground(?Color $color): self
    {
        return new self(
            items: $this->items,
            focusedIndex: $this->focusedIndex,
            focusForeground: $this->focusForeground,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $color,
            unfocusedBackground: $this->unfocusedBackground,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the unfocused background color.
     */
    public function withUnfocusedBackground(?Color $color): self
    {
        return new self(
            items: $this->items,
            focusedIndex: $this->focusedIndex,
            focusForeground: $this->focusForeground,
            focusBackground: $this->focusBackground,
            unfocusedForeground: $this->unfocusedForeground,
            unfocusedBackground: $color,
            width: $this->width,
            height: $this->height,
        );
    }
}
