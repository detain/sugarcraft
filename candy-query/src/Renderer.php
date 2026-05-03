<?php

declare(strict_types=1);

namespace CandyCore\Query;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;
use CandyCore\Sprinkles\Style;

final class Renderer
{
    public static function render(App $a): string
    {
        $tables = self::tablesPane($a);
        $rows   = self::rowsPane($a);
        $top    = Layout::joinHorizontal(Position::TOP, $tables, '  ', $rows);

        $query  = self::queryPane($a);

        $help   = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('tab  switch pane  ·  enter  load table  ·  ctrl+r  run query  ·  q  quit');

        $status = '';
        if ($a->error !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('error: ' . $a->error);
        } elseif ($a->status !== null) {
            $status = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render($a->status);
        }

        $title = Style::new()->bold()->foreground(Color::hex('#7dd3fc'))
            ->render(' CandyQuery ');

        return $title . "\n" . $top . "\n" . $query . "\n " . $help . $status . "\n";
    }

    private static function tablesPane(App $a): string
    {
        $body = [];
        if ($a->tables === []) {
            $body[] = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no tables)');
        }
        foreach ($a->tables as $i => $name) {
            $st = Style::new()->foreground(Color::hex('#c5b6dd'));
            if ($name === $a->selectedTable) {
                $st = $st->foreground(Color::hex('#fde68a'))->bold();
            }
            if ($a->pane === Pane::Tables && $i === $a->tableCursor) {
                $st = $st->reverse();
            }
            $body[] = $st->render($name);
        }
        return self::frame($a, Pane::Tables, ' tables ', implode("\n", $body), 24);
    }

    private static function rowsPane(App $a): string
    {
        $title = ' rows ' . ($a->selectedTable ? "[{$a->selectedTable}] " : '');
        if ($a->rows === []) {
            return self::frame(
                $a, Pane::Rows, $title,
                Style::new()->foreground(Color::hex('#7d6e98'))->render('(empty)'),
                60,
            );
        }
        // Header row from the first row's keys.
        $cols = array_keys($a->rows[0]);
        $headerLine = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(implode('  ', array_map(static fn($c) => str_pad($c, 12), $cols)));
        $bodyLines = [$headerLine];
        foreach ($a->rows as $i => $row) {
            $cells = [];
            foreach ($cols as $c) {
                $val = $row[$c] ?? '';
                if (is_scalar($val)) {
                    $val = (string) $val;
                } else {
                    $val = json_encode($val) ?: '';
                }
                if (mb_strlen($val) > 12) {
                    $val = mb_substr($val, 0, 11) . '…';
                }
                $cells[] = str_pad($val, 12);
            }
            $line = implode('  ', $cells);
            if ($a->pane === Pane::Rows && $i === $a->rowCursor) {
                $line = Style::new()->reverse()->render($line);
            }
            $bodyLines[] = $line;
            if ($i >= 12) break;
        }
        return self::frame($a, Pane::Rows, $title, implode("\n", $bodyLines), 60);
    }

    private static function queryPane(App $a): string
    {
        $cursorMark = $a->pane === Pane::Query ? '▮' : ' ';
        $body = ($a->queryBuf === '' ? '-- type SQL, ctrl+r to run --' : $a->queryBuf) . $cursorMark;
        return self::frame($a, Pane::Query, ' query ', $body, 88);
    }

    private static function frame(App $a, Pane $p, string $title, string $body, int $width): string
    {
        $border = Border::rounded();
        $st = Style::new()->border($border)->padding(0, 1)->width($width);
        $st = $a->pane === $p
            ? $st->borderForeground(Color::hex('#7dd3fc'))
            : $st->borderForeground(Color::hex('#4a3868'));
        return $st->render(Style::new()->bold()->render($title) . "\n" . $body);
    }
}
