<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Flexbox\Support\CellPad;

/**
 * CSS flexbox-like layout for terminal UIs.
 *
 * Supports row/column direction, align, gap, and ratio-based sizing.
 *
 * Design antecedent: 76creates/stickers FlexBox (PHP).
 *
 * @see https://github.com/76creates/stickers
 */
final readonly class FlexBox
{
    use Mutable;

    /**
     * @param list<FlexItem> $items
     */
    private function __construct(
        public readonly Direction $direction = Direction::Row,
        public readonly Align $align = Align::Stretch,
        public readonly int $gap = 0,
        private readonly array $items = [],
        public readonly Justify $justify = Justify::Start,
        public readonly Wrap $wrap = Wrap::NoWrap,
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
        return $this->mutate(['direction' => $d]);
    }

    public function withAlign(Align $a): self
    {
        return $this->mutate(['align' => $a]);
    }

    public function withGap(int $cells): self
    {
        if ($cells < 0) {
            throw new \InvalidArgumentException(
                'Gap must be >= 0 cells; got ' . $cells . '.',
            );
        }
        return $this->mutate(['gap' => $cells]);
    }

    /**
     * Distribute leftover main-axis cells (see {@see Justify}). Leftover
     * exists only where no ratio item absorbs it, as in CSS.
     */
    public function withJustify(Justify $justify): self
    {
        return $this->mutate(['justify' => $justify]);
    }

    /**
     * Break a row-direction box onto further lines when its items overflow
     * the width (see {@see Wrap}). Column boxes ignore it.
     */
    public function withWrap(Wrap $wrap): self
    {
        return $this->mutate(['wrap' => $wrap]);
    }

    /**
     * Append one item.
     *
     * IMMUTABLE COST: each call copies the whole item list to append a single
     * element (O(n) per call, O(n²) to build n items — measured 2.6 ms@1k
     * rising to 18.1 ms@3k). That is the price of value semantics, not a
     * silent trap: reach for {@see withItems()} (or {@see row()}/
     * {@see column()}) when the set is known up front — they take the whole
     * list in ONE copy.
     */
    public function addItem(FlexItem $item): self
    {
        $newItems = $this->items;
        $newItems[] = $item;
        return $this->mutate(['items' => $newItems]);
    }

    /**
     * Bulk path: replace the whole item list in a single copy. Building N
     * items with chained addItem() calls is O(N²) in list copies; withItems()
     * is the O(N) construction the {@see row()}/{@see column()} factories use.
     *
     * @param FlexItem ...$items the complete set for this box
     */
    public function withItems(FlexItem ...$items): self
    {
        return $this->mutate(['items' => array_values($items)]);
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
        if ($this->wrap === Wrap::Wrap) {
            return $this->renderWrappedRow($totalWidth, $totalHeight);
        }

        return \implode("\n", $this->renderRowLine($this->items, $totalWidth, $totalHeight, $this->align === Align::Stretch));
    }

    /**
     * Lay one line of items across the width and return its rows.
     *
     * $fillHeight is the Stretch contract: the box takes the declared height.
     * A wrapped line never fills; it takes the height of its tallest cell.
     *
     * @param list<FlexItem> $items
     * @return list<string>
     */
    private function renderRowLine(array $items, int $totalWidth, int $totalHeight, bool $fillHeight): array
    {
        $count = \count($items);
        $gap   = $this->effectiveGap($totalWidth, $count);

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
            $fillHeight
                ? $totalHeight
                : ($heights === [] ? 0 : \max($heights)),
            $totalHeight,
        );

        // Build each item's finished column of cells exactly once (measure ->
        // sanitize -> align -> style), then join per line — byte-identical
        // output at O(lines + cells) instead of O(lines * cells) of work
        // (a 50-item row was measured over one 60fps frame budget on the
        // quadratic shape).
        $cells = [];
        foreach ($measured as $i => $m) {
            $aw        = $m['allocated'];
            $itemLines = $m['item']->lines();
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

        // Slot k sits before item k (slot count is the trailing slot). Interior
        // slots carry the gap plus any justified share; the edges carry only
        // the justified share. Start leaves every slot empty, so the line is
        // unpadded exactly as before justify existed.
        $extra = $this->justifyExtra($measured, $totalWidth, $gap);
        $slotPad = [];
        for ($k = 0; $k <= $count; $k++) {
            $width = ($k === 0 || $k === $count) ? $extra[$k] : $gap + $extra[$k];
            $slotPad[$k] = $width > 0 ? \str_repeat(' ', $width) : '';
        }

        $resultLines = [];
        for ($line = 0; $line < $maxHeight; $line++) {
            $lineStr = $slotPad[0];
            foreach ($cells as $i => $column) {
                if ($i > 0) {
                    $lineStr .= $slotPad[$i];
                }
                $lineStr .= $column[$line];
            }
            $lineStr .= $slotPad[$count];
            $resultLines[] = CellPad::fit($lineStr, $totalWidth)[0];
        }

        return $resultLines;
    }

    /**
     * Greedy line breaking for a wrapping row. Each item's hypothetical size is
     * its basis when set, otherwise its natural width; a line closes before the
     * item that would overflow it. Wrapped lines stack flush with no row gap.
     */
    private function renderWrappedRow(int $totalWidth, int $totalHeight): string
    {
        $groups  = [];
        $current = [];
        $used    = 0;
        foreach ($this->items as $item) {
            $hypothetical = $item->basis > 0
                ? \min($item->basis, $totalWidth)
                : \max(1, $this->measureWidth($item));
            $add = $current === [] ? $hypothetical : $hypothetical + $this->gap;

            if ($current !== [] && $used + $add > $totalWidth) {
                $groups[] = $current;
                $current  = [];
                $used     = 0;
                $add      = $hypothetical;
            }

            $current[] = $item;
            $used     += $add;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        // Every wrapped line is padded to the full width, so the box is a
        // rectangle even when a short last line leaves leftover cells unplaced.
        $lines = [];
        foreach ($groups as $group) {
            foreach ($this->renderRowLine($group, $totalWidth, $totalHeight, false) as $line) {
                [$fitted, $fittedWidth] = CellPad::fit($line, $totalWidth);
                $lines[] = CellPad::pad($fitted, $fittedWidth, $totalWidth, 'right');
            }
        }

        if ($this->align === Align::Stretch) {
            $blank = \str_repeat(' ', $totalWidth);
            while (\count($lines) < $totalHeight) {
                $lines[] = $blank;
            }
        }

        return \implode("\n", \array_slice($lines, 0, $totalHeight));
    }

    private function renderColumn(int $totalWidth, int $totalHeight): string
    {
        $items = $this->items;
        $count = \count($items);
        $gap   = $this->effectiveGap($totalHeight, $count);

        $measured = \array_map(fn(FlexItem $item): array => [
            'item'   => $item,
            'width'  => $this->measureWidth($item),
            'height' => $this->measureHeight($item),
        ], $items);

        $measured = $this->allocateMainAxis($measured, $totalHeight, $gap);

        // Slot k sits before item k; rows in a slot are blank. See renderRowLine.
        $extra = $this->justifyExtra($measured, $totalHeight, $gap);
        $blank = \str_repeat(' ', $totalWidth);

        $resultLines = [];
        for ($i = 0; $i < $count; $i++) {
            $m = $measured[$i];

            $before = $i === 0 ? $extra[0] : $gap + $extra[$i];
            for ($j = 0; $j < $before; $j++) {
                $resultLines[] = $blank;
            }

            $itemLines = $m['item']->lines();
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
                // carrying wide (CJK/emoji) glyphs. alignCell's (styled)
                // output is EXACTLY $maxW cells wide and maxW is clamped to
                // $totalWidth, so the fill is pure arithmetic — no walk.
                $lineStr = CellPad::pad($lineStr, $maxW, $totalWidth, 'right');
                $resultLines[] = $lineStr;
            }

            // Pad to allocated height
            $extraLines = $m['allocated'] - \count($itemLines);
            for ($j = 0; $j < $extraLines; $j++) {
                $resultLines[] = $blank;
            }
        }

        for ($j = 0; $j < $extra[$count]; $j++) {
            $resultLines[] = $blank;
        }

        return \implode("\n", \array_slice($resultLines, 0, $totalHeight));
    }

    /**
     * Leftover main-axis cells split into n+1 slots around n items: index 0
     * is leading, index n trailing, the rest sit between items. Gaps are not
     * part of the result; callers add them to the interior slots.
     *
     * Weights are integer shares. Each slot takes its floored share, and the
     * remainder goes to positive-weight slots in order, so the split always
     * sums to the leftover exactly.
     *
     * @param list<array{item: FlexItem, width: int, height: int, allocated: int}> $measured
     * @return list<int>
     */
    private function justifyExtra(array $measured, int $mainSize, int $gap): array
    {
        $count = \count($measured);
        $used  = $gap * \max(0, $count - 1);
        foreach ($measured as $m) {
            $used += $m['allocated'];
        }
        $leftover = $mainSize - $used;

        $slots = \array_fill(0, $count + 1, 0);
        if ($count === 0 || $leftover <= 0) {
            return $slots;
        }

        if ($this->justify === Justify::Center) {
            // Floor on the leading side, matching centerAlign()'s text placement.
            $slots[0]      = \intdiv($leftover, 2);
            $slots[$count] = $leftover - $slots[0];
            return $slots;
        }

        $weights = match ($this->justify) {
            Justify::Start => \array_fill(0, $count + 1, 0),
            Justify::End => \array_merge([1], \array_fill(0, $count, 0)),
            // One item under space-between is flex-start, the CSS edge rule.
            Justify::SpaceBetween => $count === 1
                ? \array_fill(0, 2, 0)
                : \array_merge([0], \array_fill(0, $count - 1, 1), [0]),
            // Half-slots: edges get one unit, interior slots two, so each item
            // owns equal space on both sides and the edges are half-sized.
            Justify::SpaceAround => \array_merge([1], \array_fill(0, $count - 1, 2), [1]),
            Justify::SpaceEvenly => \array_fill(0, $count + 1, 1),
        };

        $total = \array_sum($weights);
        if ($total === 0) {
            return $slots;
        }

        $assigned = 0;
        foreach ($weights as $k => $w) {
            $slots[$k] = \intdiv($leftover * $w, $total);
            $assigned += $slots[$k];
        }
        $rest = $leftover - $assigned;
        foreach ($weights as $k => $w) {
            if ($rest === 0) {
                break;
            }
            if ($w > 0) {
                $slots[$k]++;
                $rest--;
            }
        }

        return $slots;
    }

    /**
     * The gap that is actually renderable: each interstice is capped so the
     * gaps plus a 1-cell floor for every item still fit the container,
     * bounding the str_repeat/pad work a huge positive gap could trigger.
     * (The setter refuses negatives; this only reins in absurd positives.)
     *
     * @param int $mainSize Container size along the active direction
     * @param int $n        Items laid out on this line
     */
    private function effectiveGap(int $mainSize, int $n): int
    {
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

    /**
     * Natural content width of an item — delegates to the item's own cached
     * measure so a reused FlexItem is measured once per INSTANCE, not once
     * per frame (see FlexItem::measuredWidth()).
     */
    private function measureWidth(FlexItem $item): int
    {
        return $item->measuredWidth();
    }

    /** Natural content height — delegates to the item's cached measure. */
    private function measureHeight(FlexItem $item): int
    {
        return $item->measuredHeight();
    }

    private function alignCell(string $text, int $width, Align $align): string
    {
        // One memoized measure; the truncate walk runs only on overflow
        // (CellPad's byte-identity to the naive pairing is pinned by
        // tests/Support/CellPadTest.php — finding-040).
        [$line, $visualWidth] = CellPad::fit($text, $width);
        if ($visualWidth >= $width) {
            return $line;
        }
        return match ($align) {
            Align::Start    => CellPad::pad($line, $visualWidth, $width, 'right'),
            Align::End      => CellPad::pad($line, $visualWidth, $width, 'left'),
            Align::Center   => CellPad::pad($line, $visualWidth, $width, 'center'),
            Align::Stretch  => CellPad::pad($line, $visualWidth, $width, 'right'),
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
