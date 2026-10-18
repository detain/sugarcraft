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
 * {@see KeyTable}, grouped into the named {@see KeyTable::SECTIONS} and
 * flowed through a two- or three-column grid, paged when the terminal
 * cannot hold the whole grid.
 *
 * The box is adaptive: {@see self::WIDTH} (78, btop's) on an 80-column
 * terminal — today's status quo — growing to {@see self::WIDE_WIDTH}
 * (117, roughly +50%) on wide ones, at least {@see self::THREE_COLUMNS_WIDTH}
 * cells earning the third column. Each column is a stream of sections:
 * a bold heading row, then rows in btop's `key (20 cells, centred) +
 * description` style; descriptions that outgrow the column wrap under
 * themselves. Paging turns whole grid pages (columns x rows-per-column).
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

    /** Widest box (~+50% over btop's 78); wide terminals get the grid. */
    public const WIDE_WIDTH = 117;

    /** A box at least this wide earns a third column instead of two. */
    public const THREE_COLUMNS_WIDTH = 110;

    /** Cells between grid columns. */
    public const GUTTER = 2;

    /** Cells reserved for the centred key label inside a column. */
    public const KEY_COL = 20;

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
     * 117, never wider than two cells under the terminal (the status-quo
     * 78-in-80 margins stay symmetric).
     */
    public static function widthFor(int $cols): int
    {
        return min(self::WIDE_WIDTH, max(self::WIDTH, $cols - 2));
    }

    /** Grid columns a box of this width fits. */
    public static function columnsFor(int $width): int
    {
        return $width >= self::THREE_COLUMNS_WIDTH ? 3 : 2;
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
     * Pages count the GRID, not the flat roster: capacity is one column's
     * line budget times the column count of this terminal's box width.
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
        $capacity = self::columnsFor($width) * max(1, $height - 3);

        return [$x, $y, $height, $height > 3 ? max(1, (int) ceil(count(self::gridLines($width)) / $capacity)) : 1];
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
        if ($delta !== 0) {
            Logo::paint($surface, $y - 1, $c->logo->art, $c->logo->frameIndex, $ink);
        } else {
            Banner::paint($surface, $y - 1, $ink, $c->tty());
        }
        MenuDraw::box($surface, $c, $x, $y + 6 + $delta, $width, $height, Lang::t('overlay.title.help'));
        $clip = MenuDraw::inside($x, $y + 6 + $delta, $width, $height);
        $bold = MenuDraw::BOLD;
        if ($pages > 1) {
            [$open, $close] = $c->border()->embedJunctions(true);
            MenuDraw::at(
                $surface,
                $y + 5 + $delta + $height,
                $x + 2,
                $ink->fg('hi_fg') . $open . $bold . '↑' . $ink->fg('title') . ' '
                    . Lang::t('overlay.help.page', ['page' => $page + 1, 'pages' => $pages]) . ' '
                    . $ink->fg('hi_fg') . '↓' . MenuDraw::UNBOLD . $close,
            );
        }
        $cy = $y + 7 + $delta;
        MenuDraw::at($surface, $cy++, $x + 1, $ink->fg('title') . $bold . MenuDraw::cjust(Lang::t('menu.help.key'), self::KEY_COL) . Lang::t('menu.help.description'), '', $clip);
        $per = max(1, $height - 3);
        $lines = self::gridLines($width);
        for ($j = 0; $j < $columns; ++$j) {
            $col = $x + 1 + $j * ($columnWidth + self::GUTTER);
            $ly = $cy;
            foreach (array_slice($lines, ($page * $columns + $j) * $per, $per) as [$label, $description, $isHeading]) {
                if ($isHeading) {
                    MenuDraw::at($surface, $ly++, $col, $ink->fg('title') . $bold . MenuDraw::cut($label, $columnWidth) . MenuDraw::UNBOLD, '', $clip);
                    continue;
                }
                MenuDraw::at(
                    $surface,
                    $ly++,
                    $col,
                    $ink->fg('hi_fg') . $bold . $label . $ink->fg('main_fg') . MenuDraw::UNBOLD . $description,
                    '',
                    $clip,
                );
            }
        }
    }

    /**
     * The whole grid as one stream of lines, independent of terminal
     * height and paging: per section a heading, then its rows in the
     * roster order, descriptions wrapped to the column's cell budget.
     * Pure and deterministic — the goldens byte-pin this layout.
     *
     * @return list<array{0: string, 1: string, 2: bool}> [label cells, description cells, isHeading]
     */
    private static function gridLines(int $width): array
    {
        $descriptionWidth = max(1, self::columnWidth($width, self::columnsFor($width)) - self::KEY_COL);
        $lines = [];
        foreach (KeyTable::sections() as $heading => $rows) {
            $lines[] = [Lang::t($heading), '', true];
            foreach ($rows as $row) {
                $chunks = self::wrapCells($row->description(), $descriptionWidth);
                $label = MenuDraw::cjust($row->keyLabel(), self::KEY_COL);
                $lines[] = [$label, array_shift($chunks), false];
                foreach ($chunks as $chunk) {
                    $lines[] = [str_repeat(' ', self::KEY_COL), $chunk, false];
                }
            }
        }

        return $lines;
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
