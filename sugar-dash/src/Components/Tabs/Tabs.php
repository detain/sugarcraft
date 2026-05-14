<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tabs;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

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
final class Tabs implements \SugarCraft\Dash\Foundation\Sizer
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
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
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
     */
    private function renderTabBar(int $selectedIndex): string
    {
        $parts = [];
        $totalWidth = $this->width ?? 0;

        foreach ($this->tabs as $i => $tab) {
            $label = $tab['label'];
            $isActive = ($i === $selectedIndex);

            $tabStr = $this->renderTabLabel($label, $isActive);
            $parts[] = $tabStr;

            // Add separator if not last tab
            if ($i < count($this->tabs) - 1) {
                $sepColor = '';
                if ($this->inactiveColor !== null) {
                    $sepColor = $this->inactiveColor->toFg(ColorProfile::TrueColor);
                }
                $parts[] = $sepColor . $this->separator . Ansi::reset();
            }
        }

        $result = implode('', $parts);

        // If we have a fixed width and it's wider, pad with spaces
        if ($totalWidth > 0) {
            $resultWidth = Width::string($result);
            if ($resultWidth < $totalWidth) {
                $result .= str_repeat(' ', $totalWidth - $resultWidth);
            }
        }

        // Add active tab underline
        if ($this->activeChar !== '' && isset($this->tabs[$selectedIndex])) {
            $activeLabel = $this->tabs[$selectedIndex]['label'];
            // Find position of active tab in result to place underline
            // For simplicity, add underline on its own line at the end of tab bar
            $result .= "\n";
            $labelWidth = Width::string($activeLabel) + 3; // +3 for prefix/suffix
            $prefix = ' ';
            if ($this->activeColor !== null) {
                $result .= $this->activeColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $prefix . str_repeat($this->activeChar, $labelWidth);
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
            return $color->toFg(ColorProfile::TrueColor)
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
        $content = $tab['content'];

        $tabBarHeight = $this->activeChar !== '' ? 2 : 1; // Same calculation as getInnerSize()
        $contentHeight = $this->height !== null ? $this->height - $tabBarHeight : 0;

        if ($content instanceof \SugarCraft\Dash\Foundation\Sizer&& $this->width !== null && $contentHeight > 0) {
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
    public function getInnerSize(): array
    {
        // Calculate width from tab labels
        $width = 0;
        foreach ($this->tabs as $tab) {
            $labelWidth = Width::string($tab['label']);
            // Account for brackets: [label] or ' label '
            $width += $labelWidth + 3; // 3 for prefix/suffix/space
            // Plus separator
            if ($width > 0) {
                $width += 1;
            }
        }

        // Calculate height from selected tab content
        $safeIndex = max(0, min($this->selectedIndex, count($this->tabs) - 1));
        $tabBarHeight = $this->activeChar !== '' ? 2 : 1; // Tab bar + optional underline
        $contentHeight = $tabBarHeight;
        if (isset($this->tabs[$safeIndex])) {
            $tab = $this->tabs[$safeIndex];
            $content = $tab['content'];
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $h] = $content->getInnerSize();
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
