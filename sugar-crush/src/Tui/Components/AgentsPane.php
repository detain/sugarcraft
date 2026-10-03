<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\AgentStatusBar;

/**
 * The shell's agents sidebar — the docked Agents slot's widget.
 *
 * Rows come from {@see AgentDashboardPane::entries()}, the single ordering
 * authority every agents surface shares: registered and projected agents
 * first, then background sessions, so a Task-tool delegation (mirrored into
 * the parent's AgentManager by the SubAgentActivity frame channel) and a
 * `/bg` session both appear here without this class knowing which is which.
 * Each row is the dashboard's own {@see AgentStatusBar::renderAgentLine()},
 * truncated to the sidebar's content width — the two widgets disagree only
 * in budget, never in data or vocabulary.
 *
 * This REPLACED the original hardcoded "(no active agents)" stub, which
 * could not be told apart from an empty dashboard on a session with three
 * delegations running: an always-false box on a live surface is worse than
 * no box, because it teaches the user to ignore the pane. The literal
 * survives as the honest EMPTY state, shared with AgentViewPane.
 */
final class AgentsPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    /** The {@see App::paneScroll()} id this pane scrolls under. */
    public const SCROLL_ID = 'pane:agents';

    /** Lines of a run's latest activity an expanded row shows. */
    private const DETAIL_LINES = 6;

    public static function render(App $a, int $width, int $rows): string
    {
        return self::layout($a, $width, $rows)[0];
    }

    /**
     * The pane, and the click key of each BODY line: a run's id on its row
     * head (`siderow:agents:<id>` once the shell zones it), null elsewhere.
     * An expanded run shows the tail of its activity under its row.
     *
     * The third slot is the furthest the pane can be scrolled, which the
     * wheel clamps against.
     *
     * @return array{0: string, 1: list<?string>, 2: int}
     */
    public static function layout(App $a, int $width, int $rows): array
    {
        $theme = $a->theme();
        $entries = AgentDashboardPane::entries($a);
        $muted = Style::new()->foreground($theme->shellMuted);
        $keys = [];
        $maxOffset = max(0, count($entries) - 1);

        if ($entries === []) {
            $body = $muted->render('(no active agents)');
            $keys[] = null;
        } else {
            // Same row-truncation idiom the dashboard applies at its own
            // width: measure the finished ANSI line, cut only when it
            // overflows, so the dot/name/status survive a 34-column side.
            $budget = max(1, $rows - self::CHROME_ROWS);
            $inner = max(1, $width - self::CHROME_COLS);
            $offset = min($a->paneScroll(self::SCROLL_ID), max(0, count($entries) - 1));
            $lines = [];
            if ($offset > 0) {
                $lines[] = $muted->render(Width::truncate('↑ ' . $offset . ' more', $inner));
                $keys[] = null;
            }

            $shown = 0;
            foreach (array_slice($entries, $offset) as $index => $entry) {
                // The trailer costs a row of the budget, never one past it:
                // a trailer added on top made the box one row taller than
                // its slot.
                $remaining = count($entries) - $offset - $index;
                if (count($lines) >= $budget - ($remaining > 1 ? 1 : 0)) {
                    break;
                }
                $line = AgentStatusBar::renderAgentLine($entry, $theme);
                $lines[] = Width::string($line) > $inner ? Width::truncateAnsi($line, $inner) : $line;
                $keys[] = $entry->key;
                $shown++;

                if ($entry->key === null || !$a->isAgentExpanded($entry->key)) {
                    continue;
                }
                $detail = $entry->outputBuffer === [] ? ['(no activity yet)'] : array_slice($entry->outputBuffer, -self::DETAIL_LINES);
                foreach ($detail as $text) {
                    if (count($lines) >= $budget - 1) {
                        break;
                    }
                    $lines[] = $muted->render(Width::truncate('  ' . PaneLabel::safe($text), $inner));
                    $keys[] = null;
                }
            }

            $hidden = count($entries) - $offset - $shown;
            if ($hidden > 0) {
                $lines[] = $muted->render('… +' . $hidden . ' more');
                $keys[] = null;
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . \SugarCraft\Crush\Tui\Pane::Agents->icon() . ' agents '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Agents
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return [PaneFrame::render($st, $body, $theme->border), $keys, $maxOffset];
    }
}
