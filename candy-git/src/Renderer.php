<?php

declare(strict_types=1);

namespace SugarCraft\Git;

use SugarCraft\Core\Syntax\TokenKind;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;

/**
 * Pure view function. Renders three panes side-by-side; the focused
 * pane gets a brighter border accent.
 *
 * Mirrors jesseduffield/lazygit pkg/gui/views + commands — pane borders, focus accent, panel rows. */
final class Renderer
{
    /**
     * Strip C0/C1 control bytes and every escape sequence from untrusted git
     * output. Applied before concatenation to prevent terminal escape
     * injection. Rides sugar-core's fail-closed sanitizer: the previous `/u`
     * preg pattern returned FALSE on malformed UTF-8 (git subjects can carry
     * arbitrary bytes), which silently skipped ALL stripping — ESC included.
     */
    private static function sanitize(string $s): string
    {
        return Sanitize::untrusted($s);
    }

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
            ->render(' CandyGit ')
            . '   '
            . Style::new()->foreground(Color::hex('#a78bfa'))
                ->render($a->branchSummary !== '' ? "[{$a->branchSummary}]" : '');

        // A26 (audit 2026-11): the key-hint strip is the longest single line
        // in the frame; clip it to the terminal instead of overflowing.
        $help = self::truncate(
            Style::new()->foreground(Color::hex('#7d6e98'))
                ->render(Lang::t('help.keyhints')),
            $a->barWidth()
        );

        $err = '';
        if ($a->error !== null) {
            $err = "\n " . Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render(self::truncate(Lang::t('ui.error_prefix') . $a->error, $a->barWidth()));
        }

        $success = '';
        if ($a->successMessage !== null) {
            $success = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render(self::truncate($a->successMessage, $a->barWidth()));
        }

        $commitBar = '';
        if ($a->collectingCommit) {
            $commitBar = "\n " . Style::new()->foreground(Color::hex('#6ee7b7'))
                ->render(self::truncate(Lang::t('commit.prompt') . $a->commitMessage . '_', $a->barWidth()));
        }

        $branchBar = '';
        if ($a->collectingBranchName) {
            $branchBar = "\n " . Style::new()->foreground(Color::hex('#fde68a'))
                ->render(self::truncate(Lang::t('branch.prompt') . $a->branchName . '_', $a->barWidth()));
        }

        $mergeBar = '';
        if ($a->collectingMergeTarget === TRUE) {
            $mergeBar = "\n " . Style::new()->foreground(Color::hex('#a78bfa'))
                ->render(self::truncate(Lang::t('merge.prompt') . $a->mergeTarget . '_', $a->barWidth()));
        }

        $diffOverlay = '';
        if ($a->diffViewer !== null) {
            $diffOverlay = self::diffOverlay($a);
        }

        $rebaseOverlay = '';
        if ($a->showRebaseMenu === TRUE) {
            $rebaseOverlay = self::rebaseOverlay($a);
        }

        $overlay = '';
        if ($a->showHelp === TRUE) {
            $overlay = self::helpOverlay($a);
        }

        $stashOverlay = '';
        if ($a->stashManager !== null) {
            $stashOverlay = self::stashOverlay($a);
        }

        $cherryPickBar = '';
        if ($a->cherryPick !== null && $a->cherryPick->collecting) {
            $cherryPickBar = "\n " . Style::new()->foreground(Color::hex('#fde68a'))
                ->render(self::truncate(Lang::t('cherry_pick.prompt') . $a->cherryPick->commitRef . '_', $a->barWidth()));
        }

        $worktreeOverlay = '';
        if ($a->worktrees !== null) {
            $worktreeOverlay = self::worktreeOverlay($a);
        }

        $interactiveRebaseOverlay = '';
        if ($a->interactiveRebase !== null) {
            $interactiveRebaseOverlay = self::interactiveRebaseOverlay($a);
        }

        $frame = $header . "\n" . $body . "\n " . $help . $err . $success . $commitBar . $branchBar . $mergeBar . $diffOverlay . $rebaseOverlay . $stashOverlay . $cherryPickBar . $worktreeOverlay . $interactiveRebaseOverlay . $overlay;

        // Hard ceiling: whatever the per-pane budgets missed, the frame never
        // ships more rows than the terminal has (audit K3). The trailing empty
        // piece of explode-on-"\n" keeps the final newline byte-identical.
        if ($a->height !== null) {
            $lines = explode("\n", $frame);
            if (count($lines) > $a->height) {
                $lines = array_slice($lines, 0, $a->height);
            }
            $frame = implode("\n", $lines);
        }

        return $frame . "\n";
    }

    private static function statusPane(App $a): string
    {
        [$start, $shown, $above, $below] = self::rowWindow(count($a->status), $a->statusCursor, $a->statusBodyRows());
        $rows = [];
        if ($above > 0) {
            $rows[] = self::scrollHint('+' . $above . ' above');
        }
        foreach (array_slice($a->status, $start, $shown, true) as $i => $row) {
            $idx  = $row['index_status'] ?? ' ';
            $work = $row['work_status']  ?? ' ';
            $path = self::truncate(self::sanitize($row['path'] ?? ''), $a->statusPathWidth());
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
        if ($below > 0) {
            $rows[] = self::scrollHint('+' . $below . ' below');
        }
        return self::frame($a, Pane::Status, ' status ', implode("\n", $rows), $a->paneWidth());
    }

    private static function branchesPane(App $a): string
    {
        [$start, $shown, $above, $below] = self::rowWindow(count($a->branches), $a->branchesCursor, $a->branchesBodyRows());
        $rows = [];
        if ($above > 0) {
            $rows[] = self::scrollHint('+' . $above . ' above');
        }
        foreach (array_slice($a->branches, $start, $shown, true) as $i => $b) {
            $marker = $b['current'] ? '* ' : '  ';
            $line   = $marker . self::truncate(self::sanitize($b['name']), $a->branchNameWidth());
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
        if ($below > 0) {
            $rows[] = self::scrollHint('+' . $below . ' below');
        }
        return self::frame($a, Pane::Branches, ' branches ', implode("\n", $rows), $a->paneWidth());
    }

    private static function logPane(App $a): string
    {
        [$start, $shown, $above, $below] = self::rowWindow(count($a->log), $a->logCursor, $a->logBodyRows());
        $rows = [];
        if ($above > 0) {
            $rows[] = self::scrollHint('+' . $above . ' above');
        }
        foreach (array_slice($a->log, $start, $shown, true) as $i => $entry) {
            $sha     = Style::new()->foreground(Color::hex('#fde68a'))->render($entry['sha']);
            $subject = self::sanitize($entry['subject']);
            $subjectMax = $a->logSubjectWidth();
            // Cell-width aware: mb_strlen counts codepoints, so CJK subjects
            // (double-width) overran the pane budget (audit #14).
            if (Width::of($subject) > $subjectMax) {
                $subject = Width::truncate($subject, max(0, $subjectMax - 1)) . '…';
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
        if ($below > 0) {
            $rows[] = self::scrollHint('+' . $below . ' below');
        }
        return self::frame($a, Pane::Log, ' log ', implode("\n", $rows), $a->paneWidth());
    }

    private static function truncate(string $s, int $max): string
    {
        if (Width::of($s) <= $max) {
            return $s;
        }
        return Width::truncate($s, max(0, $max - 1)) . '…';
    }

    /**
     * Row window keeping $cursor visible in $visible rows of a $count-long
     * list, reserving one row per hidden side for the "+N above/below"
     * indicators. $visible = PHP_INT_MAX (size never reported) shows
     * everything — the historical byte-stable frame (audit K3).
     *
     * @return array{int,int,int,int} start offset, row count, hidden above, hidden below
     */
    private static function rowWindow(int $count, int $cursor, int $visible): array
    {
        if ($count === 0 || $visible >= $count) {
            return [0, $count, 0, 0];
        }
        $cursor = max(0, min($cursor, $count - 1));
        if ($visible <= 1) {
            // One row only: the cursor row wins, indicators cost it a row.
            return [$cursor, 1, 0, 0];
        }
        if ($visible === 2) {
            // Both rows go to content; even a single indicator would push
            // the printed block over budget, so no labels are carried.
            return [max(0, min($cursor, $count - 2)), 2, 0, 0];
        }

        // Three rows or more: reserve one per indicator, and a flush side
        // frees its reserved row for list content.
        $body = $visible - 2;
        $start = max(0, min($cursor - intdiv($body, 2), $count - $body));
        $above = $start;
        $below = $count - $start - $body;
        if ($above === 0) {
            $body = $visible - 1;
            $below = $count - $body;
        } elseif ($below <= 0) {
            $start = $count - $body;
            $above = $start;
            $below = 0;
        }

        return [$start, $body, $above, $below];
    }

    /** Dim scroll indicator ("+12 below") for windowed lists. */
    private static function scrollHint(string $text): string
    {
        return Style::new()->foreground(Color::hex('#7d6e98'))->italic()
            ->render(' ' . $text);
    }

    /**
     * Overlay box width: the historical constant is a ceiling, not a floor —
     * a narrow terminal shrinks the box instead of overflowing it (audit A26).
     */
    private static function overlayWidth(App $a, int $max): int
    {
        if ($a->width === null) {
            return $max;
        }
        // The style box spends 2 columns on the rounded border and 2 more on
        // the horizontal padding OUTSIDE the given content width (measured:
        // width(58) prints a 62-cell border row), so the content budget is
        // terminal width minus 6 (A26, audit 2026-11).
        return max(16, min($max, $a->width - 6));
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
            ->width(self::overlayWidth($a, 52))
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
        $titleBox = self::overlayWidth($a, 72);
        $lines[] = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render(' diff: ' . self::truncate(self::sanitize($dv->path), max(6, $titleBox - 10)) . ' ');

        // Syntax pass: accent recognised-language tokens inside diff
        // bodies. '' language (or an unaccentable line) keeps the plain
        // whole-line rendering below byte-for-byte (DiffHighlighter opt-in law).
        $language = DiffHighlighter::languageForPath($dv->path);

        // K3 (audit 2026-11): window the diff body to the terminal height —
        // every line used to be appended below the frame where nothing could
        // reach it. The selected hunk's first line (the cursor) stays visible;
        // lines clip to the box width (A26). The 3 reserved rows are the
        // title, the navigation hint, and one scroll indicator at most.
        $boxW = self::overlayWidth($a, 72);
        $inner = max(10, $boxW - 5);
        [$winStart, $winShown, $above, $below] = self::rowWindow(
            count($dv->lines),
            (int) $dv->hunkCursor,
            max(1, $a->overlayBodyRows() - 3),
        );
        if ($above > 0) {
            $lines[] = self::scrollHint('+' . $above . ' above');
        }

        // The selected-hunk bounds are loop-invariant; hoist them out (A27).
        $selStart = -1;
        $selEnd = -1;
        $hunkIdx = array_search($dv->hunkCursor, $dv->hunkStarts, true);
        if ($hunkIdx !== false) {
            $selStart = (int) $dv->hunkCursor;
            $selEnd = $hunkIdx + 1 < count($dv->hunkStarts)
                ? $dv->hunkStarts[$hunkIdx + 1] - 1
                : count($dv->lines) - 1;
        }

        foreach (array_slice($dv->lines, $winStart, $winShown, true) as $i => $line) {
            $isSelectedHunkLine = ($i >= $selStart && $i <= $selEnd);

            $st = Style::new();
            $line = self::truncate(self::sanitize($line), $inner);
            if (str_starts_with($line, '+') === TRUE) {
                $st = $st->foreground(Color::hex('#6ee7b7'));
            } elseif (str_starts_with($line, '-') === TRUE) {
                $st = $st->foreground(Color::hex('#ff5f87'));
            } elseif (str_starts_with($line, '@@') === TRUE) {
                $st = $st->foreground(Color::hex('#a78bfa'))->italic();
            } else {
                $st = $st->foreground(Color::hex('#c5b6dd'));
            }

            if ($isSelectedHunkLine) {
                $st = $st->reverse();
            }

            $spans = DiffHighlighter::tokenizeLine($line, $language);
            if ($spans === null) {
                $lines[] = $st->render(' ' . $line);
                continue;
            }

            // Marker (+/-/space) and unclassified content keep the diff-state
            // colour; only recognised tokens steal the accent.
            $row = $st->render(' ' . $line[0]);
            foreach ($spans as $span) {
                $spanStyle = $span->kind === TokenKind::Plain
                    ? $st
                    : self::syntaxAccent($span->kind, $isSelectedHunkLine);
                $row .= $spanStyle->render($span->text);
            }
            $lines[] = $row;
        }
        if ($below > 0) {
            $lines[] = self::scrollHint('+' . $below . ' below');
        }

        $hint = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render(Lang::t('diff.navigation_hint'));

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->padding(0, 1)
            ->width($boxW)
            ->render(implode("\n", $lines) . "\n" . $hint);

        return "\n" . $box . "\n";
    }

    /**
     * Accent style for one recognised syntax token inside a diff body line.
     * Hues extend the overlay palette (amber/violet/dim already frame the
     * diff); Plain is never asked for — the caller keeps the line-state
     * colour for unclassified text. Exhaustive over TokenKind by design.
     */
    private static function syntaxAccent(TokenKind $kind, bool $selected): Style
    {
        $style = match ($kind) {
            TokenKind::Keyword => Style::new()->foreground(Color::hex('#89b4fa')),
            TokenKind::StringToken => Style::new()->foreground(Color::hex('#fde68a')),
            TokenKind::Number => Style::new()->foreground(Color::hex('#a78bfa')),
            TokenKind::Comment => Style::new()->foreground(Color::hex('#7d6e98'))->italic(),
            TokenKind::Plain => Style::new()->foreground(Color::hex('#c5b6dd')),
        };

        return $selected === TRUE ? $style->reverse() : $style;
    }

    private static function rebaseOverlay(App $a): string
    {
        $cherry = $a->rebaseMenuKind === 'cherry';
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#a78bfa'))
                ->render($cherry ? ' cherry-pick ' : ' rebase '),
            '',
            Style::new()->foreground(Color::hex('#c5b6dd'))
                ->render(Lang::t($cherry ? 'rebase.cherry_prompt' : 'rebase.prompt')),
            '',
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('c') . '  ' . Lang::t('rebase.continue'),
            '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('a') . '  ' . Lang::t('rebase.abort'),
        ];
        if (!$cherry) {
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('s') . '  ' . Lang::t('rebase.skip');
        }
        $lines[] = '';
        $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . Lang::t('rebase.cancel');

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width(self::overlayWidth($a, 40))
            ->render(implode("\n", $lines));

        return "\n\n" . $box . "\n";
    }

    private static function stashOverlay(App $a): string
    {
        $sm = $a->stashManager;
        if ($sm === null) return '';

        $lines = [
            Style::new()->bold()->foreground(Color::hex('#a78bfa'))->render(' stashes '),
            '',
        ];

        // K3 (audit 2026-11): window the entries list to the terminal height
        // (9 = title, blanks, and the fixed action footer) and clip lines to
        // the box width (A26).
        $boxW = self::overlayWidth($a, 64);
        $inner = max(10, $boxW - 5);
        if ($sm->stashes === []) {
            $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))->render(Lang::t('stash.empty'));
        } else {
            [$start, $shown, $above, $below] = self::rowWindow(
                count($sm->stashes), $sm->cursor, max(1, $a->overlayBodyRows() - 9)
            );
            if ($above > 0) {
                $lines[] = self::scrollHint('+' . $above . ' above');
            }
            foreach (array_slice($sm->stashes, $start, $shown, true) as $i => $entry) {
                $line = self::truncate(self::sanitize($entry->displayLine()), $inner);
                $st = Style::new()->foreground(Color::hex('#c5b6dd'));
                if ($i === $sm->cursor) {
                    $st = $st->reverse()->foreground(Color::hex('#fde68a'));
                }
                $lines[] = '  ' . $st->render($line);
            }
            if ($below > 0) {
                $lines[] = self::scrollHint('+' . $below . ' below');
            }
        }

        $lines[] = '';
        $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('a') . '  ' . Lang::t('stash.apply');
        $lines[] = '  ' . Style::new()->foreground(Color::hex('#ff5f87'))->render('d') . '  ' . Lang::t('stash.drop');
        $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('j/k') . '  ' . Lang::t('help.move_cursor');
        $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . Lang::t('help.close_help');

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width($boxW)
            ->render(implode("\n", $lines));

        return "\n" . $box . "\n";
    }

    private static function worktreeOverlay(App $a): string
    {
        $wt = $a->worktrees;
        if ($wt === null) return '';

        // K3/A26: window the worktree list and clip paths to the box width.
        $boxW = self::overlayWidth($a, 64);
        $inner = max(10, $boxW - 5);
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#a78bfa'))->render(' worktrees '),
            '',
        ];

        if ($wt->adding === TRUE) {
            $lines[] = Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('worktree.add_prompt'));
            $lines[] = '  path: ' . Style::new()->foreground(Color::hex('#fde68a'))->render(self::truncate(self::sanitize($wt->newPath), $inner - 8)) . '_';
            $lines[] = '  branch: ' . Style::new()->foreground(Color::hex('#fde68a'))->render(self::truncate(self::sanitize($wt->newBranch ?: 'HEAD'), $inner - 10)) . '_';
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('enter') . '  ' . Lang::t('worktree.confirm_add');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('c') . '  ' . Lang::t('help.close_help');
        } elseif ($wt->removing === TRUE) {
            $current = $wt->current();
            $lines[] = Style::new()->foreground(Color::hex('#ff5f87'))->render(Lang::t('worktree.remove_confirm', ['path' => self::truncate(self::sanitize($current?->path ?? ''), $inner)]));
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#ff5f87'))->render('enter') . '  ' . Lang::t('worktree.confirm_remove');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('c') . '  ' . Lang::t('help.close_help');
        } else {
            if ($wt->worktrees === []) {
                $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))->render(Lang::t('worktree.empty'));
            } else {
                [$start, $shown, $above, $below] = self::rowWindow(
                    count($wt->worktrees), $wt->cursor, max(1, $a->overlayBodyRows() - 9)
                );
                if ($above > 0) {
                    $lines[] = self::scrollHint('+' . $above . ' above');
                }
                foreach (array_slice($wt->worktrees, $start, $shown, true) as $i => $entry) {
                    $line = self::truncate(self::sanitize($entry->path), $inner);
                    if ($entry->branch !== '') {
                        $line .= ' (' . self::sanitize($entry->branch) . ')';
                    }
                    $st = Style::new()->foreground(Color::hex('#c5b6dd'));
                    if ($i === $wt->cursor) {
                        $st = $st->reverse()->foreground(Color::hex('#fde68a'));
                    }
                    $lines[] = '  ' . $st->render($line);
                }
                if ($below > 0) {
                    $lines[] = self::scrollHint('+' . $below . ' below');
                }
            }
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('a') . '  ' . Lang::t('worktree.add');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#ff5f87'))->render('d') . '  ' . Lang::t('worktree.remove');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('j/k') . '  ' . Lang::t('help.move_cursor');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . Lang::t('help.close_help');
        }

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width($boxW)
            ->render(implode("\n", $lines));

        return "\n" . $box . "\n";
    }

    private static function interactiveRebaseOverlay(App $a): string
    {
        $ir = $a->interactiveRebase;
        if ($ir === null) return '';

        // K3/A26: window the todo list to the terminal height and clip
        // subjects to the box width.
        $boxW = self::overlayWidth($a, 72);
        $inner = max(10, $boxW - 5);

        $lines = [
            Style::new()->bold()->foreground(Color::hex('#a78bfa'))->render(' interactive rebase '),
            '',
        ];

        if ($ir->selectingN === TRUE) {
            $lines[] = Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('rebase_i.select_count'));
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#fde68a'))->render('n = ' . ($ir->countInput !== '' ? $ir->countInput : '_'));
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('enter') . '  ' . Lang::t('rebase_i.confirm_count');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . Lang::t('help.close_help');
        } else {
            $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))->render(Lang::t('rebase_i.preview_only'));
            if ($ir->commits === []) {
                $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))->render(Lang::t('rebase_i.no_commits'));
            } else {
                $lines[] = Style::new()->foreground(Color::hex('#c5b6dd'))->render(Lang::t('rebase_i.todo_list'));
                [$start, $shown, $above, $below] = self::rowWindow(
                    count($ir->commits), $ir->cursor, max(1, $a->overlayBodyRows() - 10)
                );
                if ($above > 0) {
                    $lines[] = self::scrollHint('+' . $above . ' above');
                }
                foreach (array_slice($ir->commits, $start, $shown, true) as $i => $commit) {
                    $actionColor = match ($commit->action) {
                        RebaseAction::Pick => '#6ee7b7',
                        RebaseAction::Reword => '#fde68a',
                        RebaseAction::Edit => '#a78bfa',
                        RebaseAction::Squash => '#fde68a',
                        RebaseAction::Drop => '#ff5f87',
                    };
                    // Pad the PLAIN action text to 8 cells before colorizing:
                    // sprintf %-8s counted the SGR bytes of the rendered string
                    // as content, so every column collapsed (audit #14).
                    $line = '  '
                        . Style::new()->foreground(Color::hex($actionColor))->render(Width::padRight($commit->action->value, 8))
                        . ' '
                        . Style::new()->foreground(Color::hex('#fde68a'))->render($commit->sha)
                        . ' '
                        . self::truncate(self::sanitize($commit->subject), max(1, $inner - 17));
                    if ($i === $ir->cursor) {
                        $line = Style::new()->reverse()->render($line);
                    }
                    $lines[] = $line;
                }
                if ($below > 0) {
                    $lines[] = self::scrollHint('+' . $below . ' below');
                }
            }
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#6ee7b7'))->render('space/l') . '  ' . Lang::t('rebase_i.cycle_action');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#ff5f87'))->render('d') . '  ' . Lang::t('rebase_i.drop');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('j/k') . '  ' . Lang::t('help.move_cursor');
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render('esc') . '  ' . Lang::t('help.close_help');
        }

        if ($ir->error !== null) {
            $lines[] = '';
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#ff5f87'))->render(self::sanitize($ir->error));
        }

        $border = Border::rounded();
        $box = Style::new()
            ->border($border)
            ->borderForeground(Color::hex('#a78bfa'))
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width($boxW)
            ->render(implode("\n", $lines));

        return "\n" . $box . "\n";
    }
}
