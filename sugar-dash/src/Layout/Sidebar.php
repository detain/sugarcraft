<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A vertical sidebar navigation component.
 *
 * Features:
 * - Vertical list of navigation items
 * - Each item has a label and optional icon
 * - Active item highlighting
 * - Optional header/title section
 * - Optional footer section
 * - Customizable colors for items and active state
 * - Border right with customizable color
 * - Collapsible mode (shows only icons)
 *
 * Mirrors sidebar navigation UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Sidebar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, icon?: string, href?: string, isActive?: bool, isDivider?: bool}> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $inactiveColor = null,
        private readonly ?Color $bgColor = null,
        private readonly bool $collapsed = false,
    ) {}

    /**
     * Create a new sidebar with default styling.
     *
     * Default: purple border color, active highlight.
     */
    public static function new(array $items = []): self
    {
        return new self(
            items: $items,
            title: null,
            borderColor: Color::hex('#874BFD'),
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::hex('#A0A0B0'),
            bgColor: null,
            collapsed: false,
        );
    }

    /**
     * Create a sidebar with a title.
     */
    public static function title(string $title, array $items = []): self
    {
        return new self(
            items: $items,
            title: $title,
            borderColor: Color::hex('#874BFD'),
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::hex('#A0A0B0'),
            bgColor: null,
            collapsed: false,
        );
    }

    /**
     * Set the allocated dimensions for this sidebar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the sidebar as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max(3, $useWidth);
        $useHeight = $this->height ?? 24;

        $result = '';

        // Background color if set
        if ($this->bgColor !== null) {
            $result .= $this->bgColor->toBg(ColorProfile::TrueColor);
        }

        // Border color if set
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        $lines = [];

        // Title section
        if ($this->title !== null) {
            $titleWidth = $useWidth - 2;
            $titleText = $this->truncateToWidth($this->title, $titleWidth);
            $lines[] = ' ' . $titleText . ' ';
            $lines[] = '─' . str_repeat('─', $titleWidth) . '─';
        }

        // Items
        foreach ($this->items as $item) {
            if (isset($item['isDivider']) && $item['isDivider']) {
                // Divider line
                $lines[] = '─' . str_repeat('─', $useWidth - 2) . '─';
            } else {
                $label = $item['label'];
                $icon = $item['icon'] ?? '';
                $isActive = $item['isActive'] ?? false;

                $text = $this->collapsed && $icon !== ''
                    ? $icon
                    : ($icon !== '' ? $icon . ' ' . $label : $label);

                $text = $this->truncateToWidth($text, $useWidth - 2);

                if ($isActive && $this->activeColor !== null) {
                    $lines[] = '>' . $this->activeColor->toFg(ColorProfile::TrueColor) . $text . Ansi::reset() . ' ';
                } elseif (!$isActive && $this->inactiveColor !== null) {
                    $lines[] = ' ' . $this->inactiveColor->toFg(ColorProfile::TrueColor) . $text . Ansi::reset() . ' ';
                } else {
                    $lines[] = ' ' . $text . ' ';
                }
            }
        }

        // Pad to allocated height
        while (count($lines) < $useHeight) {
            $lines[] = str_repeat(' ', $useWidth);
        }

        // Reset ANSI
        $result .= Ansi::reset();

        // Apply background to all lines if set
        if ($this->bgColor !== null) {
            $bgPrefix = $this->bgColor->toBg(ColorProfile::TrueColor);
            $bgReset = Ansi::reset();
            $lines = array_map(function ($line) use ($bgPrefix, $bgReset) {
                return $bgPrefix . $line . $bgReset;
            }, $lines);
        }

        // Pad lines to full width and add border
        $borderChar = $this->borderColor !== null ? '│' : '|';
        $output = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth < $useWidth - 1) {
                $line = mb_substr($line, 0, -1, 'UTF-8') . str_repeat(' ', $useWidth - 1 - $lineWidth) . mb_substr($line, -1, 1, 'UTF-8');
            }
            $borderPrefix = $this->borderColor !== null ? $this->borderColor->toFg(ColorProfile::TrueColor) : '';
            $borderSuffix = $this->borderColor !== null ? Ansi::reset() : '';
            $output[] = $borderPrefix . $borderChar . mb_substr($line, 0, -1, 'UTF-8') . $borderChar . $borderSuffix;
        }

        return implode("\n", $output);
    }

    /**
     * Calculate the natural width based on item labels.
     */
    private function calculateNaturalWidth(): int
    {
        $width = 10; // Minimum width

        if ($this->title !== null) {
            $width = max($width, Width::string($this->title) + 4);
        }

        foreach ($this->items as $item) {
            if (isset($item['isDivider']) && $item['isDivider']) {
                continue;
            }
            $label = $item['label'];
            $icon = $item['icon'] ?? '';
            $text = $icon !== '' ? $icon . ' ' . $label : $label;
            $width = max($width, Width::string($text) + 4);
        }

        return $width;
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
     * Calculate the natural dimensions of this sidebar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max(3, $useWidth);

        $height = count($this->items);
        if ($this->title !== null) {
            $height += 2; // Title line + divider
        }

        return [$useWidth, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the sidebar items.
     *
     * @param list<array{label: string, icon?: string, href?: string, isActive?: bool, isDivider?: bool}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the sidebar title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            items: $this->items,
            title: $title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            borderColor: $color,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the active item color.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $color,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the inactive item color.
     */
    public function withInactiveColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $color,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $color,
            collapsed: $this->collapsed,
        );
    }

    /**
     * Set the collapsed mode.
     */
    public function withCollapsed(bool $collapsed): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $collapsed,
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
            title: $this->title,
            borderColor: $this->borderColor,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            bgColor: $this->bgColor,
            collapsed: $this->collapsed,
        );
    }
}
