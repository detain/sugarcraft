<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;
use SugarCraft\Top\View\Banner;
use SugarCraft\Top\View\Logo;
use SugarCraft\Top\View\Surface;

/**
 * btop's help menu: the banner over a `help` box listing every key from
 * {@see KeyTable}, grouped into the named {@see KeyTable::SECTIONS}, each
 * section drawn as its own framed box, and the boxes flowed through two
 * columns, paged when the terminal cannot hold the whole grid.
 *
 * The box is adaptive: {@see self::WIDTH} (78, btop's) at the 80-column
 * minimum, growing with the terminal up to {@see self::WIDE_WIDTH} (166)
 * so the two framed columns carry wide description gutters. Every width
 * gets exactly two columns:
 * a framed section box needs the column the third one used to take. Each
 * column stacks whole boxes: the top border carries the section heading
 * as an embedded title (btop's `┐title┌`), the rows inside run btop's
 * `key (16 cells, centred) + description` style, and descriptions that
 * outgrow the box wrap under themselves. A box is atomic — it never
 * splits across a column break or a page break; a section taller than a
 * whole column continues as a NEW framed box carrying the same heading,
 * so no row is ever hidden behind a clip. Paging turns whole pages
 * (both columns at once), and {@see self::plan()} is the one pure,
 * deterministic model of that flow.
 *
 * KEY_COL keeps btop's 20. At the widest 166 box the 79-cell frame interior
 * leaves a 79 - 20 = 59-cell description column and NONE of the roster's
 * 60 descriptions wraps; at the 118-cell box a 120-column terminal gets,
 * 39 still do — a two-up framed layout with 56-cell-plus descriptions
 * would need a ~160-cell box, which simply is not there. Lowering KEY_COL
 * toward its 16-cell floor was measured (see the sweep in this campaign's
 * evidence report): it saves only 7 of those 39 at width 118 and nothing
 * at 166, while `MenuDraw::cjust()` CLIPS the two labels longer than 16
 * cells (`Shift + Left, Right`, `Alt+Shift+Left/Right`) to mangled
 * prefixes. Truncated key names are worse data loss than a second
 * description line; 20 stands.
 *
 * Keys: escape / q / h / backspace / space / enter or any click close it;
 * with more than one page, down / j / page_down / tab / wheel-down turn
 * forward and up / k / page_up / shift_tab / wheel-up back, wrapping.
 *
 * Deviation: btop draws the `↑ page n/N ↓` indicator one row BELOW the
 * box (`y + height + 6`, off-screen on a 24-row terminal); here it sits on
 * the box's bottom border.
 *
 * Mirrors aristocratos/btop Menu::helpMenu (src/btop_menu.cpp:1743-1794).
 */
final class HelpMenu implements Overlay
{
    /** Narrowest box: btop's fixed help width, used at the 80-column minimum. */
    public const WIDTH = 78;

    /** Widest box; wide terminals get frames roomy enough that descriptions stop wrapping. */
    public const WIDE_WIDTH = 166;

    /** The grid is always two framed columns (see the class docblock). */
    public const COLUMNS = 2;

    /** Cells between grid columns. */
    public const GUTTER = 2;

    /** Cells reserved for the centred key label inside a box (btop's; the docblock records why the measured step-down was rejected). */
    public const KEY_COL = 20;

    /**
     * Non-content rows inside the outer box: the top border, the centred
     * key/description header row, and the bottom border. A page's content
     * budget is therefore `height - self::CHROME_ROWS` per column, and a
     * page fitted to its own content renders `usedRows + self::CHROME_ROWS`
     * box rows ({@see self::usedRows()}).
     */
    public const CHROME_ROWS = 3;

    private function __construct(
        public readonly int $page = 0,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function capturesInput(): bool
    {
        return true;
    }

    /** btop Menu::process: main, options, help and the signal chooser need 80x24. */
    public function minSize(): array
    {
        return [80, 24];
    }

    /**
     * Box width for a terminal: btop's 78 at the 80-column minimum, up to
     * 166, never wider than two cells under the terminal (the 78-in-80
     * margins stay symmetric).
     */
    public static function widthFor(int $cols): int
    {
        return min(self::WIDE_WIDTH, max(self::WIDTH, $cols - 2));
    }

    /**
     * Grid columns a box of this width fits — always two: the third column
     * the adaptive grid once earned (110+ cells) was too narrow to hold a
     * framed section box and its descriptions. Kept as a method because
     * the width math and the tests flow through it.
     */
    public static function columnsFor(int $width): int
    {
        return self::COLUMNS;
    }

    /** Cells of one grid column inside a box of this width. */
    public static function columnWidth(int $width, int $columns): int
    {
        return intdiv($width - 2 - ($columns - 1) * self::GUTTER, $columns);
    }

    /**
     * The help geometry, 1-based: [x, y, height, pages].
     *
     * `$delta` is the logo's extra height over the 6-row banner (see
     * {@see self::logoDelta()}): the box top moves down by it and the box
     * shrinks by it, keeping the bottom row anchored where it was — the
     * whole block stays centered the way today's banner+box is.
     *
     * Pages count the BOX PLAN, not the flat roster: one page holds two
     * columns of `height - self::CHROME_ROWS` rows each (the box interior
     * minus the centred key/description header row), and {@see self::plan()}
     * decides which section boxes (and which segments of an over-tall
     * section) land where. That budget is the per-page CONTENT CAPACITY,
     * planned at this global height for every page; the box
     * {@see self::paint()} renders is fitted per page to at most
     * `usedRows + self::CHROME_ROWS` rows ({@see self::usedRows()}),
     * never taller and never re-flowed — a short page shows a shorter box,
     * the pagination stays identical.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function geometry(int $cols, int $rows, int $delta = 0): array
    {
        $n = count(KeyTable::rows());
        $y = max(1, intdiv($rows, 2) - 4 - intdiv($n, 2));
        $width = self::widthFor($cols);
        $x = max(1, intdiv($cols, 2) - intdiv($width, 2));
        $height = min($rows - 6 - $delta, $n + 3);

        return [$x, $y, $height, $height > 3 ? max(1, count(self::plan($width, $height - self::CHROME_ROWS))) : 1];
    }

    /**
     * Rows the resolved logo adds over the banner; 0 keeps every geometry
     * and paint term at its legacy value (byte-identical fallback). Two
     * gates: the box needs at least a 6-row interior (border, title,
     * separator, one key row, footer, border) before a logo is worth the
     * squeeze, AND the true delta'd extents — with `y` centred/clamped by
     * geometry() the bottom border can ride past the last row while the
     * interior floor still passes (rows-6-δ beats 6 at heights where the
     * content or clamp term wins). Delegates the height/pages math to
     * geometry() itself so the two can never diverge.
     */
    public static function logoDelta(OverlayContext $c): int
    {
        if ($c->logo === null) {
            return 0;
        }
        $delta = $c->logo->art->height() - Banner::height();
        if ($delta <= 0) {
            return 0;
        }
        if ($c->rows - 6 - $delta < 6) {
            return 0;
        }
        [$x, $y, $height] = self::geometry($c->cols, $c->rows, $delta);

        return Logo::blockFits($y - 1, $y + 5 + $delta + $height, $c->rows) ? $delta : 0;
    }

    public function update(Msg $msg, OverlayContext $context): OverlayResult
    {
        $key = KeyName::of($msg);
        $pages = self::geometry($context->cols, $context->rows, self::logoDelta($context))[3];
        if (in_array($key, ['escape', 'q', 'h', 'backspace', 'space', 'enter', 'mouse_click'], true)) {
            return OverlayResult::close();
        }
        if ($pages > 1 && in_array($key, ['down', 'j', 'page_down', 'tab', 'mouse_scroll_down'], true)) {
            return OverlayResult::keep(new self($this->page + 1 >= $pages ? 0 : $this->page + 1));
        }
        if ($pages > 1 && in_array($key, ['up', 'k', 'page_up', 'shift_tab', 'mouse_scroll_up'], true)) {
            return OverlayResult::keep(new self($this->page - 1 < 0 ? $pages - 1 : $this->page - 1));
        }

        return OverlayResult::keep($this);
    }

    public function paint(Surface $surface, OverlayContext $c): void
    {
        $ink = $c->ink;
        $delta = self::logoDelta($c);
        [$x, $y, $height, $pages] = self::geometry($c->cols, $c->rows, $delta);
        $width = self::widthFor($c->cols);
        $columns = self::columnsFor($width);
        $columnWidth = self::columnWidth($width, $columns);
        $page = min($this->page, $pages - 1);
        // The plan is ALWAYS derived at the global capacity (height - CHROME_ROWS
        // rows per column): which boxes land on which page is a property of the
        // content budget, never of the current page's visual height, so page
        // turns cannot re-flow. The outer box then FITS the page it draws —
        // top border exactly where it always was (the box grows and shrinks
        // DOWNWARD only, no vertical jump across a turn) and height_p =
        // usedRows + CHROME_ROWS rows tall. logoDelta() and Logo::blockFits()
        // keep reserving the GLOBAL-height block extents above, which the
        // fitted box never exceeds (it only shrinks), so the fit stays
        // conservative by construction.
        $grid = self::plan($width, max(1, $height - self::CHROME_ROWS));
        $boxHeight = min($height, self::usedRows($grid[$page] ?? []) + self::CHROME_ROWS);
        if ($delta !== 0) {
            Logo::paint($surface, $y - 1, $c->logo->art, $c->logo->frameIndex, $ink);
        } else {
            Banner::paint($surface, $y - 1, $ink, $c->tty());
        }
        MenuDraw::box($surface, $c, $x, $y + 6 + $delta, $width, $boxHeight, Lang::t('overlay.title.help'));
        $clip = MenuDraw::inside($x, $y + 6 + $delta, $width, $boxHeight);
        $bold = MenuDraw::BOLD;
        if ($pages > 1) {
            [$open, $close] = $c->border()->embedJunctions(true);
            MenuDraw::at(
                $surface,
                $y + 5 + $delta + $boxHeight,
                $x + 2,
                $ink->fg('hi_fg') . $open . $bold . '↑' . $ink->fg('title') . ' '
                    . Lang::t('overlay.help.page', ['page' => $page + 1, 'pages' => $pages]) . ' '
                    . $ink->fg('hi_fg') . '↓' . MenuDraw::UNBOLD . $close,
            );
        }
        $cy = $y + 7 + $delta;
        MenuDraw::at($surface, $cy++, $x + 1, $ink->fg('title') . $bold . MenuDraw::cjust(Lang::t('menu.help.key'), self::KEY_COL) . Lang::t('menu.help.description'), '', $clip);
        foreach ($grid[$page] ?? [] as $j => $boxes) {
            $bx = $x + 1 + $j * ($columnWidth + self::GUTTER);
            foreach ($boxes as $box) {
                $by = $cy + $box['row'];
                $bh = count($box['lines']) + 2;
                MenuDraw::box($surface, $c, $bx, $by, $columnWidth, $bh, $box['heading']);
                $inner = MenuDraw::inside($bx, $by, $columnWidth, $bh);
                $ly = $by + 1;
                foreach ($box['lines'] as [$label, $description]) {
                    MenuDraw::at(
                        $surface,
                        $ly++,
                        $bx + 1,
                        $ink->fg('hi_fg') . $bold . $label . $ink->fg('main_fg') . MenuDraw::UNBOLD . $description,
                        '',
                        $inner,
                    );
                }
            }
        }
    }

    /**
     * The whole grid as pages of framed section boxes, independent of the
     * terminal beyond width and per-column height: every section becomes a
     * box (heading + its rows in roster order, descriptions wrapped to the
     * box interior minus the key column), over-tall sections are first cut
     * into sized segments, and the ordered boxes are then placed by a
     * greedy pass — each box takes the FITTING column with the LEAST used
     * height (ties to the left column), a column takes a box when
     * `used + spacer + height` stays within `$perColumn` (the spacer is one
     * blank row, needed only when the column already stacks a box), and
     * the page turns only when NEITHER column fits. Later, smaller boxes
     * therefore fill the shorter column's hole instead of trailing the
     * first one down, and for every non-last page the first box of the
     * next page provably fits NEITHER column of it — only that first box:
     * a later, smaller one on the same page might still have squeezed into
     * a residual hole, and the pass lets the turn stand rather than reach
     * past the box in front of it, so the invariant is reading-order-stable
     * maximality, not order-free optimality. Boxes are never reordered
     * against reading order; a page reads column-major (left stack, then
     * right), and a split section's continuation rides whichever column has
     * room, so its second frame can sit left of its first — the embedded
     * headings keep that legible either way. A box is atomic; a section taller than one whole
     * column splits into balanced segments, EACH a framed box re-embedding
     * the heading, so every row stays visible. Pure and deterministic —
     * the goldens and {@see self::geometry()} byte-pin this layout.
     *
     * @return list<list<list<array{heading: string, lines: list<array{0: string, 1: string}>, row: int, segment: int, segments: int}>>> pages => columns => boxes (lines hold [key cells, description cells]; row is the box's 0-based offset in its column, spacer gaps included)
     */
    public static function plan(int $width, int $perColumn): array
    {
        $columnCount = self::columnsFor($width);
        $descriptionWidth = max(1, self::columnWidth($width, $columnCount) - 2 - self::KEY_COL);
        $chunkMax = max(1, $perColumn - 2);

        // Ordered boxes first: sections in KeyTable::SECTIONS order, each
        // over-tall section pre-split so every segment fits one bare column.
        $boxes = [];
        foreach (KeyTable::sections() as $heading => $rows) {
            $title = Lang::t($heading);
            $lines = [];
            foreach ($rows as $rowModel) {
                $chunks = self::wrapCells($rowModel->description(), $descriptionWidth);
                $label = MenuDraw::cjust($rowModel->keyLabel(), self::KEY_COL);
                $lines[] = [$label, array_shift($chunks)];
                foreach ($chunks as $chunk) {
                    $lines[] = [str_repeat(' ', self::KEY_COL), $chunk];
                }
            }
            $segments = self::splitSegments($lines, $chunkMax);
            foreach ($segments as $index => $segment) {
                $boxes[] = [
                    'heading' => $title,
                    'lines' => $segment,
                    'row' => 0,
                    'segment' => $index,
                    'segments' => count($segments),
                ];
            }
        }

        // Greedy placement in that order: least-used fitting column, page
        // turn only on a two-column refusal.
        $pages = [];
        $columns = array_fill(0, $columnCount, []);
        $used = array_fill(0, $columnCount, 0);
        foreach ($boxes as $box) {
            $height = count($box['lines']) + 2;
            $target = null;
            for ($c = 0; $c < $columnCount; ++$c) {
                $needed = $used[$c] + ($used[$c] > 0 ? 1 : 0) + $height;
                if ($needed <= $perColumn && ($target === null || $used[$c] < $used[$target])) {
                    $target = $c;
                }
            }
            if ($target === null && $used !== array_fill(0, $columnCount, 0)) {
                // Neither column takes the box: flush the page and start
                // the next; the fresh left column always accepts it below.
                $pages[] = $columns;
                $columns = array_fill(0, $columnCount, []);
                $used = array_fill(0, $columnCount, 0);
            }
            if ($target === null) {
                // Degenerate column (perColumn < 3): the box is taller than
                // any empty column can hold. Place it at the head of the
                // left column anyway — overflowing exactly as the old
                // atomic flow did on the same inputs, and terminating.
                $target = 0;
            }
            $box['row'] = $used[$target] > 0 ? $used[$target] + 1 : 0;
            $columns[$target][] = $box;
            $used[$target] = $box['row'] + $height;
        }
        $pages[] = $columns;

        return $pages;
    }

    /**
     * The content rows one planned page actually consumes: the deepest box
     * bottom across the two columns — each box rides at its column offset
     * `row` and is `count(lines) + 2` rows tall (its own two borders), the
     * one-row spacer between stacked boxes is already inside `row`, and a
     * trailing spacer never exists. `usedRows + self::CHROME_ROWS` is the
     * page's fitted outer-box height in {@see self::paint()}. Precondition
     * for the fitted box to hide nothing: a page planned at capacity
     * `height - self::CHROME_ROWS` has `usedRows <= height - self::CHROME_ROWS`
     * whenever that capacity is at least 3 (below it the greedy overflows
     * the head of a column exactly as the old atomic flow did, and paint's
     * min() then keeps the legacy global box height). Pure — a function of
     * {@see self::plan()}'s output, deterministic like the planner itself.
     *
     * @param list<list<array{heading: string, lines: list<array{0: string, 1: string}>, row: int, segment: int, segments: int}>> $columns one page's entry from {@see self::plan()}: columns => boxes
     */
    public static function usedRows(array $columns): int
    {
        $used = 0;
        foreach ($columns as $boxes) {
            foreach ($boxes as $box) {
                $used = max($used, $box['row'] + count($box['lines']) + 2);
            }
        }

        return $used;
    }

    /**
     * A section's content lines cut into per-column pieces: at most one
     * segment while the whole box fits a bare column (atomic), otherwise
     * evenly balanced chunks of at most `$chunkMax` lines — each chunk
     * still fits its own frame inside the column.
     *
     * @param list<array{0: string, 1: string}> $lines
     * @return list<list<array{0: string, 1: string}>>
     */
    private static function splitSegments(array $lines, int $chunkMax): array
    {
        if (count($lines) <= $chunkMax) {
            return [$lines];
        }
        $count = intdiv(count($lines) + $chunkMax - 1, $chunkMax);
        $size = intdiv(count($lines) + $count - 1, $count);

        return array_chunk($lines, $size);
    }

    /**
     * Split text into runs of at most `$cells` terminal cells, breaking on
     * spaces where one fits and hard-splitting only words too long for a
     * whole line. Pure and deterministic (Lang texts are ANSI-free).
     *
     * @return non-empty-list<string>
     */
    private static function wrapCells(string $text, int $cells): array
    {
        if ($cells < 1) {
            return [''];
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            if ($word === '') {
                continue;
            }
            for (;;) {
                $joined = $line === '' ? $word : $line . ' ' . $word;
                if (Width::string($joined) <= $cells) {
                    $line = $joined;
                    continue 2;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                    continue;
                }
                $head = self::fillCells($word, $cells);
                $lines[] = $head;
                $word = mb_substr($word, mb_strlen($head));
                if ($word === '') {
                    continue 2;
                }
            }
        }
        $lines[] = $line;

        return $lines;
    }

    /** The longest non-empty prefix of `$word` fitting `$cells` cells (at least one codepoint). */
    private static function fillCells(string $word, int $cells): string
    {
        $head = '';
        foreach (mb_str_split($word, 1, 'UTF-8') as $character) {
            if ($head !== '' && Width::string($head . $character) > $cells) {
                break;
            }
            $head .= $character;
        }

        return $head;
    }
}
