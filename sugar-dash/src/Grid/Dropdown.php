<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A dropdown menu component.
 *
 * Features:
 * - Collapsed/expanded states
 * - Custom trigger and menu styling
 * - Navigation between items with keyboard
 * - Optional icons per item
 *
 * Mirrors dropdown UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Dropdown implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, icon?: string}> $items
     */
    public function __construct(
        private readonly string $label,
        private readonly array $items,
        private readonly bool $expanded = false,
        private readonly int $selectedIndex = 0,
        private readonly ?Color $triggerColor = null,
        private readonly ?Color $menuColor = null,
        private readonly ?Color $selectedItemColor = null,
        private readonly string $expandIcon = '▾',
        private readonly string $collapseIcon = '▸',
    ) {}

    /**
     * Create a new dropdown with default styling.
     */
    public static function new(string $label, array $items): self
    {
        return new self(
            label: $label,
            items: $items,
            expanded: false,
            selectedIndex: 0,
            triggerColor: Color::hex('#3B82F6'),
            menuColor: null,
            selectedItemColor: Color::hex('#874BFD'),
            expandIcon: '▾',
            collapseIcon: '▸',
        );
    }

    /**
     * Set the allocated dimensions for this dropdown.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the dropdown component.
     */
    public function render(): string
    {
        $result = $this->renderTrigger();

        if ($this->expanded && !empty($this->items)) {
            $result .= "\n" . $this->renderMenu();
        }

        return $result;
    }

    /**
     * Render the dropdown trigger line.
     */
    private function renderTrigger(): string
    {
        $icon = $this->expanded ? $this->collapseIcon : $this->expandIcon;
        $content = $this->label . ' ' . $icon;

        if ($this->triggerColor !== null) {
            return $this->triggerColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render the dropdown menu items.
     */
    private function renderMenu(): string
    {
        $lines = [];
        $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));

        foreach ($this->items as $index => $item) {
            $lines[] = $this->renderMenuItem($item, $index === $safeIndex);
        }

        $result = implode("\n", $lines);

        if ($this->menuColor !== null || $this->selectedItemColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single menu item.
     */
    private function renderMenuItem(array $item, bool $isSelected): string
    {
        $label = $item['label'];
        $icon = $item['icon'] ?? ' ';
        $prefix = $isSelected ? '▶' : ' ';
        $content = $prefix . ' ' . $icon . ' ' . $label;

        if ($isSelected && $this->selectedItemColor !== null) {
            return $this->selectedItemColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        if ($this->menuColor !== null) {
            return $this->menuColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Calculate the natural dimensions of this dropdown.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $triggerWidth = Width::string($this->label) + 1 + Width::string($this->expandIcon);

        $maxItemWidth = 0;
        foreach ($this->items as $item) {
            $icon = $item['icon'] ?? ' ';
            // prefix + space + icon + space + label
            $itemWidth = 1 + 1 + Width::string($icon) + 1 + Width::string($item['label']);
            if ($itemWidth > $maxItemWidth) {
                $maxItemWidth = $itemWidth;
            }
        }

        $width = max($triggerWidth, $maxItemWidth);
        $height = 1 + ($this->expanded ? count($this->items) : 0);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the dropdown label.
     */
    public function withLabel(string $label): self
    {
        return new self(
            label: $label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the dropdown items.
     *
     * @param array<int, array{label: string, icon?: string}> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            label: $this->label,
            items: $items,
            expanded: $this->expanded,
            selectedIndex: min($this->selectedIndex, count($items) - 1),
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the expanded state.
     */
    public function withExpanded(bool $expanded): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the selected item index.
     */
    public function withSelectedIndex(int $index): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: max(0, min($index, count($this->items) - 1)),
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the trigger color.
     */
    public function withTriggerColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $color,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the menu color.
     */
    public function withMenuColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $color,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set the selected item highlight color.
     */
    public function withSelectedItemColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $color,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        );
    }

    /**
     * Set custom expand/collapse icons.
     */
    public function withIcons(string $expand, string $collapse): self
    {
        return new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $expand,
            collapseIcon: $collapse,
        );
    }
}
