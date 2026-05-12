<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A breadcrumb navigation component.
 *
 * Displays a hierarchical path with separators:
 * - Multiple breadcrumb items (home > category > subcategory)
 * - Customizable separator character
 * - Optional styling for current/active item
 * - Truncation support for long paths
 *
 * Mirrors the breadcrumb concept from bubble-tea but adapted
 * to PHP with wither-style immutable setters.
 */
final class Breadcrumb implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<string> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly string $separator = '›',
        private readonly ?Color $separatorColor = null,
        private readonly ?Color $itemColor = null,
        private readonly ?Color $activeColor = null,
        private readonly int $activeIndex = -1,
    ) {}

    /**
     * Create a new breadcrumb with default styling.
     *
     * Default: gray separator, white items.
     */
    public static function new(array $items): self
    {
        return new self(
            items: $items,
            separator: '›',
            separatorColor: Color::ansi(8),
            itemColor: Color::hex('#FFFFFF'),
            activeColor: Color::hex('#874BFD'),
            activeIndex: count($items) - 1,
        );
    }

    /**
     * Create a breadcrumb from a path string (e.g., "Home / Category / Item").
     */
    public static function fromPath(string $path, string $separator = '/'): self
    {
        $items = array_filter(array_map('trim', explode($separator, $path)));
        return self::new(array_values($items));
    }

    /**
     * Set the allocated dimensions for this breadcrumb.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the breadcrumb as a string.
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $result = '';

        foreach ($this->items as $i => $item) {
            $isActive = ($i === $this->activeIndex || ($this->activeIndex === -1 && $i === count($this->items) - 1));
            $color = $isActive ? $this->activeColor : $this->itemColor;

            // Add color if set
            if ($color !== null) {
                $result .= $color->toFg(ColorProfile::TrueColor);
            }

            // Add the item
            $result .= $item;

            // Reset color before separator
            if ($color !== null) {
                $result .= Ansi::reset();
            }

            // Add separator if not last item
            if ($i < count($this->items) - 1) {
                if ($this->separatorColor !== null) {
                    $result .= $this->separatorColor->toFg(ColorProfile::TrueColor);
                }
                $result .= ' ' . $this->separator . ' ';
                if ($this->separatorColor !== null) {
                    $result .= Ansi::reset();
                }
            }
        }

        // If we have allocated width and result is shorter, pad with spaces
        if ($this->width !== null && $this->width > 0) {
            $resultWidth = Width::string($result);
            if ($resultWidth < $this->width) {
                $result .= str_repeat(' ', $this->width - $resultWidth);
            }
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this breadcrumb.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->items)) {
            return [0, 1];
        }

        $totalWidth = 0;
        foreach ($this->items as $i => $item) {
            if ($i > 0) {
                // Separator width: ' ' + separator + ' '
                $totalWidth += Width::string(' ' . $this->separator . ' ');
            }
            $totalWidth += Width::string($item);
        }

        // Use allocated width if set and larger
        $width = $this->width !== null ? max($this->width, $totalWidth) : $totalWidth;

        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set new breadcrumb items.
     *
     * @param list<string> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            separator: $this->separator,
            separatorColor: $this->separatorColor,
            itemColor: $this->itemColor,
            activeColor: $this->activeColor,
            activeIndex: count($items) - 1,
        );
    }

    /**
     * Set the separator character.
     */
    public function withSeparator(string $separator): self
    {
        return new self(
            items: $this->items,
            separator: $separator,
            separatorColor: $this->separatorColor,
            itemColor: $this->itemColor,
            activeColor: $this->activeColor,
            activeIndex: $this->activeIndex,
        );
    }

    /**
     * Set the separator color.
     */
    public function withSeparatorColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            separator: $this->separator,
            separatorColor: $color,
            itemColor: $this->itemColor,
            activeColor: $this->activeColor,
            activeIndex: $this->activeIndex,
        );
    }

    /**
     * Set the item (inactive) color.
     */
    public function withItemColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            separator: $this->separator,
            separatorColor: $this->separatorColor,
            itemColor: $color,
            activeColor: $this->activeColor,
            activeIndex: $this->activeIndex,
        );
    }

    /**
     * Set the active item color.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            separator: $this->separator,
            separatorColor: $this->separatorColor,
            itemColor: $this->itemColor,
            activeColor: $color,
            activeIndex: $this->activeIndex,
        );
    }

    /**
     * Set the active item index (-1 for last item).
     */
    public function withActiveIndex(int $index): self
    {
        return new self(
            items: $this->items,
            separator: $this->separator,
            separatorColor: $this->separatorColor,
            itemColor: $this->itemColor,
            activeColor: $this->activeColor,
            activeIndex: $index,
        );
    }
}
