<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoReminder;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * The dock's Todo pane (roadmap 3.C): the agent's checklist for the session,
 * one row per item — ☑ done, ▸ in progress, ☐ pending, ☒ cancelled — with
 * the done count in the frame title.
 *
 * READ, NEVER FETCHED. `view()` does no I/O, so the list comes from what is
 * already in memory: the session's list as its
 * {@see TurnRunner} holds it ({@see TurnRunner::heldTodos()}, updated as each
 * `Todo` call's frame arrives — the list that survives compaction), else the
 * newest copy the transcript shows ({@see TodoReminder::latestIn()}), which
 * is how a chat with no workspace-registered runner, or a session resumed but
 * not yet run, still shows its list.
 *
 * Every glyph is display-width 1 and East Asian Width N
 * ({@see TodoStatus::glyph()}), and every row is truncated to the pane, so no
 * line ever measures wider than the box the frame budgets.
 */
final class TodoPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    /** Shown while the session has no list. */
    public const EMPTY_TEXT = '(no todo list yet)';

    public static function render(App $a, int $width, int $rows): string
    {
        $theme = $a->theme();
        $budget = max(1, $rows - self::CHROME_ROWS);
        $labelWidth = max(1, $width - self::CHROME_COLS);
        $todos = self::todos($a);

        $title = ' ' . Pane::Todo->icon() . ' todo ';
        if ($todos->isEmpty()) {
            $body = Style::new()->foreground($theme->shellMuted)->render(Width::truncate(self::EMPTY_TEXT, $labelWidth));
        } else {
            $title = ' ' . Pane::Todo->icon() . ' todo ' . $todos->countOf(TodoStatus::Completed) . '/' . $todos->count() . ' ';
            $items = $todos->items();

            // Keep the item being worked on in view on a list longer than
            // the pane — with the one before it for context — rather than
            // cutting the list where the work is.
            $anchor = null;
            $firstOpen = null;
            foreach ($items as $i => $item) {
                if ($item->status === TodoStatus::InProgress) {
                    $anchor = $i;
                    break;
                }
                if ($firstOpen === null && $item->status->isOpen()) {
                    $firstOpen = $i;
                }
            }
            $anchor ??= $firstOpen ?? 0;
            $offset = max(0, min($anchor - 1, \count($items) - $budget));

            $lines = [];
            foreach (\array_slice($items, $offset, $budget) as $item) {
                $style = match ($item->status) {
                    TodoStatus::InProgress => Style::new()->foreground($theme->shellPrimary)->bold(),
                    TodoStatus::Pending => Style::new()->foreground($theme->shellForeground),
                    TodoStatus::Completed, TodoStatus::Cancelled => Style::new()->foreground($theme->shellMuted),
                };
                $lines[] = $style->render(Width::truncate($item->status->glyph() . ' ' . $item->content, $labelWidth));
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === Pane::Todo
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return PaneFrame::render($st, $body, $theme->border);
    }

    /**
     * The list the pane shows for $a's session — see the class doc for the
     * order the two in-memory sources are read in.
     */
    public static function todos(App $a): TodoList
    {
        $chat = $a->chat;
        $runner = $chat?->workspace()?->service(TurnRunner::class);
        if ($runner instanceof TurnRunner) {
            $held = $runner->heldTodos($chat?->currentSessionId() ?? $a->sessionId);
            if ($held !== null) {
                return $held;
            }
        }

        return TodoReminder::latestIn($chat?->history ?? $a->messages) ?? TodoList::new();
    }
}
