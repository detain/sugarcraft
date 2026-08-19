<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolResult;

/**
 * The shell's tool-activity sidebar, fed from the hosted {@see
 * \SugarCraft\Crush\Chat}'s transcript (crush_feat.md §1 E6: "wire
 * `ToolsPane::render()` to read from `Chat::history` and actually reach it
 * from the live path" — the wire-it branch of that recommendation, taken
 * because §5 E7's MERGE branch keeps the pane layer alive).
 *
 * A finished call reaches the transcript as a message carrying {@see
 * ToolResult}s; a call still executing is a {@see
 * \SugarCraft\Crush\Message::toolRunning()} placeholder, which carries no
 * result yet and is recognised by its `pendingToolCallId`.
 *
 * Every label drawn here is model- or tool-authored, so it crosses
 * {@see PaneLabel::of()} before reaching the terminal.
 */
final class ToolsPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    public static function render(App $a, int $width, int $rows): string
    {
        $theme = $a->theme();
        $entries = self::recentCalls($a, max(1, $rows - self::CHROME_ROWS), $theme);

        if ($entries === []) {
            $body = Style::new()->foreground($theme->shellMuted)
                ->render('(tool history empty)');
        } else {
            $lines = [];
            foreach ($entries as [$label, $color]) {
                $lines[] = Style::new()
                    ->foreground($color)
                    ->render(Width::truncate($label, max(1, $width - self::CHROME_COLS)));
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' tools '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Tools
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return $st->render($body);
    }

    /**
     * At most $budget tool rows, NEWEST first.
     *
     * Bounded and reversed for the same two reasons {@see
     * FilesPane::recentFiles()} documents: the walk must not cost more as the
     * session grows (it runs once per frame, i.e. per keystroke), and {@see
     * \SugarCraft\Crush\Tui\Renderer} clips an over-tall sidebar from the
     * BOTTOM (`clipHead()` keeps the top), so the rows that survive clipping
     * have to be the recent ones.
     *
     * @return list<array{0: string, 1: \SugarCraft\Core\Util\Color}> [label, colour]
     */
    private static function recentCalls(App $a, int $budget, Theme $theme): array
    {
        $entries = [];
        $history = $a->chat?->history ?? [];

        for ($i = count($history) - 1; $i >= 0 && count($entries) < $budget; $i--) {
            $message = $history[$i];

            // A placeholder stands in for a call that has not returned yet;
            // its content is the same model-authored one-liner the transcript
            // shows (see Message::describeToolCall()).
            if ($message->pendingToolCallId !== null) {
                $entries[] = ['◌ ' . PaneLabel::of($message->content), $theme->shellWarning];

                continue;
            }

            foreach (array_reverse($message->toolResults) as $result) {
                if (!$result instanceof ToolResult) {
                    continue;
                }
                $name = PaneLabel::of($result->name);
                $entries[] = $result->isError()
                    ? ['✖ ' . $name . ' — ' . PaneLabel::of((string) $result->error), $theme->shellError]
                    : ['✔ ' . $name, $theme->shellSuccess];
                if (count($entries) >= $budget) {
                    break;
                }
            }
        }

        return $entries;
    }
}
