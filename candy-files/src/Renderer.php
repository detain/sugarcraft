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
        $left  = self::renderPane(self::paneOf($m, 'left'),  $active === 0, $m->termCols);
        $right = self::renderPane(self::paneOf($m, 'right'), $active === 1, $m->termCols);
        $body  = Layout::joinHorizontal(0.0, $left, '  ', $right);

        $tabBar = self::renderTabBar($m);
        $search = self::renderSearch($m);
        $status = $m->status === '' ? self::keyHelp() : $m->status;
        return $search . $tabBar . $body . "\n" . self::statusBar($status);
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
     * Width derivation (MAJOR-2, docs-fix-1023): with a live terminal width
     * the two panes split it (minus the 2-cell gutter), floored at the
     * historical 40 cells; without one ($termCols === 0 — unit tests, or a
     * Model built before the first WindowSizeMsg) every column keeps its
     * legacy constant, so the pre-responsive layout is byte-preserved.
     * All inner columns derive from the SAME width: at the 40-cell floor
     * the formulas reproduce the old hardcodes exactly (inner 36, name 26,
     * header 30, separator 36).
     */
    private static function renderPane(Pane $pane, bool $active, int $termCols = 0): string
    {
        $outer = $termCols > 0 ? max(40, intdiv($termCols - 2, 2)) : 40;
        $inner = $outer - 4;                 // borders (2) + padding (2)
        $nameCol = max(10, $inner - 10);     // arrow+marker+2 spaces+size(7)
        $headCap = max(6, $inner - 6);       // '  [sort]' tail keeps 6 cells

        $style = Style::new()
            ->border($active ? Border::thick() : Border::normal())
            ->padding(0, 1)
            ->width($outer);

        $header = sprintf(
            "%s  [%s%s]",
            self::truncate(Entry::sanitizeName($pane->cwd), $headCap),
            $pane->sort->value,
            $pane->showHidden ? ' ' . Lang::t('pane.hidden_suffix') : '',
        );

        $rows = [];
        foreach ($pane->entries as $i => $entry) {
            $marker  = isset($pane->selected[$entry->name]) ? '✓' : ' ';
            $arrow   = ($i === $pane->cursor) ? '▸' : ' ';
            $name    = $entry->isDir ? $entry->name . '/' : $entry->name;
            $name    = Entry::sanitizeName($name);
            $size    = $entry->displaySize();
            // Cell-accurate column: `%-26s` pads bytes, which drifts the
            // size column for every double-width (CJK/emoji) name.
            $rows[] = sprintf("%s%s %s %7s", $arrow, $marker, Width::padRight(self::truncate($name, $nameCol), $nameCol), $size);
        }

        $body = $header . "\n" . str_repeat('─', $inner) . "\n" . implode("\n", $rows);
        return $style->render($body);
    }

    private static function statusBar(string $msg): string
    {
        return Style::new()
            ->padding(0, 1)
            ->render($msg);
    }

    private static function keyHelp(): string
    {
        return Lang::t('keyhelp.default');
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
