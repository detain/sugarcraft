<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy;

use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Style;

/**
 * Pure view function for {@see Manager}. Lays out the two panes
 * side-by-side with a status line beneath.
 */
final class Renderer
{
    public static function render(Manager $m): string
    {
        $left  = self::renderPane($m->left,  $m->activeIdx === 0);
        $right = self::renderPane($m->right, $m->activeIdx === 1);
        $body  = Layout::joinHorizontal(0.0, $left, '  ', $right);

        $status = $m->status === '' ? self::keyHelp() : $m->status;
        return $body . "\n" . self::statusBar($status);
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
        return 'Tab swap · ↑↓ jk move · Enter open · ← h up · space select · s sort · . hidden · d delete · r refresh · q quit';
    }

    private static function truncate(string $s, int $n): string
    {
        if (strlen($s) <= $n) {
            return $s;
        }
        return '…' . substr($s, -($n - 1));
    }
}
