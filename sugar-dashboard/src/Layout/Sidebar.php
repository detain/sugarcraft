<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Output\Truncate;
use SugarCraft\Dashboard\Foundation\ColorMode;

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
final class Sidebar implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{label: string, icon?: string, isActive?: bool, isDivider?: bool}> $items
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
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
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

        // No pre-border color preamble any more (D-136): the old $result
        // block collected bg/border SGR codes that never reached the output
        // (the return is built purely from $lines through the border pass),
        // so it was dead weight that READ like live styling.
        $lines = [];

        // Border budget is known BEFORE content (D-165): outer useWidth =
        // border + interior(useWidth - 2) + border. Interior rows carry a
        // one-cell marker column, so item text gets useWidth - 3 and the
        // title's flanking spaces get the rest. Nothing is clipped for
        // the frame afterwards — no content cell is ever consumed.
        $inner = max(1, $useWidth - 2);

        // Title section
        if ($this->title !== null) {
            $titleText = Truncate::truncate($this->title, max(0, $inner - 2), '');
            $lines[] = ' ' . $titleText . str_repeat(' ', max(0, $inner - 1 - Width::string($titleText)));
            $lines[] = str_repeat('─', $inner);
        }

        // Items
        foreach ($this->items as $item) {
            if (isset($item['isDivider']) && $item['isDivider']) {
                // Divider line
                $lines[] = str_repeat('─', $inner);
            } else {
                $label = $item['label'];
                $icon = $item['icon'] ?? '';
                $isActive = $item['isActive'] ?? false;

                $text = $this->collapsed && $icon !== ''
                    ? $icon
                    : ($icon !== '' ? $icon . ' ' . $label : $label);

                $text = Truncate::truncate($text, max(0, $inner - 2), '');
                $marker = $isActive ? '>' : ' ';

                if ($isActive && $this->activeColor !== null) {
                    $lines[] = $marker . ' ' . $this->activeColor->toFg(ColorMode::profile()) . $text . Ansi::reset();
                } elseif (!$isActive && $this->inactiveColor !== null) {
                    $lines[] = $marker . ' ' . $this->inactiveColor->toFg(ColorMode::profile()) . $text . Ansi::reset();
                } else {
                    $lines[] = $marker . ' ' . $text;
                }
            }
        }

        // Pad to allocated height; the border pass does the cell filling.
        $lineCount = count($lines);
        while ($lineCount < $useHeight) {
            $lines[] = '';
            $lineCount++;
        }

        // Apply background to all lines if set
        if ($this->bgColor !== null) {
            $bgPrefix = $this->bgColor->toBg(ColorMode::profile());
            $bgReset = Ansi::reset();
            $lines = array_map(function ($line) use ($bgPrefix, $bgReset) {
                return $bgPrefix . $line . $bgReset;
            }, $lines);
        }

        // Wrap by CELLS (D-165). The border pass owns one column at each
        // edge (as shipped and as the examples' goldens show); the
        // interior gets exactly useWidth - 2 cells. The clip goes through
        // the canonical ANSI-aware truncation, never mb_substr: styled
        // lines END in SGR reset sequences, and the old byte cut landed
        // inside them, leaving a malformed `ESC[0` (no terminator) that
        // corrupted the border and everything after it — and on clean
        // lines it still consumed one content cell per row.
        $borderChar = $this->borderColor !== null ? '│' : '|';
        $borderPrefix = $this->borderColor !== null ? $this->borderColor->toFg(ColorMode::profile()) : '';
        $borderSuffix = $this->borderColor !== null ? Ansi::reset() : '';
        $output = [];
        foreach ($lines as $line) {
            $clipped = Width::padRight(Width::truncateAnsi($line, $inner), $inner);
            $output[] = $borderPrefix . $borderChar . $clipped . $borderChar . $borderSuffix;
        }

        return implode("\n", $output);
    }

    /**
     * Calculate the natural width based on item labels.
     *
     * The per-item text selection MIRRORS render() (D-136): a collapsed
     * sidebar carries icons in the list, so its natural width budgets the
     * icon column — not the full label that collapsed mode never paints.
     * The +4 is the marker column, the gutter space, and the two border
     * cells; the floor of 10 is the shipped minimum width.
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
            $text = $this->collapsed && $icon !== ''
                ? $icon
                : ($icon !== '' ? $icon . ' ' . $label : $label);
            $width = max($width, Width::string($text) + 4);
        }

        return $width;
    }

    /**
     * Calculate the natural dimensions of this sidebar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
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
     * @param list<array{label: string, icon?: string, isActive?: bool, isDivider?: bool}> $items
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
