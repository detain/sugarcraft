<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

use SugarCraft\Core\Util\Ansi;

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

        $measured = $this->allocateMainAxis($measured, $totalWidth, $gap);

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

        $measured = $this->allocateMainAxis($measured, $totalHeight, $gap);

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
                // Cell-based padding: a byte-based str_pad under-padded lines
                // carrying wide (CJK/emoji) glyphs (docs-fix-1023).
                $resultLines[] = \SugarCraft\Core\Util\Width::padRight($lineStr, $totalWidth);
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

    /**
     * Main-axis allocation shared by row and column modes — sum contract
     * (docs-fix-1023).
     *
     * Items with a basis take exactly their basis and are excluded from the
     * flex pool. The remaining flex items (basis == 0, ratio > 0) split the
     * usable free space by round-robin remainder carry (equivalent sum
     * contract) of their ratios: a floor base,
     * then one extra cell each in item order until the leftover runs out.
     * The floor remainder is below one cell per flex item by construction,
     * so a single in-order pass always closes the budget: allocated widths
     * sum to EXACTLY `mainSize - gap*(n-1) - fixedSum`. The previous
     * round()-then-excess-pass shape lost a whole cell in the common
     * even-thirds case (3 items with ratio 1 at width 10 rendered 9).
     *
     * When there is no flex item, or no free space to share, every
     * basis-less item falls back to one cell and the renderer's final
     * truncate/clamp contains the overflow — unchanged legacy behavior.
     *
     * @param array<int, array{item: FlexItem, width: int, height: int, ratio: int, basis: int}> $measured
     * @return array<int, array{item: FlexItem, width: int, height: int, ratio: int, basis: int, allocated: int}>
     */
    private function allocateMainAxis(array $measured, int $mainSize, int $gap): array
    {
        $usable = $mainSize - $gap * max(0, \count($measured) - 1);

        $flexIndices = [];
        $fixedSum    = 0;
        foreach ($measured as $i => $m) {
            if ($m['item']->basis > 0) {
                $measured[$i]['allocated'] = $m['item']->basis;
                $fixedSum                 += $m['item']->basis;
            } elseif ($m['item']->ratio > 0) {
                $flexIndices[] = $i;
            }
        }

        $flexFree = $usable - $fixedSum;
        if ($flexIndices === [] || $flexFree <= 0) {
            foreach ($measured as $i => $m) {
                if ($m['item']->basis <= 0) {
                    $measured[$i]['allocated'] = 1;
                }
            }
            return $measured;
        }

        $totalRatio = 0;
        foreach ($flexIndices as $i) {
            $totalRatio += $measured[$i]['item']->ratio;
        }

        $assigned = 0;
        foreach ($flexIndices as $i) {
            $share = (int) \floor($flexFree * $measured[$i]['item']->ratio / $totalRatio);
            $measured[$i]['allocated'] = $share;
            $assigned                 += $share;
        }

        $leftover = $flexFree - $assigned;
        $flexCount = \count($flexIndices);
        for ($k = 0; $k < $leftover; $k++) {
            $measured[$flexIndices[$k % $flexCount]]['allocated']++;
        }

        return $measured;
    }

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
     * exactly as {@see \SugarCraft\Flexbox\Table\Column::sanitize()} relies on.
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
