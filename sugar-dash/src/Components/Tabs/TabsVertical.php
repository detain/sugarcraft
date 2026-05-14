<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tabs;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A vertical tabs component.
 *
 * Displays tab labels vertically on the left side with the selected
 * tab's content rendered to the right. Supports:
 * - Multiple tabs with labels and content
 * - Active tab highlighting with color
 * - Customizable separator between label and content
 * - Keyboard navigation via selected tab index
 *
 * Mirrors the tab concept from bubble-tea but adapted to PHP with
 * vertical orientation and wither-style immutable setters.
 */
final class TabsVertical implements \SugarCraft\Dash\Foundation\Sizer
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
        private readonly int $labelWidth = 12,
    ) {}

    /**
     * Create a new vertical tabs component with default styling.
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
            labelWidth: 12,
        );
    }

    /**
     * Set the allocated dimensions for these vertical tabs.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the vertical tabs component.
     */
    public function render(): string
    {
        if (empty($this->tabs)) {
            return '';
        }

        $safeIndex = max(0, min($this->selectedIndex, count($this->tabs) - 1));
        $labelColumn = $this->renderLabels($safeIndex);
        $content = $this->renderContent($safeIndex);

        // Splice label column and content row-by-row so the active content
        // appears next to the active label rather than below the whole label
        // stack.
        $labelLines = explode("\n", $labelColumn);
        $contentLines = explode("\n", $content);
        $maxRows = max(count($labelLines), count($contentLines));
        $blankLabel = str_repeat(' ', $this->labelWidth);
        $rows = [];
        for ($i = 0; $i < $maxRows; $i++) {
            $left = $labelLines[$i] ?? $blankLabel;
            $right = $contentLines[$i] ?? '';
            $rows[] = $left . $this->separator . $right;
        }
        $result = implode("\n", $rows);

        // Ensure we end with reset if any colors were used
        if ($this->activeColor !== null || $this->inactiveColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render the label column (vertical).
     */
    private function renderLabels(int $selectedIndex): string
    {
        $lines = [];

        foreach ($this->tabs as $i => $tab) {
            $label = $tab['label'];
            $isActive = ($i === $selectedIndex);

            $labelStr = $this->renderLabel($label, $isActive);
            $lines[] = $labelStr;
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single tab label.
     */
    private function renderLabel(string $label, bool $isActive): string
    {
        $color = $isActive ? $this->activeColor : $this->inactiveColor;
        $prefix = $isActive ? '>' : ' ';
        $suffix = $isActive ? '<' : ' ';

        // Truncate or pad label to fit labelWidth
        $labelLen = Width::string($label);
        if ($labelLen > $this->labelWidth - 2) {
            $label = substr($label, 0, $this->labelWidth - 4) . '..';
        }

        $paddedLabel = str_pad($label, $this->labelWidth - 2, ' ', STR_PAD_RIGHT);

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor)
                . $prefix . $paddedLabel . $suffix
                . Ansi::reset();
        }

        return $prefix . $paddedLabel . $suffix;
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
        $content = $tab['content'];

        if ($content instanceof \SugarCraft\Dash\Foundation\Sizer&& $this->width !== null && $this->height !== null) {
            // Account for separator and label column width
            $contentWidth = max(0, $this->width - $this->labelWidth - Width::string($this->separator));
            $sized = $content->setSize($contentWidth, $this->height);
            return ' ' . $sized->render();
        }

        return ' ' . $content->render();
    }

    /**
     * Calculate the natural dimensions of these vertical tabs.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Calculate width from label width + separator + content estimate
        $labelColumnWidth = 2 + $this->labelWidth + 2; // prefix + label + suffix
        $separatorWidth = Width::string($this->separator);
        $contentWidth = 20; // Default content width estimate

        $width = $labelColumnWidth + $separatorWidth + $contentWidth;

        // Calculate height from number of tabs (labels are vertical)
        $height = max(1, count($this->tabs));

        // Check if selected tab content is a sizer
        $safeIndex = max(0, min($this->selectedIndex, count($this->tabs) - 1));
        if (isset($this->tabs[$safeIndex])) {
            $tab = $this->tabs[$safeIndex];
            $content = $tab['content'];
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $contentHeight] = $content->getInnerSize();
                $height = max($height, $contentHeight);
            }
        }

        return [$width, $height];
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
            labelWidth: $this->labelWidth,
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
            labelWidth: $this->labelWidth,
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
            labelWidth: $this->labelWidth,
        );
    }

    /**
     * Set the separator character between labels and content.
     */
    public function withSeparator(string $separator): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $separator,
            labelWidth: $this->labelWidth,
        );
    }

    /**
     * Set the width reserved for tab labels.
     */
    public function withLabelWidth(int $width): self
    {
        return new self(
            tabs: $this->tabs,
            selectedIndex: $this->selectedIndex,
            activeColor: $this->activeColor,
            inactiveColor: $this->inactiveColor,
            separator: $this->separator,
            labelWidth: max(4, $width),
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
            labelWidth: $this->labelWidth,
        );
    }
}
