<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

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
            ->render(Lang::t('help.keyhints'));

        $err = '';
        if ($a->error !== null) {
            $err = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render(Lang::t('ui.error_prefix') . $a->error);
        }

        $success = '';
        if ($a->successMessage !== null) {
            $success = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render($a->successMessage);
        }

        $commitBar = '';
        if ($a->collectingCommit) {
            $commitBar = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render(Lang::t('commit.prompt') . $a->commitMessage . '_');
        }

        $branchBar = '';
        if ($a->collectingBranchName) {
            $branchBar = "\n " . Style::new()->foreground(Color::hex('#fde68a'))
                ->render(Lang::t('branch.prompt') . $a->branchName . '_');
        }

        $mergeBar = '';
        if ($a->collectingMergeTarget) {
            $mergeBar = "\n " . Style::new()->foreground(Color::hex('#a78bfa'))
                ->render(Lang::t('merge.prompt') . $a->mergeTarget . '_');
        }

        $diffOverlay = '';
        if ($a->diffViewer !== null) {
            $diffOverlay = self::diffOverlay($a);
        }

        $rebaseOverlay = '';
        if ($a->showRebaseMenu) {
            $rebaseOverlay = self::rebaseOverlay($a);
        }

        $overlay = '';
        if ($a->showHelp) {
            $overlay = self::helpOverlay($a);
        }

        return $header . "\n" . $body . "\n " . $help . $err . $success . $commitBar . $branchBar . $mergeBar . $diffOverlay . $rebaseOverlay . $overlay . "\n";
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
                ->render(Lang::t('status.clean'));
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
            $rows[] = Lang::t('branches.empty');
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
            $rows[] = Lang::t('log.empty');
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

    private static function helpOverlay(App $a): string
    {
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#fde68a'))->render(' Help '),
            '',
            Style::new()->foreground(Color::hex('#c5b6dd'))->render('General:'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('?') . '  ' . Lang::t('help.context_general'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('q') . '  ' . Lang::t('help.quit'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('R') . '  ' . Lang::t('help.refresh'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('tab') . '  ' . Lang::t('help.switch_pane'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('c') . '  ' . Lang::t('help.commit'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('A') . '  ' . Lang::t('help.amend'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('n') . '  ' . Lang::t('help.new_branch'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('d') . '  ' . Lang::t('help.discard'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('P') . '  ' . Lang::t('help.diff_viewer'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('u') . '  ' . Lang::t('help.undo'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('M') . '  ' . Lang::t('help.merge'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('r') . '  ' . Lang::t('help.rebase'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('esc') . '  ' . Lang::t('help.close_help'),
            '',
            Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('help.pane_navigation')),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('j/k') . '  ' . Lang::t('help.move_cursor'),
            '',
        ];

        if ($a->pane === Pane::Status) {
            $lines = array_merge($lines, [
                Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('help.pane_status')),
                '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('s') . '  ' . Lang::t('help.stage_single'),
                '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('a') . '  ' . Lang::t('help.stage_all'),
            ]);
        } elseif ($a->pane === Pane::Branches) {
            $lines = array_merge($lines, [
                Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('help.pane_branches')),
                '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('space') . '  ' . Lang::t('help.checkout'),
                '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('D') . '  ' . Lang::t('help.delete_branch'),
            ]);
        }

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width(52)
            ->render(implode("\n", $lines));

        return "\n\n" . $box . "\n";
    }

    private static function diffOverlay(App $a): string
    {
        $dv = $a->diffViewer;
        if ($dv === null) {
            return '';
        }

        $lines = [];
        $lines[] = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(' diff: ' . $dv->path . ' ');

        foreach ($dv->lines as $i => $line) {
            $isSelectedHunkLine = false;
            // Highlight the selected hunk block
            if ($dv->hunkStarts !== []) {
                $hunkIdx = array_search($dv->hunkCursor, $dv->hunkStarts, true);
                if ($hunkIdx !== false) {
                    $start = $dv->hunkCursor;
                    $end = count($dv->lines) - 1;
                    if ($hunkIdx + 1 < count($dv->hunkStarts)) {
                        $end = $dv->hunkStarts[$hunkIdx + 1] - 1;
                    }
                    $isSelectedHunkLine = ($i >= $start && $i <= $end);
                }
            }

            $st = Style::new();
            if (str_starts_with($line, '+')) {
                $st = $st->foreground(Color::hex('#6ee7b7'));
            } elseif (str_starts_with($line, '-')) {
                $st = $st->foreground(Color::hex('#ff5f87'));
            } elseif (str_starts_with($line, '@@')) {
                $st = $st->foreground(Color::hex('#a78bfa'))->italic();
            } else {
                $st = $st->foreground(Color::hex('#c5b6dd'));
            }

            if ($isSelectedHunkLine) {
                $st = $st->reverse();
            }

            $lines[] = $st->render(' ' . $line);
        }

        $hint = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render(Lang::t('diff.navigation_hint'));

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->padding(0, 1)
            ->width(72)
            ->render(implode("\n", $lines) . "\n" . $hint);

        return "\n" . $box . "\n";
    }

    private static function rebaseOverlay(App $a): string
    {
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#a78bfa'))->render(' rebase '),
            '',
            Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('rebase.prompt')),
            '',
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('c') . '  ' . 'continue',
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('a') . '  ' . 'abort',
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('s') . '  ' . 'skip',
            '',
            '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . 'cancel',
        ];

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width(40)
            ->render(implode("\n", $lines));

        return "\n\n" . $box . "\n";
    }
}
