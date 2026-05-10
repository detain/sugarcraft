<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * Pure view function for {@see Manager}. Lays out the two panes
 * side-by-side with a status line beneath.
 */
final class Renderer
{
    public static function render(Manager $m): string
    {
        $left  = self::renderPane($m->tabs === [] ? $m->left : ($m->tabs[$m->tabIndex]['left'] ?? $m->left),  $m->tabs === [] ? ($m->activeIdx === 0) : ($m->tabs[$m->tabIndex]['activeIdx'] ?? 0) === 0);
        $right = self::renderPane($m->tabs === [] ? $m->right : ($m->tabs[$m->tabIndex]['right'] ?? $m->right), $m->tabs === [] ? ($m->activeIdx === 1) : ($m->tabs[$m->tabIndex]['activeIdx'] ?? 0) === 1);
        $body  = Layout::joinHorizontal(0.0, $left, '  ', $right);

        $tabBar = self::renderTabBar($m);
        $search = self::renderSearch($m);
        $status = $m->status === '' ? self::keyHelp() : $m->status;
        return $search . $tabBar . $body . "\n" . self::statusBar($status);
    }

    private static function renderTabBar(Manager $m): string
    {
        if ($m->tabs === [] || count($m->tabs) <= 1) {
            return '';
        }
        $parts = [];
        foreach ($m->tabs as $i => $tab) {
            $label = $tab['left']->cwd;
            if (strlen($label) > 20) {
                $label = '…' . substr($label, -17);
            }
            $prefix = $i === $m->tabIndex ? '[' : ' ';
            $suffix = $i === $m->tabIndex ? ']' : ' ';
            $parts[] = "{$prefix}{$label}{$suffix}";
        }
        return implode(' ', $parts) . "\n";
    }

    private static function renderPane(Pane $pane, bool $active): string
    {
        $style = Style::new()
            ->border($active ? Border::thick() : Border::normal())
            ->padding(0, 1)
            ->width(40);

        $header = sprintf(
            "%s  [%s%s]",
            self::truncate($pane->cwd, 30),
            $pane->sort->value,
            $pane->showHidden ? '+hidden' : '',
        );

        $rows = [];
        foreach ($pane->entries as $i => $entry) {
            $marker  = isset($pane->selected[$entry->name]) ? '✓' : ' ';
            $arrow   = ($i === $pane->cursor) ? '▸' : ' ';
            $name    = $entry->isDir ? $entry->name . '/' : $entry->name;
            $size    = $entry->displaySize();
            $rows[] = sprintf("%s%s %-26s %7s", $arrow, $marker, self::truncate($name, 26), $size);
        }

        $body = $header . "\n" . str_repeat('─', 36) . "\n" . implode("\n", $rows);
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
        return 'Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · d delete · r refresh · q quit · / search · t new tab · ^w close tab · ^tab cycle';
    }

    private static function renderSearch(Manager $m): string
    {
        if ($m->searchQuery === null) {
            return '';
        }
        $lines = [];
        $lines[] = "\x1b[1mSearch: {$m->searchQuery}\x1b[0m";
        $lines[] = '';
        $total = count($m->searchResults);
        foreach ($m->searchResults as $i => $entry) {
            $prefix = $i === $m->searchCursor ? '> ' : '  ';
            $type = $entry->isDir ? '[DIR]' : '[FILE]';
            $lines[] = "{$prefix}{$type} {$entry->name}";
        }
        if ($total === 0) {
            $lines[] = '  (no matches)';
        } else {
            $current = $m->searchCursor + 1;
            $lines[] = "  ({$current}/{$total})";
        }
        return implode("\n", $lines) . "\n\n";
    }

    private static function truncate(string $s, int $n): string
    {
        if (strlen($s) <= $n) {
            return $s;
        }
        return '…' . substr($s, -($n - 1));
    }
}
