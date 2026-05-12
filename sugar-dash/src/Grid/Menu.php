<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A horizontal menu bar component with dropdown submenus.
 *
 * Features:
 * - Horizontal menu bar with multiple top-level items
 * - Each item can have a dropdown submenu
 * - Visual indication of active/hovered item
 * - Keyboard navigation between items and submenus
 *
 * Mirrors menu bar UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Menu implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, items?: array<int, array{label: string, icon?: string, shortcut?: string}>}> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $selectedIndex = 0,
        private readonly int $submenuIndex = 0,
        private readonly bool $submenuOpen = false,
        private readonly ?Color $barColor = null,
        private readonly ?Color $itemColor = null,
        private readonly ?Color $activeItemColor = null,
        private readonly ?Color $submenuColor = null,
    ) {}

    /**
     * Create a new menu with default styling.
     */
    public static function new(array $items): self
    {
        return new self(
            items: $items,
            selectedIndex: 0,
            submenuIndex: 0,
            submenuOpen: false,
            barColor: Color::ansi(8),
            itemColor: null,
            activeItemColor: Color::hex('#3B82F6'),
            submenuColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this menu.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the menu component.
     */
    public function render(): string
    {
        $result = $this->renderBar();

        if ($this->submenuOpen) {
            $result .= "\n" . $this->renderSubmenu();
        }

        return $result;
    }

    /**
     * Render the menu bar with top-level items.
     */
    private function renderBar(): string
    {
        if (empty($this->items)) {
            return '(menu)';
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));
        $parts = [];

        foreach ($this->items as $index => $item) {
            $parts[] = $this->renderBarItem($item, $index === $safeIndex);
        }

        $result = implode('  ', $parts);

        if ($this->barColor !== null) {
            $result = $this->barColor->toFg(ColorProfile::TrueColor) . $result . Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single menu bar item.
     */
    private function renderBarItem(array $item, bool $isActive): string
    {
        $label = $item['label'];
        $indicator = $isActive ? '▶ ' : '';
        $hasSubmenu = !empty($item['items'] ?? []);
        $submenuIndicator = $hasSubmenu ? ' ▾' : '';

        $content = $indicator . $label . $submenuIndicator;

        if ($isActive && $this->activeItemColor !== null) {
            return $this->activeItemColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        if ($this->itemColor !== null) {
            return $this->itemColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render the dropdown submenu for the selected item.
     */
    private function renderSubmenu(): string
    {
        $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));
        $item = $this->items[$safeIndex] ?? null;

        if ($item === null || empty($item['items'] ?? [])) {
            return '';
        }

        $submenuItems = $item['items'];
        $safeSubmenuIndex = max(0, min($this->submenuIndex, count($submenuItems) - 1));

        $lines = [];
        foreach ($submenuItems as $index => $submenuItem) {
            $lines[] = $this->renderSubmenuItem($submenuItem, $index === $safeSubmenuIndex);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single submenu item.
     */
    private function renderSubmenuItem(array $item, bool $isSelected): string
    {
        $label = $item['label'];
        $icon = $item['icon'] ?? ' ';
        $shortcut = $item['shortcut'] ?? '';
        $prefix = $isSelected ? '▶' : ' ';
        $content = $prefix . ' ' . $icon . ' ' . $label;

        if ($shortcut !== '') {
            $padding = max(1, 20 - Width::string($label) - Width::string($icon));
            $content .= str_repeat(' ', $padding) . $shortcut;
        }

        if ($isSelected && $this->activeItemColor !== null) {
            return $this->activeItemColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        if ($this->submenuColor !== null) {
            return $this->submenuColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Calculate the natural dimensions of this menu.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Calculate bar width
        $barWidth = 0;
        foreach ($this->items as $index => $item) {
            $indicator = $index === $this->selectedIndex ? 2 : 0; // '▶ '
            $hasSubmenu = !empty($item['items'] ?? []) ? 2 : 0; // ' ▾'
            $itemWidth = $indicator + Width::string($item['label']) + $hasSubmenu;
            $barWidth += $itemWidth + 2; // 2 for separator between items
        }
        $barWidth = max(0, $barWidth - 2); // Remove trailing separator

        // Calculate submenu dimensions
        $submenuWidth = 0;
        $submenuHeight = 0;

        if ($this->submenuOpen) {
            $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));
            $currentItem = $this->items[$safeIndex] ?? null;

            if ($currentItem !== null && !empty($currentItem['items'] ?? [])) {
                foreach ($currentItem['items'] as $submenuItem) {
                    $icon = $submenuItem['icon'] ?? ' ';
                    $shortcut = $submenuItem['shortcut'] ?? '';
                    // prefix + space + icon + space + label + padding + shortcut
                    $itemWidth = 1 + 1 + Width::string($icon) + 1 + Width::string($submenuItem['label']);
                    if ($shortcut !== '') {
                        $itemWidth += max(1, 20 - Width::string($submenuItem['label']) - Width::string($icon)) + Width::string($shortcut);
                    }
                    if ($itemWidth > $submenuWidth) {
                        $submenuWidth = $itemWidth;
                    }
                }
                $submenuHeight = count($currentItem['items']);
            }
        }

        $width = max($barWidth, $submenuWidth);
        $height = 1 + ($this->submenuOpen ? $submenuHeight : 0);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the selected top-level item index.
     */
    public function withSelectedIndex(int $index): self
    {
        return new self(
            items: $this->items,
            selectedIndex: max(0, min($index, count($this->items) - 1)),
            submenuIndex: 0,
            submenuOpen: false,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the submenu item index.
     */
    public function withSubmenuIndex(int $index): self
    {
        $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));
        $currentItem = $this->items[$safeIndex] ?? null;
        $maxSubmenuIndex = 0;

        if ($currentItem !== null && !empty($currentItem['items'] ?? [])) {
            $maxSubmenuIndex = count($currentItem['items']) - 1;
        }

        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: max(0, min($index, $maxSubmenuIndex)),
            submenuOpen: $this->submenuOpen,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set whether the submenu is open.
     */
    public function withSubmenuOpen(bool $open): self
    {
        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: $this->submenuIndex,
            submenuOpen: $open,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the menu items.
     *
     * @param array<int, array{label: string, items?: array<int, array{label: string, icon?: string, shortcut?: string}>}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            selectedIndex: min($this->selectedIndex, max(0, count($items) - 1)),
            submenuIndex: 0,
            submenuOpen: false,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the bar color.
     */
    public function withBarColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: $this->submenuIndex,
            submenuOpen: $this->submenuOpen,
            barColor: $color,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the item color.
     */
    public function withItemColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: $this->submenuIndex,
            submenuOpen: $this->submenuOpen,
            barColor: $this->barColor,
            itemColor: $color,
            activeItemColor: $this->activeItemColor,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the active item highlight color.
     */
    public function withActiveItemColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: $this->submenuIndex,
            submenuOpen: $this->submenuOpen,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $color,
            submenuColor: $this->submenuColor,
        );
    }

    /**
     * Set the submenu color.
     */
    public function withSubmenuColor(?Color $color): self
    {
        return new self(
            items: $this->items,
            selectedIndex: $this->selectedIndex,
            submenuIndex: $this->submenuIndex,
            submenuOpen: $this->submenuOpen,
            barColor: $this->barColor,
            itemColor: $this->itemColor,
            activeItemColor: $this->activeItemColor,
            submenuColor: $color,
        );
    }
}
