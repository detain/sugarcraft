<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tabs;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A tabbed interface component.
 *
 * Displays a row of tab labels at the top, with the selected tab's content
 * rendered below. Supports:
 * - Multiple tabs with labels and content
 * - Active tab highlighting with color
 * - Tab separator customization
 * - Keyboard navigation via selected tab index
 *
 * Mirrors the tab concept from bubble-tea but adapted to PHP with
 * wither-style immutable setters.
 */
final class Tabs implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param array<int, array{label: string, content: Item}> $tabs
     */
    public function __construct(
        private readonly array $tabs,
        private readonly int $selectedIndex = 0,
        private readonly ?Color $activeColor = null,
        private readonly ?Color $inactiveColor = null,
        private readonly string $separator = '│',
        private readonly string $activeChar = '─',
    ) {}

    /**
     * Create a new tabs component with default styling.
     *
     * Default: purple active tab, gray inactive tabs.
     */
    public static function new(array $tabs): self
    {
        return new self(
            tabs: $tabs,
            selectedIndex: 0,
            activeColor: Color::hex('#874BFD'),
            inactiveColor: Color::ansi(8),
            separator: '│',
            activeChar: '─',
        );
    }

    /**
     * Set the allocated dimensions for these tabs.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the tabs component.
     */
    public function render(): string
    {
        if (empty($this->tabs)) {
            return '';
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->tabs) - 1));
        $tabBar = $this->renderTabBar($safeIndex);
        $content = $this->renderContent($safeIndex);

        $result = $tabBar . "\n" . $content;

        // Ensure we end with reset if any colors were used
        if ($this->activeColor !== null || $this->inactiveColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render the tab bar (label row).
     *
     * Finding sugar-dashboard-052: the underline carried a CONSTANT leading
     * space, so every tab's dash painted at column 2 no matter where its
     * label sat, and a bar with more (or longer) tabs than the allocation
     * overflowed it unclipped. Offsets are now the cumulative cell widths
     * (cell = label + 2 decoration columns: the brackets around the active
     * tab, the flanking spaces of an inactive one), labels truncate to a
     * per-tab cell budget through the canonical Width helper, and the dash
     * covers the active cell exactly.
     */
    private function renderTabBar(int $selectedIndex): string
    {
        $count = count($this->tabs);
        $totalWidth = $this->width ?? 0;
        $sepWidth = Width::string($this->separator);

        $labels = [];
        $cellWidths = [];
        foreach ($this->tabs as $i => $tab) {
            $labels[$i] = $tab['label'];
            $cellWidths[$i] = Width::string($tab['label']) + 2;
        }

        // More tabs (or longer ones) than the allocation: every label gets
        // the same cell budget so no tab is favored when the bar shrinks.
        $natural = array_sum($cellWidths) + $sepWidth * ($count - 1);
        if ($totalWidth > 0 && $natural > $totalWidth) {
            $budget = max(1, intdiv(max(0, $totalWidth - $sepWidth * ($count - 1)), $count) - 2);
            foreach ($labels as $i => $label) {
                $labels[$i] = Width::truncateAnsi($label, $budget);
                $cellWidths[$i] = Width::string($labels[$i]) + 2;
            }
        }

        $parts = [];
        $starts = [];
        $cursor = 0;

        foreach ($this->tabs as $i => $tab) {
            $starts[$i] = $cursor;
            $parts[] = $this->renderTabLabel($labels[$i], $i === $selectedIndex);
            $cursor += $cellWidths[$i];

            // Add separator if not last tab
            if ($i < $count - 1) {
                $sepColor = '';
                if ($this->inactiveColor !== null) {
                    $sepColor = $this->inactiveColor->toFg(ColorMode::profile());
                }
                $parts[] = $sepColor . $this->separator . Ansi::reset();
                $cursor += $sepWidth;
            }
        }

        $result = implode('', $parts);

        // The allocation is the frame: clip residual overflow (widths so
        // small that chrome alone exceeds them), then pad up.
        if ($totalWidth > 0) {
            $resultWidth = Width::string($result);
            if ($resultWidth > $totalWidth) {
                $result = Width::truncateAnsi($result, $totalWidth);
                $resultWidth = Width::string($result);
            }
            if ($resultWidth < $totalWidth) {
                $result .= str_repeat(' ', $totalWidth - $resultWidth);
            }
        }

        // Add active tab underline at the active tab's start column.
        if ($this->activeChar !== '' && isset($this->tabs[$selectedIndex])) {
            $result .= "\n";
            $offset = $starts[$selectedIndex];
            $dashWidth = $cellWidths[$selectedIndex];
            if ($totalWidth > 0) {
                $offset = min($offset, max(0, $totalWidth - 1));
                $dashWidth = max(1, min($dashWidth, $totalWidth - $offset));
            }
            if ($this->activeColor !== null) {
                $result .= $this->activeColor->toFg(ColorMode::profile());
            }
            $result .= str_repeat(' ', $offset) . str_repeat($this->activeChar, $dashWidth);
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a single tab label.
     */
    private function renderTabLabel(string $label, bool $isActive): string
    {
        $color = $isActive ? $this->activeColor : $this->inactiveColor;
        $prefix = $isActive ? '[' : ' ';
        $suffix = $isActive ? ']' : ' ';

        if ($color !== null) {
            return $color->toFg(ColorMode::profile())
                . $prefix . $label . $suffix
                . Ansi::reset();
        }

        return $prefix . $label . $suffix;
    }

    /**
     * Render the content of the selected tab.
     */
    private function renderContent(int $selectedIndex): string
    {
        if (!isset($this->tabs[$selectedIndex])) {
            return '';
        }

        $tab = $this->tabs[$selectedIndex];
        $content = $tab['content'] ?? '';

        $tabBarHeight = $this->activeChar !== '' ? 2 : 1; // Same calculation as innerSize()
        $contentHeight = $this->height !== null ? $this->height - $tabBarHeight : 0;

        if ($content instanceof \SugarCraft\Dashboard\Foundation\Sizer&& $this->width !== null && $contentHeight > 0) {
            $sized = $content->setSize($this->width, $contentHeight);
            return $sized->render();
        }

        return $content->render();
    }

    /**
     * Calculate the natural dimensions of these tabs.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        // Calculate width from tab labels: cell = label + 2 decoration
        // columns (sugar-dashboard-052 alignment with renderTabBar - the
        // old +3 over-counted every cell and added a separator after the
        // LAST tab too).
        $width = 0;
        $last = count($this->tabs) - 1;
        foreach ($this->tabs as $i => $tab) {
            $width += Width::string($tab['label']) + 2;
            if ($i < $last) {
                $width += Width::string($this->separator);
            }
        }

        // Calculate height from selected tab content
        $safeIndex = max(0, min($this->selectedIndex, count($this->tabs) - 1));
        $tabBarHeight = $this->activeChar !== '' ? 2 : 1; // Tab bar + optional underline
        $contentHeight = $tabBarHeight;
        if (isset($this->tabs[$safeIndex])) {
            $tab = $this->tabs[$safeIndex];
            $content = $tab['content'];
            if ($content instanceof \SugarCraft\Dashboard\Foundation\SizedItem) {
                [, $h] = $content->innerSize();
                $contentHeight += $h;
            } else {
                // Estimate single line content
                $contentHeight += 1;
            }
        }

        return [$width, $contentHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the selected tab index.
     */
    public function withSelectedIndex(int $index): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: max(0, min($index, count($this->tabs) - 1)),
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $this->separator,
            activeChar: $this->activeChar,
        );
    }

    /**
     * Set the color for the active tab.
     */
    public function withActiveColor(?Color $color): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $color,
            inactiveColor: $this->inactiveColor,
            separator: $this->separator,
            activeChar: $this->activeChar,
        );
    }

    /**
     * Set the color for inactive tabs.
     */
    public function withInactiveColor(?Color $color): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $this->activeColor,
            inactiveColor: $color,
            separator: $this->separator,
            activeChar: $this->activeChar,
        );
    }

    /**
     * Set the separator character between tabs.
     */
    public function withSeparator(string $separator): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $separator,
            activeChar: $this->activeChar,
        );
    }

    /**
     * Set the character used for active tab underline.
     */
    public function withActiveChar(string $char): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $this->separator,
            activeChar: $char,
        );
    }

    /**
     * Set new tabs content.
     *
     * @param array<int, array{label: string, content: Item}> $tabs
     */
    public function withTabs(array $tabs): self
    {
        return new self(
            tabs: $tabs,
            selectedIndex: min($this->selectedIndex, count($tabs) - 1),
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $this->separator,
            activeChar: $this->activeChar,
        );
    }
}
