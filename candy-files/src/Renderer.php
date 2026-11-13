<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Style;

/**
 * Pure view function for {@see Manager}. Lays out the two panes
 * side-by-side with a status line beneath.
 */
final class Renderer
{
    public static function render(Manager $m): string
    {
        $active = self::activeIdx($m);
        $tabBar = self::renderTabBar($m);
        $search = self::renderSearch($m);

        // CF-02: the entry list is windowed to the live height. Frame
        // chrome is the pane box's fixed rows (top border + header +
        // separator + bottom border = 4), the status line, and any tab-bar
        // / search block already composed. Capacity 0 (no height captured
        // yet — unit shape, or pre-WindowSizeMsg) keeps every entry listed,
        // byte-preserving the legacy frame.
        $capacity = 0;
        if ($m->termRows > 0) {
            $chromeRows = 5
                + ($tabBar === '' ? 0 : substr_count($tabBar, "\n"))
                + ($search === '' ? 0 : substr_count($search, "\n"));
            $capacity = max(1, $m->termRows - $chromeRows);
        }

        $left  = self::renderPane(self::paneOf($m, 'left'),  $active === 0, $m->termCols, $capacity);
        $right = self::renderPane(self::paneOf($m, 'right'), $active === 1, $m->termCols, $capacity);
        $body  = Layout::joinHorizontal(0.0, $left, '  ', $right);

        // N-16: the active-pane cue used to be border weight alone (thick
        // vs normal) — invisible once the frame is linearized or copied as
        // text. Lead the status line with a localized focus label so every
        // frame says which pane answers the keyboard.
        $focus  = Lang::t('status.focus', ['side' => $active === 0 ? Lang::t('side.left') : Lang::t('side.right')]);
        $status = $focus . ' · ' . ($m->status === '' ? self::keyHelp($m->termCols, Width::of($focus)) : $m->status);
        $frame = $search . $tabBar . $body . "\n" . self::statusBar($status);
        if ($m->termCols > 0) {
            // CF-01 belt: once a live width is known, no composed line may
            // exceed it. The status line in particular carries the full
            // key-help string (269 cells at en) until its tiering lands;
            // clipping the composed frame keeps every row inside the
            // terminal instead of letting the runtime wrap borders.
            $lines = array_map(
                static fn(string $line): string => Width::truncateAnsi($line, $m->termCols),
                explode("\n", $frame),
            );
            $frame = implode("\n", $lines);
        }
        if ($m->termRows > 0) {
            // CF-02 belt: the windowing above keeps a browse frame inside
            // the viewport; this floor holds that promise for the states it
            // does not size (an open search block, a comically small
            // terminal) by dropping head lines — status and the pane
            // bottoms stay on screen.
            $lines = explode("\n", $frame);
            if (count($lines) > $m->termRows) {
                $lines = array_slice($lines, count($lines) - $m->termRows);
            }
            $frame = implode("\n", $lines);
        }
        return $frame;
    }

    /** The pane the tabs structure holds for the current tab, or the flat one. */
    private static function paneOf(Manager $m, string $side): Pane
    {
        if ($m->tabs === []) {
            return $side === 'left' ? $m->left : $m->right;
        }
        $tab = $m->tabs[$m->tabIndex] ?? null;
        return ($tab[$side] ?? ($side === 'left' ? $m->left : $m->right));
    }

    private static function activeIdx(Manager $m): int
    {
        if ($m->tabs === []) {
            return $m->activeIdx;
        }
        return (int) (($m->tabs[$m->tabIndex]['activeIdx'] ?? 0));
    }

    private static function renderTabBar(Manager $m): string
    {
        if ($m->tabs === [] || count($m->tabs) <= 1) {
            return '';
        }
        $parts = [];
        foreach ($m->tabs as $i => $tab) {
            // cwd is filesystem data like any name — sanitize before render.
            $label = self::truncate(Entry::sanitizeName($tab['left']->cwd), 20);
            $prefix = $i === $m->tabIndex ? '[' : ' ';
            $suffix = $i === $m->tabIndex ? ']' : ' ';
            $parts[] = "{$prefix}{$label}{$suffix}";
        }
        return implode(' ', $parts) . "\n";
    }

    /**
     * Render one pane box.
     *
     * Width derivation (CF-01 cure): `Style::width()` is the CONTENT
     * width — border (2 cells) and padding (2 cells) are added on top of
     * it. Passing the outer split there used to make every pane render 4
     * cells wider than its share, so an 80-col terminal got a 90-cell
     * frame that wrapped mid-border. The pane box now measures exactly
     * $outer: the content gets $outer - 4 and every inner column derives
     * from that same content width, so the separator fills the pane and
     * two boxes plus the 2-cell gutter land on termCols. Without a live
     * width ($termCols === 0 — unit tests, or a Model built before the
     * first WindowSizeMsg) the historical 40-cell box is preserved
     * (content 36, separator 36, name 25, header cap 30).
     */
    private static function renderPane(Pane $pane, bool $active, int $termCols = 0, int $capacity = 0): string
    {
        $outer = $termCols > 0 ? intdiv($termCols - 2, 2) : 40;
        $inner = max(6, $outer - 4);             // content cells: border(2) + padding(2)
        $nameCol = max(10, $inner - 11);         // arrow+marker+space+name+space+size(7)
        $headCap = max(6, $inner - 6);           // '  [sort]' tail keeps 6 cells

        $style = Style::new()
            ->border($active ? Border::thick() : Border::normal())
            ->padding(0, 1)
            ->width($inner);

        // CF-22: an unreadable pane says so in its own header — the listing
        // above the status line is the last honest read, and the UI admits it.
        $dirLabel = Entry::sanitizeName($pane->cwd);
        if (!$pane->readable) {
            $dirLabel .= ' ' . Lang::t('pane.unreadable');
        }
        $header = sprintf(
            "%s  [%s%s]",
            self::truncate($dirLabel, $headCap),
            $pane->sort->value,
            $pane->showHidden ? ' ' . Lang::t('pane.hidden_suffix') : '',
        );

        $rows = [];
        [$start, $end, $above, $below] = self::rowWindow(count($pane->entries), $pane->cursor, $capacity);
        if ($above > 0) {
            // Fold hint: the cursor row can never sit off-window (rowWindow
            // follows it), so the hidden head is purely a scroll affordance.
            $rows[] = '  ↑ ' . Lang::t('pane.more_above', ['count' => $above]);
        }
        foreach (array_slice($pane->entries, $start, $end - $start) as $offset => $entry) {
            $i = $start + $offset;
            $marker  = isset($pane->selected[$entry->name]) ? '✓' : ' ';
            $arrow   = ($i === $pane->cursor) ? '▸' : ' ';
            $name    = $entry->isDir ? $entry->name . '/' : $entry->name;
            $name    = Entry::sanitizeName($name);
            $size    = $entry->displaySize();
            // Cell-accurate column: `%-26s` pads bytes, which drifts the
            // size column for every double-width (CJK/emoji) name.
            $rows[] = sprintf("%s%s %s %7s", $arrow, $marker, Width::padRight(self::truncate($name, $nameCol), $nameCol), $size);
        }
        if ($below > 0) {
            $rows[] = '  ↓ ' . Lang::t('pane.more_below', ['count' => $below]);
        }

        $body = $header . "\n" . str_repeat('─', $inner) . "\n" . implode("\n", $rows);
        return $style->render($body);
    }

    /**
     * CF-02: the visible window into the entry list, following the cursor.
     * Capacity 0 (height not captured yet) or a list that already fits
     * returns the whole list — the legacy unwindowed frame is preserved
     * byte-identically. When the list overflows, a fold-hint row claims one
     * capacity cell at each hidden end, so at most capacity - 2 entries
     * show mid-list.
     *
     * @return array{0:int,1:int,2:int,3:int} [start, end, hiddenAbove, hiddenBelow]
     */
    private static function rowWindow(int $count, int $cursor, int $capacity): array
    {
        if ($capacity <= 0 || $count <= $capacity) {
            return [0, $count, 0, 0];
        }
        $win = max(1, $capacity - 2);
        $start = max(0, min($cursor - intdiv($win, 2), $count - $win));
        $end = $start + $win;
        return [$start, $end, $start, $count - $end];
    }

    private static function statusBar(string $msg): string
    {
        return Style::new()
            ->padding(0, 1)
            ->render($msg);
    }

    /**
     * CF-12: the key-help line picks the widest tier that still fits.
     * The full roster is 269 cells at en — three permanently wrapped rows
     * on an 80-column terminal (the 017 defect). Tier order: full default
     * (every chord) → terse (primary verbs, no long-tail chords) →
     * minimal (open/quit/search). With no live width the historical full
     * line is preserved (unit shape, pre-WindowSizeMsg frames).
     *
     * @param int $termCols    live terminal width, 0 when unknown
     * @param int $focusCells  cells the focus prefix + separator already took
     */
    private static function keyHelp(int $termCols = 0, int $focusCells = 0): string
    {
        $default = Lang::t('keyhelp.default');
        if ($termCols <= 0) {
            return $default;
        }
        $budget = $termCols - $focusCells - 3;
        foreach ([$default, Lang::t('keyhelp.terse'), Lang::t('keyhelp.minimal')] as $tier) {
            if (Width::of($tier) <= $budget) {
                return $tier;
            }
        }
        // Even minimal overflows (comically narrow terminal): the frame
        // clamp in render() cuts the tail, status stays a single row.
        return Lang::t('keyhelp.minimal');
    }

    private static function renderSearch(Manager $m): string
    {
        if ($m->searchQuery === null) {
            return '';
        }
        $lines = [];
        // The query is typed at the keyboard today, but it reaches view()
        // through the same Model field any injected string would — sanitize
        // it like every other untrusted render.
        $lines[] = Ansi::sgr(Ansi::BOLD) . 'Search: ' . Entry::sanitizeName($m->searchQuery) . Ansi::reset();
        $lines[] = '';
        $total = count($m->searchResults);
        foreach ($m->searchResults as $i => $entry) {
            $prefix = $i === $m->searchCursor ? '> ' : '  ';
            $type = $entry->isDir ? Lang::t('search.type_dir') : Lang::t('search.type_file');
            $lines[] = "{$prefix}{$type} " . Entry::sanitizeName($entry->name);
        }
        if ($total === 0) {
            $lines[] = '  ' . Lang::t('search.no_match');
        } else {
            $current = $m->searchCursor + 1;
            $lines[] = '  ' . Lang::t('search.counter', ['current' => $current, 'total' => $total]);
        }
        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Clip $s to $cells DISPLAY cells keeping the tail (the leaf of a path
     * or the extension of a name is the meaningful end), prefixed with the
     * one-cell ellipsis. Byte math (strlen/substr) drifted CJK names by
     * their double-width cells and could split a codepoint mid-sequence.
     */
    private static function truncate(string $s, int $cells): string
    {
        $width = Width::of($s);
        if ($width <= $cells) {
            return $s;
        }
        return '…' . Width::dropAnsi($s, $width - ($cells - 1));
    }
}
