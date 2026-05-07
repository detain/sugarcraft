<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Flex;

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
    /** @var list<FlexItem> */
    private array $items = [];

    public Direction $direction = Direction::Row;
    public Justify   $justify   = Justify::Start;
    public Align     $align     = Align::Stretch;
    public int       $gap       = 0;
    public bool      $wrap      = false;
    public bool      $border    = false;

    private function __construct(Direction $direction, FlexItem ...$items)
    {
        $this->direction = $direction;
        $this->items     = $items;
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function row(FlexItem ...$items): self
    {
        return (new self(Direction::Row, ...$items));
    }

    public static function column(FlexItem ...$items): self
    {
        return (new self(Direction::Column, ...$items));
    }

    // -------------------------------------------------------------------------
    // Fluent setters
    // -------------------------------------------------------------------------

    public function withDirection(Direction $d): self
    {
        $clone = clone $this;
        $clone->direction = $d;
        return $clone;
    }

    public function withJustify(Justify $j): self
    {
        $clone = clone $this;
        $clone->justify = $j;
        return $clone;
    }

    public function withAlign(Align $a): self
    {
        $clone = clone $this;
        $clone->align = $a;
        return $clone;
    }

    public function withGap(int $cells): self
    {
        $clone = clone $this;
        $clone->gap = $cells;
        return $clone;
    }

    public function withWrap(bool $w = true): self
    {
        $clone = clone $this;
        $clone->wrap = $w;
        return $clone;
    }

    public function withBorder(bool $b = true): self
    {
        $clone = clone $this;
        $clone->border = $b;
        return $clone;
    }

    public function addItem(FlexItem $item): self
    {
        $clone = clone $this;
        $clone->items[] = $item;
        return $clone;
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
            $resultLines[] = \substr($lineStr, 0, $totalWidth);
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
        $totalBasis   = \array_sum(\array_filter(\array_column($measured, 'item'), fn($it) => $it->basis > 0));
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
        $widths = \array_map('strlen', $lines);
        return $widths === [] ? 0 : \max($widths);
    }

    private function measureHeight(FlexItem $item): int
    {
        return \count(\explode("\n", $item->content));
    }

    private function alignCell(string $text, int $width, Align $align): string
    {
        $len = \strlen($text);
        if ($len >= $width) {
            return \substr($text, 0, $width);
        }
        $pad = $width - $len;
        return match ($align) {
            Align::Start    => $text . \str_repeat(' ', $pad),
            Align::End      => \str_repeat(' ', $pad) . $text,
            Align::Center   => \str_repeat(' ', (int) \floor($pad / 2)) . $text . \str_repeat(' ', (int) \ceil($pad / 2)),
            Align::Stretch  => $text . \str_repeat(' ', $pad),
        };
    }

    private function applyStyle(string $s, string $style): string
    {
        if ($style === '') return $s;
        return "\x1b[{$style}m{$s}\x1b[0m";
    }
}
