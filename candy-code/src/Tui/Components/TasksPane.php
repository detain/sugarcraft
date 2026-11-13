<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Code\App\App;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Commands\TasksCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\ScreenReaderMode;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * The dock's Tasks pane: the background work this session has running, the
 * same list `/tasks` prints — shells, sessions and sub-agent runs, each row
 * with its state and age.
 *
 * READ, NEVER RUN. `view()` does no I/O beyond the in-memory registries, and
 * the rows come from {@see TasksCommand::groups()}, the one assembly the
 * command also reads, so the pane and `/tasks` cannot disagree. Nothing is
 * started, tailed or stopped from here: tail and stop stay on the command,
 * which is where a write is gated by the read-only session rule.
 */
final class TasksPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    public static function render(App $a, int $width, int $rows): string
    {
        $theme = $a->theme();
        $budget = max(1, $rows - self::CHROME_ROWS);
        $labelWidth = max(1, $width - self::CHROME_COLS);

        $lines = self::lines($a);
        $title = ' ' . ScreenReaderMode::paneIcon(Pane::Tasks) . ' ' . Lang::t('tui.pane.title.tasks') . ' ';

        if ($lines === []) {
            $body = Style::new()->foreground($theme->shellMuted)->render(RowElide::text(Lang::t('host.tasks.none'), $labelWidth));
        } else {
            $shown = \array_slice($lines, 0, $budget);
            if (\count($lines) > $budget && $budget > 1) {
                $shown = \array_slice($lines, 0, $budget - 1);
                $shown[] = Lang::t('tui.pane.more_below', ['count' => (string) (\count($lines) - \count($shown))]);
            }
            $body = implode("\n", array_map(
                static fn (string $line): string => self::styled($line, $theme, $labelWidth),
                $shown,
            ));
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === Pane::Tasks
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return PaneFrame::render($st, $body, $theme->border);
    }

    /**
     * The pane's lines, headings included, in the order `/tasks` prints them.
     *
     * @return list<string>
     */
    public static function lines(App $a): array
    {
        $chat = $a->chat;
        $owner = $chat?->currentSessionId() ?? $a->sessionId;
        $registry = $chat?->workspace()?->service(BackgroundShellRegistry::class);

        $groups = TasksCommand::groups(
            $registry instanceof BackgroundShellRegistry ? $registry : null,
            $chat?->backgroundSupervisor(),
            $chat?->agentLive(),
            $owner,
            \microtime(true),
        );

        $lines = [];
        foreach (['shells', 'sessions', 'agents'] as $kind) {
            if ($groups[$kind] === []) {
                continue;
            }
            $lines[] = TasksCommand::heading($kind);
            $lines = [...$lines, ...$groups[$kind]];
        }

        return $lines;
    }

    /** A heading in the emphasis colour, a row in the body colour, both elided to the pane. */
    private static function styled(string $line, \SugarCraft\Code\Theme $theme, int $labelWidth): string
    {
        $text = RowElide::text($line, $labelWidth);
        if (!str_starts_with($line, ' ')) {
            return Style::new()->foreground($theme->shellPrimary)->bold()->render($text);
        }

        return Style::new()->foreground($theme->shellForeground)->render($text);
    }
}
