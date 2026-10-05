<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
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
 * `Task` delegations themselves are left out — each run is listed by the
 * Agents pane ({@see AgentsPane}) — but the tool calls those runs make are
 * listed here, beside the session's own, labelled with their agent.
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

    /** The {@see App::paneScroll()} id this pane scrolls under. */
    public const SCROLL_ID = 'pane:tools';

    /** Lines of a call's output an expanded row shows. */
    private const DETAIL_OUTPUT_LINES = 5;

    /** Arguments an expanded row lists. */
    private const DETAIL_ARGUMENTS = 3;

    public static function render(App $a, int $width, int $rows): string
    {
        return self::layout($a, $width, $rows)[0];
    }

    /**
     * The pane, and the click key of each BODY line (null where a line is
     * not a row head) — what the shell turns into `siderow:tools:<key>`
     * click zones at the pane's position. A key is the transcript's own
     * expansion key ({@see \SugarCraft\Crush\Chat::expanded()}), so a click
     * here and a click on the transcript row open the same call.
     *
     * The third slot is the furthest the pane can be scrolled (rows of
     * entries past the first), which the wheel clamps against.
     *
     * @return array{0: string, 1: list<?string>, 2: int}
     */
    public static function layout(App $a, int $width, int $rows): array
    {
        $theme = $a->theme();
        $budget = max(1, $rows - self::CHROME_ROWS);
        $inner = max(1, $width - self::CHROME_COLS);
        $offset = $a->paneScroll(self::SCROLL_ID);
        // One entry past the window is fetched so the wheel knows there is
        // more below; the walk stays bounded by what is on screen.
        $entries = self::recentCalls($a, $budget + $offset + 1, $theme);
        $maxOffset = max(0, count($entries) - 1);
        $offset = min($offset, $maxOffset);
        $entries = array_slice($entries, $offset);
        $expanded = $a->chat?->expanded() ?? [];
        $muted = Style::new()->foreground($theme->shellMuted);

        $lines = [];
        $keys = [];
        if ($entries === []) {
            $lines[] = $muted->render('(tool history empty)');
            $keys[] = null;
        } else {
            if ($offset > 0) {
                $lines[] = $muted->render(Width::truncate('↑ ' . $offset . ' newer', $inner));
                $keys[] = null;
            }
            foreach ($entries as [$label, $color, $key, $detail]) {
                if (count($lines) >= $budget) {
                    break;
                }
                $lines[] = Style::new()->foreground($color)->render(Width::truncate($label, $inner));
                $keys[] = $key;
                // `=== true`: under `expandToolOutput` a `false` entry is a
                // call the user closed (Chat::isToolOutputExpanded()).
                if ($key === null || ($expanded[$key] ?? false) !== true) {
                    continue;
                }
                foreach ($detail as $line) {
                    if (count($lines) >= $budget) {
                        break;
                    }
                    $lines[] = $muted->render(Width::truncate('  ' . $line, $inner));
                    $keys[] = null;
                }
            }
        }
        $body = implode("\n", $lines);

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' ' . \SugarCraft\Crush\Tui\Pane::Tools->icon() . ' tools '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Tools
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return [PaneFrame::render($st, $body, $theme->border), $keys, $maxOffset];
    }

    /**
     * What an expanded row shows under its head: the call's arguments, then
     * the head of its output (or its error) — bounded, one row each, every
     * value through {@see PaneLabel::of()}.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    private static function detail(array $arguments, string $output): array
    {
        $lines = [];
        foreach (array_slice($arguments, 0, self::DETAIL_ARGUMENTS, true) as $name => $value) {
            if ($name === 'description') {
                continue;
            }
            $shown = is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
            $lines[] = PaneLabel::of((string) $name) . ': ' . PaneLabel::of($shown);
        }

        $out = array_values(array_filter(
            explode("\n", str_replace("\r", '', $output)),
            static fn (string $l): bool => trim($l) !== '',
        ));
        foreach (array_slice($out, 0, self::DETAIL_OUTPUT_LINES) as $line) {
            $lines[] = '│ ' . PaneLabel::of($line);
        }
        if (count($out) > self::DETAIL_OUTPUT_LINES) {
            $lines[] = '… +' . (count($out) - self::DETAIL_OUTPUT_LINES) . ' lines';
        }

        return $lines === [] ? ['(no output)'] : $lines;
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
     * @return list<array{0: string, 1: \SugarCraft\Core\Util\Color, 2: ?string, 3: list<string>}> [label, colour, click key, expanded detail]
     */
    private static function recentCalls(App $a, int $budget, Theme $theme): array
    {
        $own = self::sessionCalls($a, $budget, $theme);
        $delegated = self::delegatedCalls($a, $budget, $theme);
        if ($delegated === []) {
            return array_map(static fn (array $e): array => [$e[0], $e[1], $e[4], $e[5]], $own);
        }

        // One list for the one pane: the session's calls and every run's,
        // newest first by when each started. A tie keeps the session's call
        // first — at one-second resolution a run's call and the Task call
        // that launched it routinely share a timestamp.
        $merged = [...$own, ...$delegated];
        // Rank 0 is a session call; a run's calls rank 1.. in call order.
        usort($merged, static fn (array $x, array $y): int => [$y[2], $x[3] === 0 ? 0 : 1, $y[3]] <=> [$x[2], $y[3] === 0 ? 0 : 1, $x[3]]);

        return array_map(
            static fn (array $e): array => [$e[0], $e[1], $e[4], $e[5]],
            array_slice($merged, 0, $budget),
        );
    }

    /**
     * The session's own calls, newest first, each with when it happened and
     * a tie-break rank — see {@see recentCalls()}.
     *
     * @return list<array{0: string, 1: \SugarCraft\Core\Util\Color, 2: int, 3: int, 4: ?string, 5: list<string>}>
     */
    private static function sessionCalls(App $a, int $budget, Theme $theme): array
    {
        $entries = [];
        $history = $a->chat?->history ?? [];

        for ($i = count($history) - 1; $i >= 0 && count($entries) < $budget; $i--) {
            $message = $history[$i];

            // A placeholder stands in for a call that has not returned yet;
            // its content is the same model-authored one-liner the transcript
            // shows (see Message::describeToolCall()).
            if ($message->pendingToolCallId !== null) {
                if ($message->pendingToolName === TaskTool::NAME) {
                    continue;
                }
                $entries[] = [
                    '◌ ' . PaneLabel::of($message->content), $theme->shellWarning, $message->createdAt, 0,
                    $message->pendingToolCallId, self::detail($message->pendingToolArguments, ''),
                ];

                continue;
            }

            foreach (array_reverse($message->toolResults) as $result) {
                if (!$result instanceof ToolResult || $result->name === TaskTool::NAME) {
                    continue;
                }
                $name = self::label($result);
                $key = $result->id ?? $result->name;
                $detail = self::detail($result->arguments, $result->isError() ? (string) $result->error : $result->result);
                $entries[] = $result->isError()
                    ? ['✖ ' . $name . ' — ' . PaneLabel::of((string) $result->error), $theme->shellError, $message->createdAt, 0, $key, $detail]
                    : ['✔ ' . $name, $theme->shellSuccess, $message->createdAt, 0, $key, $detail];
                if (count($entries) >= $budget) {
                    break;
                }
            }
        }

        return $entries;
    }

    /**
     * The tool calls of every delegated run the session knows of, labelled
     * with the agent that made them (`reviewer › Read(path: "x")`) — the
     * session has one Tools pane, so a sub-agent's calls belong in it too.
     * At most $budget of the newest, so the per-frame cost stays bounded by
     * the pane, not by how many runs a session has had.
     *
     * @return list<array{0: string, 1: \SugarCraft\Core\Util\Color, 2: int, 3: int, 4: ?string, 5: list<string>}>
     */
    private static function delegatedCalls(App $a, int $budget, Theme $theme): array
    {
        $manager = $a->chat?->agentManager();
        if ($manager === null) {
            return [];
        }

        $entries = [];
        foreach ($manager->runs() as $run) {
            $agent = PaneLabel::of($run->agent->name);
            foreach ($run->recentCalls as $order => $call) {
                $label = $agent . ' › ' . PaneLabel::of($call['label']);
                // Not clickable: a run's calls cross the wire as labels only,
                // so there is nothing more to expand them into.
                $entries[] = match ($call['state']) {
                    SubAgentActivity::CALL_RUNNING => ['◌ ' . $label, $theme->shellWarning, $call['at'], 1 + $order, null, []],
                    SubAgentActivity::CALL_ERROR => ['✖ ' . $label, $theme->shellError, $call['at'], 1 + $order, null, []],
                    default => ['✔ ' . $label, $theme->shellSuccess, $call['at'], 1 + $order, null, []],
                };
            }
        }

        usort($entries, static fn (array $x, array $y): int => [$y[2], $y[3]] <=> [$x[2], $x[3]]);

        return array_slice($entries, 0, $budget);
    }

    /**
     * A finished call's label: what it did, not only which tool — the same
     * one-liner the transcript's row carries ({@see ToolResult::$description}),
     * prefixed with the tool's name when the one-liner is the model's own
     * summary (`Bash: List files`) rather than an argument dump that already
     * names it (`Read(path: "src/x.php")`). A result with no description
     * falls back to the bare name, as every row did before.
     */
    private static function label(ToolResult $result): string
    {
        $name = PaneLabel::of($result->name);
        $described = PaneLabel::of((string) $result->description);
        if ($described === '' || $described === $name) {
            return $name;
        }

        return str_starts_with($described, $name . '(') ? $described : $name . ': ' . $described;
    }
}
