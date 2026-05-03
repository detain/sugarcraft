<?php

declare(strict_types=1);

namespace CandyCore\Stash;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;
use CandyCore\Sprinkles\Style;

/**
 * Pure view function. Renders three panes side-by-side; the focused
 * pane gets a brighter border accent.
 */
final class Renderer
{
    public static function render(App $a): string
    {
        $left  = self::statusPane($a);
        $right = Layout::joinVertical(
            Position::LEFT,
            self::branchesPane($a),
            self::logPane($a),
        );
        $body = Layout::joinHorizontal(Position::TOP, $left, '  ', $right);

        $header = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(' SugarStash ')
            . '   '
            . Style::new()->foreground(Color::hex('#a78bfa'))
                ->render($a->branchSummary !== '' ? "[{$a->branchSummary}]" : '');

        $help = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  R  refresh  ·  q  quit');

        $err = '';
        if ($a->error !== null) {
            $err = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('error: ' . $a->error);
        }

        return $header . "\n" . $body . "\n " . $help . $err . "\n";
    }

    private static function statusPane(App $a): string
    {
        $rows = [];
        foreach ($a->status as $i => $row) {
            $idx  = $row['index_status'] ?? ' ';
            $work = $row['work_status']  ?? ' ';
            $path = $row['path']         ?? '';
            $marker = sprintf('%s%s ', $idx, $work);
            $line = $marker . $path;
            $st = Style::new();
            if ($idx !== ' ') $st = $st->foreground(Color::hex('#6ee7b7'));
            elseif ($work !== ' ') $st = $st->foreground(Color::hex('#fde68a'));
            else $st = $st->foreground(Color::hex('#c5b6dd'));
            if ($a->pane === Pane::Status && $i === $a->statusCursor) {
                $st = $st->reverse();
            }
            $rows[] = $st->render($line);
        }
        if ($rows === []) {
            $rows[] = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('clean working tree');
        }
        return self::frame($a, Pane::Status, ' status ', implode("\n", $rows), 36);
    }

    private static function branchesPane(App $a): string
    {
        $rows = [];
        foreach ($a->branches as $i => $b) {
            $marker = $b['current'] ? '* ' : '  ';
            $line   = $marker . $b['name'];
            $st = Style::new();
            $st = $b['current']
                ? $st->bold()->foreground(Color::hex('#fde68a'))
                : $st->foreground(Color::hex('#c5b6dd'));
            if ($a->pane === Pane::Branches && $i === $a->branchesCursor) {
                $st = $st->reverse();
            }
            $rows[] = $st->render($line);
        }
        if ($rows === []) {
            $rows[] = '(no branches)';
        }
        return self::frame($a, Pane::Branches, ' branches ', implode("\n", $rows), 36);
    }

    private static function logPane(App $a): string
    {
        $rows = [];
        foreach ($a->log as $i => $entry) {
            $sha     = Style::new()->foreground(Color::hex('#fde68a'))->render($entry['sha']);
            $subject = $entry['subject'];
            if (mb_strlen($subject) > 26) {
                $subject = mb_substr($subject, 0, 25) . '…';
            }
            $line = $sha . '  ' . $subject;
            if ($a->pane === Pane::Log && $i === $a->logCursor) {
                $line = Style::new()->reverse()->render($line);
            }
            $rows[] = $line;
        }
        if ($rows === []) {
            $rows[] = '(empty log)';
        }
        return self::frame($a, Pane::Log, ' log ', implode("\n", $rows), 36);
    }

    private static function frame(App $a, Pane $p, string $title, string $body, int $width): string
    {
        $border = Border::rounded();
        $st = Style::new()->border($border)->padding(0, 1)->width($width);
        if ($a->pane === $p) {
            $st = $st->borderForeground(Color::hex('#ff5f87'));
        } else {
            $st = $st->borderForeground(Color::hex('#4a3868'));
        }
        return $st->render(Style::new()->bold()->render($title) . "\n" . $body);
    }
}
