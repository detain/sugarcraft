<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Flex;

use SugarCraft\Core\Util\Ansi;

/** {@see FlexBox} main-axis direction — equivalent to CSS `flex-direction`. */
enum Direction {
    case Row;     // horizontal
    case Column;  // vertical
}

/** {@see FlexBox} main-axis distribution of free space — equivalent to CSS `justify-content`. */
enum Justify {
    case Start;
    case Center;
    case End;
    case SpaceBetween;
    case SpaceAround;
}

/** {@see FlexBox} cross-axis item alignment — equivalent to CSS `align-items`. */
enum Align {
    case Start;
    case Center;
    case End;
    case Stretch;
}

/**
 * CSS flexbox-like layout for terminal UIs.
 *
 * Supports row/column direction, justify/align, gap, wrapping, and ratio-based sizing.
 *
 * Port of 76creates/stickers FlexBox.
 *
 * @see https://github.com/76creates/stickers
 */
final class FlexBox
{
    /**
     * @param list<FlexItem> $items
     */
    private function __construct(
        public readonly Direction $direction = Direction::Row,
        public readonly Justify $justify = Justify::Start,
        public readonly Align $align = Align::Stretch,
        public readonly int $gap = 0,
        public readonly bool $wrap = false,
        public readonly bool $border = false,
        private array $items = [],
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function row(FlexItem ...$items): self
    {
        return new self(Direction::Row, Justify::Start, Align::Stretch, 0, false, false, $items);
    }

    public static function column(FlexItem ...$items): self
    {
        return new self(Direction::Column, Justify::Start, Align::Stretch, 0, false, false, $items);
    }

    // -------------------------------------------------------------------------
    // Fluent setters
    // -------------------------------------------------------------------------

    public function withDirection(Direction $d): self
    {
        return new self($d, $this->justify, $this->align, $this->gap, $this->wrap, $this->border, $this->items);
    }

    public function withJustify(Justify $j): self
    {
        return new self($this->direction, $j, $this->align, $this->gap, $this->wrap, $this->border, $this->items);
    }

    public function withAlign(Align $a): self
    {
        return new self($this->direction, $this->justify, $a, $this->gap, $this->wrap, $this->border, $this->items);
    }

    public function withGap(int $cells): self
    {
        return new self($this->direction, $this->justify, $this->align, $cells, $this->wrap, $this->border, $this->items);
    }

    public function withWrap(bool $w = true): self
    {
        return new self($this->direction, $this->justify, $this->align, $this->gap, $w, $this->border, $this->items);
    }

    public function withBorder(bool $b = true): self
    {
        return new self($this->direction, $this->justify, $this->align, $this->gap, $this->wrap, $b, $this->items);
    }

    public function addItem(FlexItem $item): self
    {
        $newItems = $this->items;
        $newItems[] = $item;
        return new self($this->direction, $this->justify, $this->align, $this->gap, $this->wrap, $this->border, $newItems);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the FlexBox into a string within the given viewport.
     *
     * @param int $totalWidth  Available width in cells
     * @param int $totalHeight Available height in cells
     * @return string
     */
    public function render(int $totalWidth, int $totalHeight): string
    {
        if ($this->items === []) {
            return '';
        }

        if ($this->direction === Direction::Row) {
            return $this->renderRow($totalWidth, $totalHeight);
        }
        return $this->renderColumn($totalWidth, $totalHeight);
    }

    private function renderRow(int $totalWidth, int $totalHeight): string
    {
        $items = $this->items;
        $gap   = $this->gap;

        // Measure each item — pull ratio/basis off the FlexItem so the
        // array_column lookups below actually find them.
        $measured = \array_map(fn(FlexItem $item): array => [
            'item'   => $item,
            'width'  => $this->measureWidth($item),
            'height' => $this->measureHeight($item),
            'ratio'  => $item->ratio,
            'basis'  => $item->basis,
        ], $items);

        $totalRatio    = \array_sum(\array_column($measured, 'ratio'));
        $itemsWithBasis = \array_filter($measured, fn($m) => $m['item']->basis > 0);
        $totalBasis    = \array_sum(\array_column($itemsWithBasis, 'basis'));
        $freeSpace     = $totalWidth - $totalBasis - ($gap * (\count($items) - 1));

        if ($totalRatio > 0 && $freeSpace > 0) {
            foreach ($measured as $i => $m) {
                $measured[$i]['allocated'] = $m['item']->basis > 0
                    ? $m['item']->basis
                    : (int) \round($freeSpace * $m['item']->ratio / $totalRatio);
            }
        } else {
            foreach ($measured as $i => $m) {
                $measured[$i]['allocated'] = $m['item']->basis > 0 ? $m['item']->basis : 1;
            }
        }

        $totalAllocated = \array_sum(\array_column($measured, 'allocated'));
        $excess = $totalWidth - $totalAllocated - ($gap * (\count($items) - 1));
        if ($excess > 0 && $totalRatio > 0) {
            // Distribute excess to items with ratio
            foreach ($measured as $i => $m) {
                if ($m['item']->ratio > 0) {
                    $extra = (int) \round($excess * $m['item']->ratio / $totalRatio);
                    $measured[$i]['allocated'] += $extra;
                }
            }
        }

        // Compute start X for each item
        $offsets = [0];
        for ($i = 0; $i < \count($measured) - 1; $i++) {
            $offsets[] = $offsets[$i] + $measured[$i]['allocated'] + $gap;
        }

        $resultLines = [];
        $heights = \array_column($measured, 'height');
        $maxHeight = $this->align === Align::Stretch
            ? $totalHeight
            : ($heights === [] ? 0 : \max($heights));

        for ($line = 0; $line < $maxHeight; $line++) {
            $lineStr = '';
            for ($i = 0; $i < \count($measured); $i++) {
                $m  = $measured[$i];
                $aw = $m['allocated'];
                $itemContent = $m['item']->content;
                $itemLines = \explode("\n", $itemContent);
                while (\count($itemLines) < $maxHeight) {
                    $itemLines[] = '';
                }
                $raw = $itemLines[$line] ?? '';

                // Sanitize data-origin content before rendering.
                $raw = $this->sanitize($raw);

                // Align within allocated width
                $cellStr = $this->alignCell($raw, $aw, $this->align);

                if ($m['item']->style !== '') {
                    $cellStr = $this->applyStyle($cellStr, $m['item']->style);
                }

                $lineStr .= $cellStr;
                if ($i < \count($measured) - 1) {
                    $lineStr .= \str_repeat(' ', $gap);
                }
            }
            $resultLines[] = \SugarCraft\Core\Util\Width::truncateAnsi($lineStr, $totalWidth);
        }

        return \implode("\n", $resultLines);
    }

    private function renderColumn(int $totalWidth, int $totalHeight): string
    {
        $items = $this->items;
        $gap   = $this->gap;

        $measured = \array_map(fn(FlexItem $item): array => [
            'item'   => $item,
            'width'  => $this->measureWidth($item),
            'height' => $this->measureHeight($item),
            'ratio'  => $item->ratio,
            'basis'  => $item->basis,
        ], $items);

        $totalRatio   = \array_sum(\array_column($measured, 'ratio'));
        $itemsWithBasis = \array_filter($measured, fn($m) => $m['item']->basis > 0);
        $totalBasis   = \array_sum(\array_column($itemsWithBasis, 'basis'));
        $freeHeight   = $totalHeight - $totalBasis - ($gap * (\count($items) - 1));

        foreach ($measured as $i => $m) {
            $measured[$i]['allocated'] = $m['item']->basis > 0
                ? $m['item']->basis
                : ($totalRatio > 0 ? (int) \round($freeHeight * $m['item']->ratio / $totalRatio) : 1);
        }

        $offsets = [0];
        for ($i = 0; $i < \count($measured) - 1; $i++) {
            $offsets[] = $offsets[$i] + $measured[$i]['allocated'] + $gap;
        }

        $resultLines = [];
        for ($i = 0; $i < \count($measured); $i++) {
            $m    = $measured[$i];
            $itemLines = \explode("\n", $m['item']->content);
            $maxW = $this->align === Align::Stretch ? $totalWidth : $m['width'];

            foreach ($itemLines as $line) {
                $line = $this->sanitize($line);
                $lineStr = $this->alignCell($line, $maxW, $this->align);
                if ($m['item']->style !== '') {
                    $lineStr = $this->applyStyle($lineStr, $m['item']->style);
                }
                $resultLines[] = \str_pad($lineStr, $totalWidth);
            }

            // Pad to allocated height
            $extraLines = $m['allocated'] - \count($itemLines);
            for ($j = 0; $j < $extraLines; $j++) {
                $resultLines[] = \str_repeat(' ', $totalWidth);
            }

            // Gap
            if ($gap > 0) {
                for ($j = 0; $j < $gap; $j++) {
                    $resultLines[] = \str_repeat(' ', $totalWidth);
                }
            }
        }

        return \implode("\n", \array_slice($resultLines, 0, $totalHeight));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function measureWidth(FlexItem $item): int
    {
        $lines = \explode("\n", $item->content);
        $widths = \array_map(\SugarCraft\Core\Util\Width::string(...), $lines);
        return $widths === [] ? 0 : \max($widths);
    }

    private function measureHeight(FlexItem $item): int
    {
        return \count(\explode("\n", $item->content));
    }

    private function alignCell(string $text, int $width, Align $align): string
    {
        // Truncate to display width if needed (ANSI-aware).
        $truncated = \SugarCraft\Core\Util\Width::truncateAnsi($text, $width);
        $visualWidth = \SugarCraft\Core\Util\Width::string($truncated);
        if ($visualWidth >= $width) {
            return $truncated;
        }
        $pad = $width - $visualWidth;
        return match ($align) {
            Align::Start    => \SugarCraft\Core\Util\Width::padRight($truncated, $width),
            Align::End      => \SugarCraft\Core\Util\Width::padLeft($truncated, $width),
            Align::Center   => \SugarCraft\Core\Util\Width::padCenter($truncated, $width),
            Align::Stretch  => \SugarCraft\Core\Util\Width::padRight($truncated, $width),
        };
    }

    private function applyStyle(string $s, string $style): string
    {
        if ($style === '') return $s;
        return Ansi::CSI . $style . 'm' . $s . Ansi::reset();
    }

    /**
     * Strip dangerous control characters from content destined for the terminal.
     *
     * Removes C0 controls (0x00-0x08, 0x0B-0x1F), C1 escape (0x7F),
     * and OSC/DCS sequences that could corrupt terminal state. Library-emitted
     * SGR sequences are preserved as they are added downstream via applyStyle.
     */
    private function sanitize(string $s): string
    {
        // Remove OSC sequences (ESC ] ... BEL or ESC \).
        $s = \preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $s);
        // Remove DCS sequences (ESC P ... ESC \).
        $s = \preg_replace('/\x1bP[^\x1b]*(?:\x1b\\\\)/', '', $s);
        // Remove bare ESC introducers not followed by [ (not CSI).
        $s = \preg_replace('/\x1b(?!\[)/', '', $s);
        // Remove C0 controls except HT (0x09) and LF (0x0A).
        $s = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        // Remove C1 controls (0x7F, 0x80-0x9F).
        $s = \preg_replace('/[\x7F\x80-\x9F]/', '', $s);
        return $s;
    }
}
