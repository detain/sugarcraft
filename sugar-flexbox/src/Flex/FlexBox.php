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
        if ($cells < 0) {
            throw new \InvalidArgumentException(
                'Gap must be >= 0 cells; got ' . $cells . '.',
            );
        }
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
        // Degenerate containers render empty in BOTH directions (one
        // contract, no row/column polarity split): a non-positive axis leaves
        // nothing to fit, and the row path's padding math on negative sizes
        // dropped rows from the FRONT of the column budget instead.
        if ($this->items === [] || $totalWidth <= 0 || $totalHeight <= 0) {
            return '';
        }

        // Exhaustive on Direction: a new case must be handled loudly here
        // (unlike an if/else, a missing match arm is a fatal, not a silent
        // fall-through to the column renderer).
        return match ($this->direction) {
            Direction::Row    => $this->renderRow($totalWidth, $totalHeight),
            Direction::Column => $this->renderColumn($totalWidth, $totalHeight),
        };
    }

    private function renderRow(int $totalWidth, int $totalHeight): string
    {
        $items = $this->items;
        $gap   = $this->effectiveGap($totalWidth);

        // Measure each item on the axes the renderer actually consumes.
        $measured = \array_map(fn(FlexItem $item): array => [
            'item'   => $item,
            'width'  => $this->measureWidth($item),
            'height' => $this->measureHeight($item),
        ], $items);

        $measured = $this->allocateMainAxis($measured, $totalWidth, $gap);

        $heights = \array_column($measured, 'height');
        // Fit contract: a row never emits more lines than totalHeight. The
        // tallest-item cross-axis size is a CEILING, not an exemption — a
        // 10-line item in a 3-row box renders 3 rows (clamped, never
        // overflowed; see Align's vertical-axis promise).
        $maxHeight = \min(
            $this->align === Align::Stretch
                ? $totalHeight
                : ($heights === [] ? 0 : \max($heights)),
            $totalHeight,
        );

        // Row assembly is quadratic in the OLD shape: every output line
        // re-exploded, re-extended and re-indexed every item's full
        // content, and repeated the gap str_repeat once per cell. Build
        // each item's finished column of cells exactly once (measure ->
        // sanitize -> align -> style), then join per line — byte-identical
        // output at O(lines + cells) instead of O(lines * cells) of work
        // (a 50-item row was measured over one 60fps frame budget on the
        // quadratic shape).
        $gapStr = $gap > 0 ? \str_repeat(' ', $gap) : '';

        $cells = [];
        foreach ($measured as $i => $m) {
            $aw        = $m['allocated'];
            $itemLines = \explode("\n", $m['item']->content);
            $style     = $m['item']->style;
            $column    = [];
            for ($line = 0; $line < $maxHeight; $line++) {
                // Sanitize data-origin content before rendering.
                $raw = $this->sanitize($itemLines[$line] ?? '');
                // Align within allocated width
                $cellStr = $this->alignCell($raw, $aw, $this->align);
                if ($style !== '') {
                    $cellStr = $this->applyStyle($cellStr, $style);
                }
                $column[] = $cellStr;
            }
            $cells[$i] = $column;
        }

        $resultLines = [];
        for ($line = 0; $line < $maxHeight; $line++) {
            $parts = [];
            foreach ($cells as $column) {
                $parts[] = $column[$line];
            }
            $lineStr     = $gapStr === '' ? \implode('', $parts) : \implode($gapStr, $parts);
            $resultLines[] = \SugarCraft\Core\Util\Width::truncateAnsi($lineStr, $totalWidth);
        }

        return \implode("\n", $resultLines);
    }

    private function renderColumn(int $totalWidth, int $totalHeight): string
    {
        $items = $this->items;
        $gap   = $this->effectiveGap($totalHeight);

        $measured = \array_map(fn(FlexItem $item): array => [
            'item'   => $item,
            'width'  => $this->measureWidth($item),
            'height' => $this->measureHeight($item),
        ], $items);

        $measured = $this->allocateMainAxis($measured, $totalHeight, $gap);

        $resultLines = [];
        for ($i = 0; $i < \count($measured); $i++) {
            $m    = $measured[$i];
            $itemLines = \explode("\n", $m['item']->content);
            // Fit contract: an item may never print more lines than the
            // height allocateMainAxis reserved for it. A taller multi-line
            // sibling used to spill past its slice and push the next item
            // out of the totalHeight window entirely, falsifying the
            // exact-sum contract (a column is a vertical box just as a row
            // is a horizontal one — Align's "clamped, never distributed"
            // promise holds on both axes).
            $itemLines = \array_slice($itemLines, 0, \max(0, $m['allocated']));
            // Fit contract: cap the cell at the container width. A measured
            // width wider than totalWidth (plain or CJK/emoji cells) must
            // truncate, not just pad — alignCell honours Width::truncateAnsi
            // up to this ceiling, so a 20-cell line in a 10-wide box prints
            // exactly 10, matching renderRow's final clamp.
            $maxW = $this->align === Align::Stretch
                ? $totalWidth
                : \min($m['width'], $totalWidth);

            foreach ($itemLines as $line) {
                $line = $this->sanitize($line);
                $lineStr = $this->alignCell($line, $maxW, $this->align);
                if ($m['item']->style !== '') {
                    $lineStr = $this->applyStyle($lineStr, $m['item']->style);
                }
                // Cell-based padding: a byte-based str_pad under-padded lines
                // carrying wide (CJK/emoji) glyphs.
                $resultLines[] = \SugarCraft\Core\Util\Width::padRight($lineStr, $totalWidth);
            }

            // Pad to allocated height
            $extraLines = $m['allocated'] - \count($itemLines);
            for ($j = 0; $j < $extraLines; $j++) {
                $resultLines[] = \str_repeat(' ', $totalWidth);
            }

            // Gap BETWEEN items only — same interstice contract renderRow
            // keeps (: i < count-1). A trailing gap after the last item
            // wasted budget rows the height clamp then cut from real
            // content.
            if ($gap > 0 && $i < \count($measured) - 1) {
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
     * The gap that is actually renderable: each interstice is capped so the
     * gaps plus a 1-cell floor for every item still fit the container,
     * bounding the str_repeat/pad work a huge positive gap could trigger.
     * (The setter refuses negatives; this only reins in absurd positives.)
     *
     * @param int $mainSize Container size along the active direction
     */
    private function effectiveGap(int $mainSize): int
    {
        $n = \count($this->items);
        if ($n < 2 || $this->gap === 0) {
            return 0;
        }
        return \min($this->gap, \max(0, \intdiv($mainSize - $n, $n - 1)));
    }

    /**
     * Main-axis allocation shared by row and column modes — sum contract.
     *
     * Items with a basis take exactly their basis — clamped to the
     * container's main-axis size, so a mis-typed huge basis cannot storm
     * memory at render time — and are excluded from the flex pool. Items
     * with neither basis nor ratio (CSS `flex-grow: 0` siblings) reserve a
     * 1-cell floor out of the budget and are excluded too. The remaining
     * flex items (basis == 0, ratio > 0) split the usable free space by
     * round-robin remainder carry (equivalent sum contract) of their
     * ratios: a floor() base — the rounded-down quotient, which is itself 0
     * when the container cannot pay every flex sibling a cell (3 ratio-1
     * items across 2 cells yield 1/1/0) — then one extra cell each in
     * item order until the leftover runs out. The floor remainder is below one cell per flex
     * item by construction, so a single in-order pass always closes the
     * budget: flex allocations sum to EXACTLY
     * `mainSize - gap*(n-1) - reserved`. The previous round()-then-
     * excess-pass shape lost a whole cell in the common even-thirds case
     * (3 items with ratio 1 at width 10 rendered 9).
     *
     * When there is no flex item, or no free space to share, every
     * basis-less item falls back to one cell and the renderer's final
     * truncate/clamp contains the overflow — unchanged legacy behavior.
     *
     * Whatever path an entry takes, it ALWAYS receives an `allocated` key:
     * the historical write-gap (a ratio<=0/basis<=0 item beside a flex
     * sibling matched no branch) made row render crash on the unset-key
     * null reaching alignCell's typed parameter under strict_types, while
     * column mode rendered the same input with the item's reserved rows
     * silently missing.
     *
     * @param array<int, array{item: FlexItem, width: int, height: int}> $measured
     * @return array<int, array{item: FlexItem, width: int, height: int, allocated: int}>
     */
    private function allocateMainAxis(array $measured, int $mainSize, int $gap): array
    {
        $usable = $mainSize - $gap * max(0, \count($measured) - 1);

        $flexIndices = [];
        $fixedSum    = 0;
        foreach ($measured as $i => $m) {
            if ($m['item']->basis > 0) {
                $space                       = \min($m['item']->basis, \max(0, $mainSize));
                $measured[$i]['allocated']   = $space;
                $fixedSum                   += $space;
            } elseif ($m['item']->ratio > 0) {
                $flexIndices[] = $i;
            } else {
                $measured[$i]['allocated'] = 1;
                $fixedSum++;
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
     */
    private function sanitize(string $s): string
    {
        // Control bytes must not survive into a cell: an embedded newline
        // or tab forges extra physical lines/columns on the terminal that
        // the cell grid never sees, desynchronising full-frame from delta
        // paint for the rest of the session. Line breaking is the
        // container's job; a cell contributes one visual line only.
        return \str_replace(
            ["\r\n", "\n", "\t", "\r"],
            [' ', ' ', ' ', ''],
            \SugarCraft\Core\Util\Sanitize::untrusted($s),
        );
    }
}
