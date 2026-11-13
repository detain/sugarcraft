<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Drawable;
use SugarCraft\Dashboard\Foundation\Theme;

/**
 * A horizontal stack layout component.
 *
 * Arranges items horizontally in a row, with optional
 * spacing between items. All items share the same vertical space.
 *
 * Mirrors HStack layout concepts adapted to PHP with wither-style immutable setters.
 */
final class HStack implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<\SugarCraft\Dashboard\Foundation\Item> $items
     */
    public function __construct(
        private readonly array $items = [],
        private readonly int $spacing = 0,
        private readonly HAlign $alignment = HAlign::Left,
    ) {}

    /**
     * Create a new horizontal stack with default styling.
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
            alignment: HAlign::Left,
        );
    }

    /**
     * Create a horizontal stack with spacing between items.
     */
    public static function spaced(int $spacing, \SugarCraft\Dashboard\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: max(0, $spacing),
            alignment: HAlign::Left,
        );
    }

    /**
     * Create a horizontally centered stack.
     */
    public static function centered(\SugarCraft\Dashboard\Foundation\Item ...$items): self
    {
        return new self(
            items: $items,
            spacing: 0,
            alignment: HAlign::Center,
        );
    }

    /**
     * Set the allocated dimensions for this stack.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the stack as a single line string.
     */
    public function render(): string
    {
        if ($this->items === []) {
            return '';
        }

        $useWidth = $this->getWidth();
        $useHeight = $this->getHeight();

        // Render each child into its own column of lines (finding
        // sugar-dashboard-020: string-concatenating multi-line blobs made a
        // horizontal stack render its children vertically and the seam
        // corrupted content; the row-wise stitch in Layout.php is the
        // exemplar followed here). Children are allocated their natural
        // width — the old setSize(0, h) collapsed allocation-reporting
        // children to zero.
        $columns = [];
        $columnWidths = [];
        $maxHeight = 0;

        foreach ($this->items as $item) {
            $sizedItem = $item;
            if ($sizedItem instanceof \SugarCraft\Dashboard\Foundation\SizedItem) {
                [$naturalWidth, $naturalHeight] = $sizedItem->innerSize();
                $sizedItem = $sizedItem->setSize($naturalWidth, $useHeight > 0 ? $useHeight : $naturalHeight);
            }
            $lines = explode("\n", $sizedItem->render());

            $widest = 0;
            foreach ($lines as $line) {
                $widest = max($widest, Width::string($line));
            }

            $columns[] = $lines;
            $columnWidths[] = $widest;
            $maxHeight = max($maxHeight, count($lines));
        }

        // Calculate total width
        $totalWidth = array_sum($columnWidths);
        $gap = $this->spacing > 0 && count($this->items) > 1 ? $this->spacing : 0;
        if ($gap > 0) {
            $totalWidth += $gap * (count($this->items) - 1);
        }

        // Horizontal alignment pads EVERY row of the composed block (the old
        // code padded only in front of the first line of the concatenated blob).
        $prePad = '';
        if ($useWidth > $totalWidth) {
            $pad = $this->alignment === HAlign::Right
                ? $useWidth - $totalWidth
                : ($this->alignment === HAlign::Center ? intdiv($useWidth - $totalWidth, 2) : 0);
            $prePad = str_repeat(' ', $pad);
        }

        $rowCount = count($columns);
        $result = [];
        for ($y = 0; $y < $maxHeight; $y++) {
            $row = $prePad;
            foreach ($columns as $i => $lines) {
                $line = $lines[$y] ?? '';
                if ($i > 0 && $gap > 0 && !($i === $rowCount - 1 && $line === '')) {
                    // No phantom seam after the last column when that row
                    // simply does not exist for the final child.
                    $row .= str_repeat(' ', $gap);
                }
                // Non-final columns are padded to their slot so following
                // columns stay in their lane; the final column keeps its
                // exact bytes (no trailing-space drift).
                if ($i < $rowCount - 1) {
                    $deficit = $columnWidths[$i] - Width::string($line);
                    if ($deficit > 0) {
                        $line .= str_repeat(' ', $deficit);
                    }
                }
                $row .= $line;
            }
            $result[] = $row;
        }

        return implode("\n", $result);
    }

    /**
     * Get the width to use for this stack.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Get the height to use for this stack.
     */
    private function getHeight(): int
    {
        return $this->height ?? 0;
    }

    /**
     * Calculate the natural dimensions of this stack.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        if ($this->items === []) {
            return [0, 0];
        }

        $totalWidth = 0;
        $maxHeight = 0;

        foreach ($this->items as $index => $item) {
            if ($item instanceof \SugarCraft\Dashboard\Foundation\SizedItem) {
                [$w, $h] = $item->innerSize();
                $totalWidth += $w;
                $maxHeight = max($maxHeight, $h);
            } else {
                $rendered = $item->render();
                $lines = explode("\n", $rendered);
                $maxLineWidth = 0;
                foreach ($lines as $line) {
                    $maxLineWidth = max($maxLineWidth, Width::string($line));
                }
                $totalWidth += $maxLineWidth;
                $maxHeight = max($maxHeight, count($lines));
            }

            // Add spacing after each item except the last
            if ($index < count($this->items) - 1) {
                $totalWidth += $this->spacing;
            }
        }

        return [$totalWidth, $maxHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the items in this stack.
     *
     * @param list<\SugarCraft\Dashboard\Foundation\Item> $items
     */
    public function withItems(array $items): self
    {
        return new self(
            items: $items,
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Add an item to the end of the stack.
     */
    public function withAppended(\SugarCraft\Dashboard\Foundation\Item $item): self
    {
        return new self(
            items: [...$this->items, $item],
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Add an item to the beginning of the stack.
     */
    public function withPrepended(\SugarCraft\Dashboard\Foundation\Item $item): self
    {
        return new self(
            items: [$item, ...$this->items],
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }

    /**
     * Set the spacing between items.
     */
    public function withSpacing(int $spacing): self
    {
        return new self(
            items: $this->items,
            spacing: max(0, $spacing),
            alignment: $this->alignment,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withAlignment(HAlign $alignment): self
    {
        return new self(
            items: $this->items,
            spacing: $this->spacing,
            alignment: $alignment,
        );
    }

    /**
     * Apply a theme, fanning it down to any theme-aware children.
     */
    public function withTheme(Theme $theme): self
    {
        $themedItems = [];
        foreach ($this->items as $item) {
            if ($item instanceof Drawable) {
                $themedItems[] = $item->withTheme($theme);
            } else {
                $themedItems[] = $item;
            }
        }

        return new self(
            items: $themedItems,
            spacing: $this->spacing,
            alignment: $this->alignment,
        );
    }
}
