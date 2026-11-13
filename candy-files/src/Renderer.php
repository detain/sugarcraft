<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Style;
use SugarCraft\Files\Support\Bidi;
use SugarCraft\Files\Support\GlyphSet;

/**
 * Pure view function for {@see Manager}. Lays out the two panes
 * side-by-side with a status line beneath.
 *
 * No upstream — house pattern (finding 051). superfile composes its view
 * from bubbles/list models; there is no Renderer symbol to mirror.
 */
final class Renderer
{
    /** CF-52: terminal width at which each pane row gains its mtime column. */
    private const DATE_MIN_COLS = 100;
    /** CF-52: cells of `YYYY-MM-DD HH:MM`. */
    private const DATE_CELLS = 16;

    public static function render(Manager $m): string
    {
        // CF-35: one tier decision per frame; every chrome
        // glyph below comes from it. The unicode tier reproduces the
        // historical bytes exactly.
        $glyphs = GlyphSet::current();
        // CF-47 (closes 094): RTL catalogues need their chrome joins pinned;
        // LTR locales render byte-identically (Bidi is a pure no-op there).
        $rtl = Bidi::isRtl(T::locale());
        $active = self::activeIdx($m);
        $tabBar = self::renderTabBar($m, $glyphs, $rtl);
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

        $left  = self::renderPane(self::paneOf($m, 'left'),  $active === 0, $m->termCols, $capacity, $glyphs);
        $right = self::renderPane(self::paneOf($m, 'right'), $active === 1, $m->termCols, $capacity, $glyphs);
        $body  = Layout::joinHorizontal(0.0, $left, '  ', $right);

        // N-16: the active-pane cue used to be border weight alone (thick
        // vs normal) — invisible once the frame is linearized or copied as
        // text. Lead the status line with a localized focus label so every
        // frame says which pane answers the keyboard.
        $focus  = Lang::t('status.focus', ['side' => $active === 0 ? Lang::t('side.left') : Lang::t('side.right')]);
        // CF-35: key-help (pure catalogue chrome) rides the tier; the live
        // status does NOT — it interpolates {name}/{dir} filesystem data,
        // and chrome tiers never rewrite user bytes (same law as pane rows).
        // CF-47: the join separator is bidi-pinned for RTL locales; the
        // interpolated live status keeps its own data bytes untouched.
        $rest = $m->status === ''
            ? self::keyHelp($m->termCols, Width::of($focus), $glyphs, $rtl)
            : $m->status;
        $status = $focus . ' ' . Bidi::pinSeparator($glyphs->sep, $rtl) . ' ' . $rest;
        if ($m->infoLines !== null) {
            // CF-52: stat overlay, same frame-replacement contract as help.
            $frame = self::renderInfo($m) . "\n" . self::statusBar($status);
        } elseif ($m->helpMode) {
            // CF-28 (closes 041): the overlay replaces the browse frame —
            // the table is the whole point of the frame. The status line
            // stays so the focus cue survives; renderHelp windows the
            // entries to the viewport, and the two size belts below are
            // still the last word.
            $frame = self::renderHelp($m, $glyphs, $rtl) . "\n" . self::statusBar($status);
        } else {
            $frame = $search . $tabBar . $body . "\n" . self::statusBar($status);
        }
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

    private static function renderTabBar(Manager $m, GlyphSet $glyphs, bool $rtl): string
    {
        if ($m->tabs === [] || count($m->tabs) <= 1) {
            return '';
        }
        $parts = [];
        foreach ($m->tabs as $i => $tab) {
            // cwd is filesystem data like any name — sanitize before render.
            $label = self::truncate(Entry::sanitizeName($tab['left']->cwd), 20, $glyphs);
            // CF-29 (closes 084): the index prefix is the binding — the
            // tab bar names its own direct-switch keys, 1..9.
            $prefix = ($i + 1) . ($i === $m->tabIndex ? '[' : ' ');
            $suffix = $i === $m->tabIndex ? ']' : ' ';
            // CF-47: each tab cell is one bidi island (CF-02's Latin paths
            // inside bracket neutrals must not drift across neighbours in
            // RTL paragraphs).
            $parts[] = Bidi::isolate("{$prefix}{$label}{$suffix}", $rtl);
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
    private static function renderPane(Pane $pane, bool $active, int $termCols, int $capacity, GlyphSet $glyphs): string
    {
        $outer = $termCols > 0 ? intdiv($termCols - 2, 2) : 40;
        $inner = max(6, $outer - 4);             // content cells: border(2) + padding(2)
        // CF-16 (closes 056): the size column carries localized tokens
        // (VERZ/КАТ/目录…), so its width follows the widest thing it must
        // hold — 7 legacy cells at en, wider for longer catalogues. The
        // name column absorbs the difference: row overhead is 4 cells
        // (arrow, marker, two gaps) plus this size field.
        $sizeCol = max(7, Width::of(Lang::t('size.dir')), Width::of(Lang::t('size.link')));
        // CF-52: the mtime column is a width-tiered luxury (CF-12 law): it
        // only appears once the terminal is wide enough that the name column
        // keeps >= 17 cells after paying for it. No live width (unit shape)
        // and narrow terminals keep the historical row bytes exactly.
        $dateCol = $termCols >= self::DATE_MIN_COLS ? self::DATE_CELLS + 1 : 0;
        $nameCol = max(10, $inner - 4 - $sizeCol - $dateCol);
        $headCap = max(6, $inner - 6);           // '  [sort]' tail keeps 6 cells

        // CF-35: the ascii tier has no thick variant to lean on (box
        // drawing is exactly the Ambiguous band that doubles), so both
        // panes wear the ascii set and the status line's focus label is
        // the active cue — a promise N-16 already made universal.
        $style = Style::new()
            ->border($glyphs->asciiBorders ? Border::ascii() : ($active ? Border::thick() : Border::normal()))
            ->padding(0, 1)
            ->width($inner);

        // CF-22: an unreadable pane says so in its own header — the listing
        // above the status line is the last honest read, and the UI admits it.
        $dirLabel = Entry::sanitizeName($pane->cwd);
        if (!$pane->readable) {
            $dirLabel .= ' ' . Lang::t('pane.unreadable');
        }
        // CF-16 (closes 060): the header shows the localized field label
        // plus a language-neutral ↑/↓ — the raw enum value ('name-asc')
        // used to sit in user chrome untranslated in all 16 locales.
        $header = sprintf(
            "%s  [%s %s%s]",
            self::truncate($dirLabel, $headCap, $glyphs),
            $pane->sort->label(),
            $pane->sort->isAscending() ? $glyphs->up : $glyphs->down,
            $pane->showHidden ? ' ' . Lang::t('pane.hidden_suffix') : '',
        );

        $rows = [];
        [$start, $end, $above, $below] = self::rowWindow(count($pane->entries), $pane->cursor, $capacity);
        if ($above > 0) {
            // Fold hint: the cursor row can never sit off-window (rowWindow
            // follows it), so the hidden head is purely a scroll affordance.
            $rows[] = '  ' . $glyphs->up . ' ' . Lang::t('pane.more_above', ['count' => $above]);
        }
        foreach (array_slice($pane->entries, $start, $end - $start) as $offset => $entry) {
            $i = $start + $offset;
            $marker  = isset($pane->selected[$entry->name]) ? $glyphs->mark : ' ';
            $arrow   = ($i === $pane->cursor) ? $glyphs->cursor : ' ';
            // CF-45: the arrow leg replaces the directory slash for
            // links — `mylink -> /dir` reads, `mylink/ -> /dir` double-marks.
            $name    = $entry->isDir && !$entry->isLink ? $entry->name . '/' : $entry->name;
            // CF-45 (closes 081): a link shows what it is by naming
            // its target in the same leg — `mylink -> /some/dir`, with
            // a localized (broken) tag when the target does not
            // resolve. The raw target is filesystem data like any
            // name, so it is sanitized before render.
            if ($entry->isLink) {
                if ($entry->linkTarget !== '') {
                    $name .= ' -> ' . Entry::sanitizeName($entry->linkTarget);
                }
                if ($entry->linkBroken) {
                    $name .= ' (' . Lang::t('row.link_broken') . ')';
                }
            }
            $name    = Entry::sanitizeName($name);
            $size    = $entry->displaySize();
            // Cell-accurate columns: `%-26s`/`%7s` padded bytes, which
            // drifts for every double-width (CJK/emoji) name and for the
            // localized size tokens CF-16 introduces.
            $date = '';
            if ($dateCol > 0) {
                $date = ' ' . Width::padRight($entry->mtime > 0 ? date('Y-m-d H:i', $entry->mtime) : '', self::DATE_CELLS);
            }
            $rows[] = $arrow . $marker . ' '
                . Width::padRight(self::truncate($name, $nameCol, $glyphs), $nameCol) . ' '
                . Width::padRight($size, $sizeCol) . $date;
        }
        if ($below > 0) {
            $rows[] = '  ' . $glyphs->down . ' ' . Lang::t('pane.more_below', ['count' => $below]);
        }

        $body = $header . "\n" . str_repeat($glyphs->rule, $inner) . "\n" . implode("\n", $rows);
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
     * CF-47: the tiers are joined chord rosters — pure chrome — so their
     * internal ` sep ` joins are bidi-pinned for RTL locales BEFORE the
     * tier budget measures them (pins count one cell each in the core
     * width math, so a pinned roster can fall to a narrower tier earlier).
     *
     * @param int  $termCols    live terminal width, 0 when unknown
     * @param int  $focusCells  cells the focus prefix + separator already took
     * @param bool $rtl         active catalogue is a right-to-left language
     */
    private static function keyHelp(int $termCols, int $focusCells, GlyphSet $glyphs, bool $rtl): string
    {
        $default = Bidi::pinRuns($glyphs->substitute(Lang::t('keyhelp.default')), $glyphs->sep, $rtl);
        if ($termCols <= 0) {
            return $default;
        }
        $budget = $termCols - $focusCells - 3;
        // Literal key strings per tier — the LangCoverageTest no-unused
        // guard greps for them (even in comments), so tiers must not be
        // looped over key names held in variables.
        $terse = Bidi::pinRuns($glyphs->substitute(Lang::t('keyhelp.terse')), $glyphs->sep, $rtl);
        $minimal = Bidi::pinRuns($glyphs->substitute(Lang::t('keyhelp.minimal')), $glyphs->sep, $rtl);
        foreach ([$default, $terse, $minimal] as $tier) {
            if (Width::of($tier) <= $budget) {
                return $tier;
            }
        }

        // Even minimal overflows (comically narrow terminal): the frame
        // clamp in render() cuts the tail, status stays a single row.
        return $minimal;
    }

    /**
     * CF-28 (closes 041): the help overlay. Every binding the key-help
     * legend carries, one per row, windowed to the live height by the
     * same rowWindow law the panes follow. Entries come from the already
     * localized keyhelp.default legend split on its separator — the
     * overlay is lang-backed without duplicating sixteen catalogues of
     * action prose; '?' joins the legend so the strip itself advertises
     * the door.
     */
    private static function renderHelp(Manager $m, GlyphSet $glyphs, bool $rtl): string
    {
        $items = explode(' · ', $glyphs->substitute(Lang::t('keyhelp.default')));
        $count = count($items);
        // Overhead rows in the help frame: title, blank, dismiss line,
        // blank before status, status. Capacity 0 (unit shape / no live
        // height) lists everything and lets the belts below have the
        // last word.
        $capacity = $m->termRows > 0 ? max(1, $m->termRows - 5) : $count;
        // cursor 0: the table is a document, not a follow-the-cursor list.
        [, $end, , $hiddenBelow] = self::rowWindow($count, 0, $capacity);
        $lines = [Ansi::sgr(Ansi::BOLD) . Lang::t('help.title', ['count' => $count]) . Ansi::reset()];
        foreach (array_slice($items, 0, $end) as $item) {
            $lines[] = ' ' . Bidi::pinRuns($item, $glyphs->sep, $rtl);
        }
        if ($hiddenBelow > 0) {
            $lines[] = ' ' . Lang::t('help.more', ['count' => $hiddenBelow]);
        }
        $lines[] = '';
        $lines[] = ' ' . Lang::t('help.dismiss');
        return implode("\n", $lines) . "\n\n";
    }

    /**
     * CF-52: the `I` overlay. Lines were composed (localized, sanitized)
     * by Manager::openInfo; this only titles, windows to the live height
     * and appends the dismiss hint.
     */
    private static function renderInfo(Manager $m): string
    {
        $lines = $m->infoLines ?? [];
        $capacity = $m->termRows > 0 ? max(1, $m->termRows - 5) : count($lines);
        $lines = array_slice($lines, 0, $capacity);
        $out = [Ansi::sgr(Ansi::BOLD) . Lang::t('info.title') . Ansi::reset()];
        foreach ($lines as $line) {
            $out[] = ' ' . $line;
        }
        $out[] = '';
        $out[] = ' ' . Lang::t('info.dismiss');
        return implode("\n", $out) . "\n\n";
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
        // CF-16 (closes 025): the prompt went through Lang like every
        // neighbour; only the query itself stays data (sanitize first).
        $lines[] = Ansi::sgr(Ansi::BOLD) . Lang::t('search.prompt') . Entry::sanitizeName($m->searchQuery) . Ansi::reset();
        $lines[] = '';
        $total = count($m->searchResults);
        // CF-17 exposed a CF-02 gap: the result block was never
        // windowed, so on a live height many results pushed the frame
        // past termRows and the bottom belt sliced the PROMPT away.
        // The block now windows around the cursor like the pane list,
        // keeping the query line and the counter on screen — the rows
        // in the middle are the only thing allowed to fold.
        $shown = $total;
        $start = 0;
        if ($m->termRows > 0 && $total > 0) {
            $maxShown = max(1, $m->termRows - 11); // prompt, blank, counter, fold, blanks, box, tab bar, status
            if ($total > $maxShown) {
                $start = max(0, min($m->searchCursor - intdiv($maxShown, 2), $total - $maxShown));
                $shown = $maxShown;
            }
        }
        // CF-17: a walk hit shows the PATH RELATIVE TO THE PANE, not the
        // bare basename — two 'config.json' two levels apart must not
        // render as the same line, and 'where' is the whole payload of
        // the feature.
        $cwd = $m->activePane()->cwd;
        $visible = 0;
        foreach ($m->searchResults as $i => $entry) {
            if ($i < $start || $i >= $start + $shown) {
                continue;
            }
            ++$visible;
            $prefix = $i === $m->searchCursor ? '> ' : '  ';
            $type = $entry->isDir ? Lang::t('search.type_dir') : Lang::t('search.type_file');
            $dir = $m->searchResultDirs[$i] ?? '';
            $label = $entry->name;
            if ($dir !== '' && $dir !== $cwd) {
                $rel = str_starts_with($dir, $cwd . DIRECTORY_SEPARATOR)
                    ? substr($dir, strlen($cwd) + 1)
                    : ltrim($dir, '/\\');
                $label = $rel . DIRECTORY_SEPARATOR . $entry->name;
            }
            $lines[] = "{$prefix}{$type} " . Entry::sanitizeName($label);
        }
        if ($visible < $total) {
            $lines[] = '  ' . Lang::t('help.more', ['count' => $total - $visible]);
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
     * tier's ellipsis. Byte math (strlen/substr) drifted CJK names by
     * their double-width cells and could split a codepoint mid-sequence.
     * The ascii tier's '...' costs three cells, not one — the head-drop
     * math follows Width::of(ellipsis), never a hard-coded 1.
     */
    private static function truncate(string $s, int $cells, GlyphSet $glyphs): string
    {
        $width = Width::of($s);
        if ($width <= $cells) {
            return $s;
        }

        return $glyphs->ellipsis . Width::dropAnsi($s, $width - ($cells - Width::of($glyphs->ellipsis)));
    }
}
