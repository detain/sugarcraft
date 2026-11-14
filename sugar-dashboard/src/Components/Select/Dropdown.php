<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A dropdown menu component.
 *
 * Features:
 * - Collapsed/expanded states
 * - Trigger shows the current selection ("label: choice icon")
 * - Custom trigger and menu styling
 * - Navigation between items with keyboard
 * - Optional icons per item
 * - Allocation-honoring setSize(): the trigger and every menu row are
 *   cell-fitted to the allocated width, and an open list is windowed to
 *   the allocated height with the selection kept visible
 *
 * Mirrors dropdown UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Dropdown implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    /** Placeholder painted on the trigger when there is nothing to select. */
    private const NO_SELECTION = '— none —';

    private ?int $allocatedWidth = null;
    private ?int $allocatedHeight = null;

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
     *
     * The allocation is stored and READ by render() and innerSize():
     * non-positive dimensions mean "natural" (finding sugar-dashboard-050:
     * this used to be a plain `clone $this` that dropped the channel).
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->allocatedWidth = $width > 0 ? $width : null;
        $clone->allocatedHeight = $height > 0 ? $height : null;
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
     * Render the dropdown trigger line: label, current selection, icon.
     */
    private function renderTrigger(): string
    {
        $icon = $this->expanded ? $this->collapseIcon : $this->expandIcon;
        $content = $this->label . ': ' . ($this->selectedLabel() ?? self::NO_SELECTION) . ' ' . $icon;

        return $this->fit($content, $this->triggerColor);
    }

    /**
     * The label of the selected item, or null when the list is empty.
     */
    private function selectedLabel(): ?string
    {
        if ($this->items === []) {
            return null;
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->items) - 1));
        return $this->items[$safeIndex]['label'] ?? null;
    }

    /**
     * Render the visible window of the dropdown menu items.
     */
    private function renderMenu(): string
    {
        $count = count($this->items);
        $safeIndex = max(0, min($this->selectedIndex, $count - 1));

        // The trigger eats one row of the allocation; the list is windowed
        // to the rest with the selection kept in view (ListComponent's
        // slice-then-fit pattern).
        $budget = $this->allocatedHeight !== null ? max(1, $this->allocatedHeight - 1) : $count;
        $visible = min($count, $budget);
        $top = max(0, min($safeIndex - intdiv($visible - 1, 2), $count - $visible));

        $lines = [];
        for ($index = $top; $index < $top + $visible; $index++) {
            $lines[] = $this->renderMenuItem($this->items[$index], $index === $safeIndex);
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
            return $this->fit($content, $this->selectedItemColor);
        }

        return $this->fit($content, $this->menuColor);
    }

    /**
     * Cell-fit one painted line to the allocated width, then color it.
     */
    private function fit(string $content, ?Color $color): string
    {
        if ($this->allocatedWidth !== null) {
            $content = Width::padRight(
                Width::truncateAnsi($content, $this->allocatedWidth),
                $this->allocatedWidth,
            );
        }

        if ($color === null) {
            return $content;
        }

        return $color->toFg(ColorMode::profile()) . $content . Ansi::reset();
    }

    /**
     * Calculate the natural dimensions of this dropdown.
     *
     * Reports the allocated size when one was given — the box a caller
     * asked for is the box that will be painted.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $triggerWidth = Width::string($this->label) + 2
            + Width::string($this->selectedLabel() ?? self::NO_SELECTION)
            + 1 + Width::string($this->expandIcon);

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
        if ($this->allocatedWidth !== null) {
            $width = $this->allocatedWidth;
        }

        $height = 1;
        if ($this->expanded && $this->items !== []) {
            $budget = $this->allocatedHeight !== null ? max(1, $this->allocatedHeight - 1) : count($this->items);
            $height += min(count($this->items), $budget);
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Propagate the setSize() allocation through a freshly built carrier.
     */
    private function carryAllocation(self $next): self
    {
        $next->allocatedWidth = $this->allocatedWidth;
        $next->allocatedHeight = $this->allocatedHeight;
        return $next;
    }

    /**
     * Set the dropdown label.
     */
    public function withLabel(string $label): self
    {
        return $this->carryAllocation(new self(
            label: $label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the dropdown items.
     *
     * @param array<int, array{label: string, icon?: string}> $items
     */
    public function withItems(array $items): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $items,
            expanded: $this->expanded,
            selectedIndex: min($this->selectedIndex, count($items) - 1),
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the expanded state.
     */
    public function withExpanded(bool $expanded): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the selected item index.
     */
    public function withSelectedIndex(int $index): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: max(0, min($index, count($this->items) - 1)),
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the trigger color.
     */
    public function withTriggerColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $color,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the menu color.
     */
    public function withMenuColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $color,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set the selected item highlight color.
     */
    public function withSelectedItemColor(?Color $color): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $color,
            expandIcon: $this->expandIcon,
            collapseIcon: $this->collapseIcon,
        ));
    }

    /**
     * Set custom expand/collapse icons.
     */
    public function withIcons(string $expand, string $collapse): self
    {
        return $this->carryAllocation(new self(
            label: $this->label,
            items: $this->items,
            expanded: $this->expanded,
            selectedIndex: $this->selectedIndex,
            triggerColor: $this->triggerColor,
            menuColor: $this->menuColor,
            selectedItemColor: $this->selectedItemColor,
            expandIcon: $expand,
            collapseIcon: $collapse,
        ));
    }
}
