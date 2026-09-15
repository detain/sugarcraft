<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Flex;

use SugarCraft\Core\Util\Ansi;

/** {@see FlexBox} main-axis direction — equivalent to CSS `flex-direction`. */
enum Direction {
    case Row;     // horizontal
    case Column;  // vertical
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
 * Supports row/column direction, align, gap, and ratio-based sizing.
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
        public readonly Align $align = Align::Stretch,
        public readonly int $gap = 0,
        private array $items = [],
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function row(FlexItem ...$items): self
    {
        return new self(Direction::Row, Align::Stretch, 0, $items);
    }

    public static function column(FlexItem ...$items): self
    {
        return new self(Direction::Column, Align::Stretch, 0, $items);
    }

    // -------------------------------------------------------------------------
    // Fluent setters
    // -------------------------------------------------------------------------

    public function withDirection(Direction $d): self
    {
        return new self($d, $this->align, $this->gap, $this->items);
    }

    public function withAlign(Align $a): self
    {
        return new self($this->direction, $a, $this->gap, $this->items);
    }

    public function withGap(int $cells): self
    {
        return new self($this->direction, $this->align, $cells, $this->items);
    }

    public function addItem(FlexItem $item): self
    {
        $newItems = $this->items;
        $newItems[] = $item;
        return new self($this->direction, $this->align, $this->gap, $newItems);
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
     * Neutralize data-origin content before it reaches the terminal.
     *
     * Delegates to the canonical {@see \SugarCraft\Core\Util\Sanitize::untrusted()}
     * — `Ansi::strip()` over the whole ECMA-48 family in BOTH 7-bit and 8-bit
     * form (CSI/OSC/SGR + DCS/SOS/PM/APC payloads + lone C1 bytes, fail-closed
     * on an unterminated sequence) followed by a C0-minus-{\t,\n,\r} + DEL sweep,
     * valid UTF-8 (e.g. CJK `東京`) preserved. `renderRow()`/`renderColumn()` run
     * this on each item line BEFORE `alignCell()` (ANSI-aware truncate/pad) and
     * BEFORE `applyStyle()` wraps the result in `CSI <style> m … reset`, so the
     * box's own colour is applied downstream of the strip and survives it —
     * exactly as {@see \SugarCraft\Stickers\Table\Column::sanitize()} relies on.
     * The pre-hardening hand-rolled regexes this replaces were \x1b-only: blind
     * to 8-bit C1 (`\x9b` CSI → cursor-move / sixel / title-set without ever
     * using \x1b) and they leaked unterminated DCS/APC payload text.
     * See docs/research/ansi-tmux-ansicode-audit.md #9.
     */
    private function sanitize(string $s): string
    {
        return \SugarCraft\Core\Util\Sanitize::untrusted($s);
    }
}
