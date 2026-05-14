<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Nav;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A horizontal navigation bar component.
 *
 * Features:
 * - Horizontal list of navigation items
 * - Each item has a label and optional icon
 * - Active item highlighting
 * - Optional brand/title section
 * - Left, center, and right item groups
 * - Customizable colors for items and active state
 * - Border bottom with customizable color
 *
 * Mirrors navbar UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Navbar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, icon?: string, href?: string, isActive?: bool}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly ?string $brand = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $inactiveColor = null,
        private readonly ?Color $bgColor = null,
    ) {}

    /**
     * Create a new navbar with default styling.
     *
     * Default: purple border color, active highlight.
     */
    public static function new(array $items = []): self
    {
        return new self(
            items: $items,
            brand: null,
            borderColor: Color::hex('#874BFD'),
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::hex('#A0A0B0'),
            bgColor: null,
        );
    }

    /**
     * Create a navbar with a brand name.
     */
    public static function brand(string $brand, array $items = []): self
    {
        return new self(
            items: $items,
            brand: $brand,
            borderColor: Color::hex('#874BFD'),
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::hex('#A0A0B0'),
            bgColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this navbar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the navbar as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 80;
        $useWidth = max($useWidth, 1);

        // Build the navbar content
        $content = $this->buildNavbarContent($useWidth);

        // Apply background to content
        $bgPrefix = $this->bgColor !== null ? $this->bgColor->toBg(ColorProfile::TrueColor) : '';
        $bgReset = $this->bgColor !== null ? Ansi::reset() : '';

        $lines = explode("\n", $content);
        $output = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth < $useWidth) {
                $line .= str_repeat(' ', $useWidth - $lineWidth);
            } elseif ($lineWidth > $useWidth) {
                $line = $this->truncateToWidth($line, $useWidth);
            }
            $output[] = $bgPrefix . $line . $bgReset;
        }

        // Add bottom border if borderColor is set
        if ($this->borderColor !== null) {
            $borderLine = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $useWidth) . Ansi::reset();
            $output[] = $borderLine;
        }

        // Apply background to border line as well
        if ($this->bgColor !== null) {
            $lastIndex = count($output) - 1;
            $output[$lastIndex] = $this->bgColor->toBg(ColorProfile::TrueColor) . $output[$lastIndex] . Ansi::reset();
        }

        return implode("\n", $output);
    }

    /**
     * Build the navbar content string.
     */
    private function buildNavbarContent(int $width): string
    {
        if ($this->items === [] && $this->brand === null) {
            return str_repeat(' ', $width);
        }

        // Calculate available space for items
        $brandWidth = $this->brand !== null ? Width::string($this->brand) + 2 : 0;

        // Build item strings
        $itemStrings = [];
        foreach ($this->items as $item) {
            $label = $item['label'];
            $icon = $item['icon'] ?? '';
            $isActive = $item['isActive'] ?? false;

            $text = $icon !== '' ? $icon . ' ' . $label : $label;
            $itemStrings[] = [
                'text' => $text,
                'isActive' => $isActive,
                'width' => Width::string($text),
            ];
        }

        // Calculate total items width
        $totalItemsWidth = 0;
        foreach ($itemStrings as $item) {
            $totalItemsWidth += $item['width'] + 2; // +2 for spacing
        }

        // Build the navbar line
        $result = '';

        // Brand on left
        if ($this->brand !== null) {
            $result .= $this->brand;
            if ($this->borderColor !== null) {
                $result .= ' ';
            }
        }

        // Items
        foreach ($itemStrings as $i => $item) {
            if ($i > 0) {
                $result .= '  ';
            }

            if ($item['isActive'] && $this->activeColor !== null) {
                $result .= $this->activeColor->toFg(ColorProfile::TrueColor);
            } elseif (!$item['isActive'] && $this->inactiveColor !== null) {
                $result .= $this->inactiveColor->toFg(ColorProfile::TrueColor);
            }

            $result .= $item['text'];

            if ($item['isActive'] || !$item['isActive']) {
                $result .= Ansi::reset();
            }
        }

        return $result;
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
        return mb_substr($s, 0, max(1, $lo), 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this navbar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 80;

        // Height is 1 for content, +1 for bottom border if borderColor is set
        $height = 1;
        if ($this->borderColor !== null) {
            $height = 2; // Content line + border line
        }

        return [$useWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the navbar items.
     *
     * @param list<array{label: string, icon?: string, href?: string, isActive?: bool}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            brand: $this->brand,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the brand name.
     */
    public function withBrand(?string $brand): self
    {
        return new self(
            items: $this->items,
            brand: $brand,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            brand: $this->brand,
            borderColor: $color,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the active item color.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            brand: $this->brand,
            borderColor: $this->borderColor,
            activeColor: $color,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the inactive item color.
     */
    public function withInactiveColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            brand: $this->brand,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $color,
            bgColor: $this->bgColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            brand: $this->brand,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $color,
        );
    }

    /**
     * Mark an item as active by index.
     */
    public function withActiveItem(int $index): self
    {
        $items = array_map(function (array $item, int $i) use ($index): array {
            $item['isActive'] = $i === $index;
            return $item;
        }, $this->items, array_keys($this->items));

        return new self(
            items: $items,
            brand: $this->brand,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
        );
    }
}
