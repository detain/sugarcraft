<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Chat;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Table\Table;

/**
 * The one place the column-layout decisions shared by the `echo`-shaped
 * commands live.
 *
 * {@see AgentsCommand} and {@see McpAuthCommand} both used to hand-build
 * columns with `strlen()` + `str_repeat(' ', max(1, N - $len))`. That is
 * BYTE length against a CELL grid, so any name carrying a multi-byte
 * character — and these are read out of on-disk agent presets and out of
 * `.mcp.json` server URLs, i.e. content this process did not write — pushed
 * its row's later columns left by one column position per extra byte. Every
 * cell here is measured with {@see Width}, which counts grapheme cell width
 * and strips ANSI.
 *
 * THREE CONSTRAINTS, and each one is why a knob below is deliberately NOT
 * turned on:
 *
 *  - **No styling.** These classes `echo`; {@see \SugarCraft\Crush\Chat}
 *    captures that with `ob_start()` and appends it to the transcript as an
 *    assistant message, which the TUI then renders. An escape emitted here
 *    surfaces as literal `[33m` text — the defect
 *    {@see \SugarCraft\Crush\Tests\Commands\NoRawAnsiInTranscriptTest}
 *    exists for. That test greps the SOURCE for an escape LITERAL, so a
 *    table styled at RUNTIME would sail straight past it while reintroducing
 *    exactly the bug it guards. Hence border only: no `styleFunc()`, no
 *    `Style`, no `Theme`.
 *
 *  - **No Markdown emphasis inside a cell.** The transcript renderer
 *    consumes `**…**` and re-emits the text bolded, which is FOUR cells
 *    narrower than what {@see Table} measured when it padded the column.
 *    Measured: a `**● active**` cell left its row four cells short and
 *    pushed the closing border out of line. Cells are plain text; see
 *    {@see McpAuthCommand::formatStatus()}.
 *
 *  - **A width cap that cannot fire.** {@see Table::width()} shrinks every
 *    column PROPORTIONALLY when the natural width overruns, which was
 *    measured clipping `2026-08-22 03:00` to `2026-08-22 03` and `● active`
 *    to `● activ` — a cap that mangles a column which had room. So the cap
 *    is DERIVED from the same per-column budgets the caller clips its cells
 *    to ({@see maxCells()}): route every cell through {@see cell()} and the
 *    natural width can never reach the cap, leaving `width()` a backstop
 *    against a caller that forgets rather than a layout mechanism.
 *
 * THE PANE WIDTH IS KNOWN, and an earlier revision of this docblock said it
 * was not. It claimed "these commands cannot know the pane width —
 * {@see \SugarCraft\Crush\Chat} carries none", and used that to defend
 * budgets that only kept the COMMON case inside a narrow pane. `Chat` carries
 * it: {@see \SugarCraft\Crush\Chat::$cols} is populated from
 * {@see \SugarCraft\Core\Msg\WindowSizeMsg} (and by
 * {@see \SugarCraft\Crush\Chat::withSize()}, whose own docblock says the
 * shell "hands the pane's inner geometry down through here"), and
 * {@see \SugarCraft\Crush\Chat::cols()} reads it with a
 * {@see \SugarCraft\Crush\Tui\Renderer::getTerminalSize()} fallback.
 * Both commands already hold the `Chat` — it is `execute()`'s first parameter.
 * {@see \SugarCraft\Crush\Chat::handleHelpCommand()} has derived a layout budget
 * from exactly that field for as long as it has existed. So the budgets below
 * are a STARTING POINT that {@see fit()} scales to the live pane, and the
 * transcript-wrap defect is bounded rather than merely made smaller.
 *
 * The pane the box must fit is not the terminal: {@see
 * \SugarCraft\Crush\Renderer::render()} wraps the transcript at
 * `max(20, $chat->cols() - Renderer::SHELL_CHROME_COLS)`, and
 * {@see CHROME_COLS} is that same 6. A row wider than that is HARD-wrapped
 * mid-row by the Markdown pass, which shreds the box into fragments — which
 * is the whole reason the fit is derived from the wrap width rather than from
 * the terminal width.
 */
final class TranscriptTable
{
    /**
     * Columns the transcript pane gives up to the shell's own chrome.
     *
     * The same 6 as {@see \SugarCraft\Crush\Renderer}'s `SHELL_CHROME_COLS`
     * — border 1 each side plus `padding(0, 1)` — because the number this
     * class must fit inside is literally the `$width` `Renderer::render()`
     * hands `renderHistory()`, which is `max(20, $chat->cols() - 6)`. Kept
     * here rather than reached for across the class boundary for the reason
     * `Chat::HELP_CHROME_COLS` gives: this side needs the number, not the
     * layout. {@see \SugarCraft\Crush\Tests\Commands\TranscriptTableTest}
     * pins it against `Renderer`'s so the two cannot drift apart silently.
     */
    public const CHROME_COLS = 6;

    /**
     * The pane width a table built for `$chat` must fit inside.
     *
     * Mirrors `Renderer::render()`'s own `max(20, cols() - SHELL_CHROME_COLS)`
     * rather than approximating it, so "fits the pane" means the same thing on
     * both sides of the `ob_start()` capture.
     */
    public static function paneWidth(Chat $chat): int
    {
        return max(20, $chat->cols() - self::CHROME_COLS);
    }

    /**
     * Scale $columns' budgets down until the table fits $paneWidth cells.
     *
     * THE DEFAULT FLOOR IS THE HEADER, not zero and not a magic minimum: a
     * column is never rendered narrower than its own header text —
     * {@see maxCells()} takes `max($budget, headerWidth)` for exactly that
     * reason — so shrinking a budget below its header buys no cells and only
     * makes the derived cap lie. Everything ABOVE the floor is shrinkable, and
     * it is shrunk in proportion to how much each column had to give, so a
     * wide free-text column absorbs the loss before a narrow one does. The
     * proportional-shrink defect {@see Table::width()} has is avoided the same
     * way it already was: the caller clips its cells to the RETURNED budgets,
     * so the natural width never reaches the cap.
     *
     * $floors IS WHY THE SHRINK IS NOT PURELY PROPORTIONAL, and it is the
     * whole difference between this and `Table::width()`. Some columns hold a
     * FIXED-FORMAT value that does not abbreviate: `2026-08-22 03:00` clipped
     * to `2026-08-22 0…` is not a shorter timestamp, it is a wrong one, and
     * `● expiring soon` clipped to `● expiring so…` is not a shorter status.
     * A caller raises those columns' floors and the loss lands entirely on the
     * free-text columns — a URL or a description — where `…` means what a
     * reader expects it to mean. MEASURED on `McpAuthCommand`'s four columns:
     *
     *   - 74-cell pane (an 80-column terminal): `Server` 20, `Status` 16,
     *     `Expires` 16, `Scopes` 9 — the whole 14-cell loss on the two
     *     free-text columns, neither floor even reached.
     *   - 60-cell pane: `Server` 10, `Status` 15, `Expires` 16, `Scopes` 6 —
     *     `Status` is down to its floor and `Expires` still has not moved,
     *     while a purely proportional split would have taken `Expires` to 13
     *     and rendered `2026-08-22 03:00` as `2026-08-22 0…`.
     *
     * A floor above its own column's budget is clamped down to that budget
     * rather than widening the table.
     *
     * When even the floors do not fit — a pane narrower than the floors plus
     * borders, which `max(20, …)` makes reachable at any terminal under about
     * 26 columns — the floors are returned unchanged. That is an over-wide
     * box, deliberately: the alternative is clipping the headers into nonsense,
     * and at that width every other box in the shell is over-wide too. It is
     * the same call {@see \SugarCraft\Crush\Chat::handleHelpCommand()} makes with
     * its own `max(20, …)` floor.
     *
     * @param  array<string, int> $columns    header text => cell budget
     * @param  int                $paneWidth  cells the whole table may occupy
     * @param  array<string, int> $floors     header text => cells that column may not drop below
     * @return array<string, int> the same keys, with budgets that fit
     */
    public static function fit(array $columns, int $paneWidth, array $floors = []): array
    {
        if ($columns === []) {
            return [];
        }

        if (self::maxCells($columns) <= $paneWidth) {
            return $columns;
        }

        $natural = [];
        $bounds = [];
        foreach ($columns as $header => $budget) {
            $natural[$header] = max($budget, Width::string((string) $header));
            $bounds[$header] = min(
                $natural[$header],
                max(Width::string((string) $header), $floors[$header] ?? 0),
            );
        }
        $floors = $bounds;

        $overhead = 3 * \count($columns) + 1;
        $content = $paneWidth - $overhead;
        $floorSum = array_sum($floors);

        if ($content <= $floorSum) {
            return $floors;
        }

        $slack = $content - $floorSum;

        // Cannot be zero here, and the invariant is worth writing down because
        // it is the divisor: $excess === 0 means every column is already at
        // its floor, i.e. array_sum($natural) === $floorSum, which with the
        // guard above would mean maxCells() <= $paneWidth and the early return
        // one block up would have fired.
        $excess = array_sum($natural) - $floorSum;

        $fitted = [];
        foreach ($columns as $header => $_) {
            $share = $natural[$header] - $floors[$header];
            $fitted[$header] = $floors[$header] + intdiv($share * $slack, $excess);
        }

        // intdiv() truncates every share, so up to n - 1 cells of the pane go
        // unused. Handing them back widest-first keeps the free-text column
        // the one that grows, which is the same bias the proportional split
        // above has.
        $spare = $content - array_sum($fitted);
        if ($spare > 0) {
            $order = $columns;
            arsort($order);
            foreach (array_keys($order) as $header) {
                if ($spare <= 0) {
                    break;
                }
                ++$fitted[$header];
                --$spare;
            }
        }

        return $fitted;
    }

    /**
     * A bordered, unstyled table ready for {@see Table::row()} calls.
     *
     * Returns candy-sprinkles' own {@see Table} rather than wrapping it: the
     * value is immutable-fluent, so a caller chaining `->row()` gets a new
     * instance that still carries the border and width cap set here.
     *
     * @param array<string, int> $columns header text => cell budget for that column
     */
    public static function headed(array $columns): Table
    {
        return Table::new()
            ->headers(...array_keys($columns))
            ->border(Border::rounded())
            ->width(self::maxCells($columns));
    }

    /**
     * The widest a table built from $columns can render, borders included.
     *
     * Derived rather than hand-picked so the number cannot drift away from
     * the budgets it describes. The overhead terms are read off
     * {@see Table::render()} with a full border: one left and one right
     * edge, one separator between each adjacent pair of columns, and one
     * space of padding on either side of every cell — `2 + (n - 1) + 2n`.
     *
     * A header wider than its own budget would widen the column on its own,
     * so each column contributes whichever of the two is larger.
     *
     * @param array<string, int> $columns header text => cell budget
     */
    public static function maxCells(array $columns): int
    {
        $content = 0;
        foreach ($columns as $header => $budget) {
            $content += max($budget, Width::string((string) $header));
        }

        return $content + 3 * \count($columns) + 1;
    }

    /**
     * Clip $text to $cells CELLS, appending `…` when anything was dropped.
     *
     * Both branches route through {@see Width::truncate()}, which strips
     * ANSI — so an escape sequence embedded in a preset's `name:` or in an
     * MCP server URL is removed here rather than reaching the transcript.
     * That is load-bearing, not incidental: this text is not written by this
     * process, and an escape that reaches the transcript is the `[33m` bug
     * again by a different route.
     */
    public static function cell(string $text, int $cells): string
    {
        if ($cells <= 0) {
            return '';
        }

        if (Width::string($text) <= $cells) {
            return Width::truncate($text, $cells);
        }

        return Width::truncate($text, max(1, $cells - 1)) . '…';
    }
}
