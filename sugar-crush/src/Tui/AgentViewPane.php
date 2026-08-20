<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * Renders the Agent View list pane — a scrollable, selectable list of all
 * active agents with their real-time status, current operation, elapsed
 * time, and token usage.
 *
 * Agent row format (left-to-right):
 *   [dot] name  [status]  operation…  elapsed  usage
 *
 * Colour coding mirrors AgentStatusBar, by {@see Theme} token:
 *   `shellSuccess`: working
 *   `shellWarning`: waiting
 *   `shellInfo`:    streaming
 *   `shellError`:   failed
 *   `shellMuted`:   completed / stopped
 *
 * The selected-agent highlight fills the row with the palette's `separator`
 * and keeps the status colour on top. It used to fill a literal #232338, which
 * is an inversion only on a dark terminal — on a light one it painted a dark
 * band under text chosen to be read on light.  Arrow-key navigation
 * is handled by the caller (KeyboardHandler / App state); this class only
 * renders the current selection state passed in.
 *
 * Mirrors charmbracelet/crush agent view list design.
 */
final class AgentViewPane
{
    /** Theme tokens matched to {@see AgentStatusBar}'s STATUS_TOKEN. */
    private const STATUS_TOKEN = [
        'working'   => 'shellSuccess',
        'waiting'   => 'shellWarning',
        'streaming' => 'shellInfo',
        'failed'    => 'shellError',
        'completed' => 'shellMuted',
        'stopped'   => 'shellMuted',
    ];

    /**
     * Render the agent list pane.
     *
     * @param list<AgentDisplayState> $agents        Ordered list of agents to display.
     * @param int                     $selectedIndex Index of the currently-selected agent (passed
     *                                               from App state; -1 means no selection).
     * @param int                     $width        Available terminal columns for the pane.
     * @param int                     $maxRows      Maximum rows to render before clipping (longer
     *                                               lists are silently truncated at render time;
     *                                               scrolling is handled by the caller).
     */
    public static function render(array $agents, int $selectedIndex, int $width, int $maxRows, Theme $theme): string
    {
        // Early exit — empty list: show a tasteful placeholder inside the border.
        if ($agents === []) {
            $body = Style::new()
                ->foreground($theme->shellMuted)
                ->render('(no active agents)');

            return Style::new()
                ->border(Border::rounded()->withTitle(' agents '))
                ->padding(0, 1)
                ->width($width)
                ->render($body);
        }

        // ── Column-budget layout ─────────────────────────────────────────────
        // Right-side fixed columns (estimated; shrinks gracefully on narrow terms):
        //   sep(2) + elapsed(~7) + sep(2) + usage(~15)
        $fixedRight = 26;

        $rows = [];
        $count = min(count($agents), $maxRows);

        for ($i = 0; $i < $count; $i++) {
            $agent = $agents[$i];
            $isSelected = ($i === $selectedIndex);
            $statusColor = self::statusColor($agent->status, $theme);

            // Colour dot + name + status badge.
            $dot  = Style::new()->foreground($statusColor)->render("\u{25CF}");
            $name = $agent->name;

            // Right-side (elapsed + usage).
            $rightSection = $agent->elapsedDisplay() . '  ' . $agent->usageDisplay();

            // Truncate operation to fill remaining space.
            // name(~12) + dot(2) + sep(2) + status_bracket(~14) + sep(2) + right(~26)
            //
            // $width is a CELL budget, so the name has to be charged against it
            // in cells. strlen() charged BYTES, which meant an agent named with
            // any non-ASCII character silently bought less operation text than
            // an identically-wide ASCII name -- "abc" got 17 columns while
            // "abc" with an acute accent on the a got 16, for the same three
            // cells on screen.
            $opBudget = max(5, $width - Width::string($name) - 60);
            $operation = self::truncate($agent->operation, $opBudget);

            // Left section: dot + name + status + operation.
            $leftSection = "{$dot} {$name} [{$agent->status}]  {$operation}";

            if ($isSelected) {
                // Inverted highlight — dark background, status-colour foreground.
                $rowStyle = Style::new()
                    ->foreground($statusColor)
                    ->background($theme->shellSeparator);
            } else {
                $rowStyle = Style::new()
                    ->foreground($theme->shellMuted);
            }

            $rightStyle = Style::new()->foreground($theme->shellMuted);

            // Right-pad the left section so the right section lands at the edge.
            //
            // Width::padRight(), not str_pad(), for TWO reasons that both make
            // str_pad() the wrong tool here and only one of which is about
            // Unicode:
            //
            // 1. $rightSection's cost must be counted in cells, same argument
            //    as $opBudget above.
            // 2. $leftSection is STYLED -- $dot is a rendered Style, so the
            //    string carries SGR escape bytes. str_pad() counts those as
            //    occupied columns, so on a colour-capable terminal the measure
            //    overshot by the whole escape sequence and str_pad() returned
            //    the string untouched, i.e. the pad did nothing at all.
            //    Width::string() strips ANSI before measuring, so the pad is
            //    computed against what the terminal actually shows.
            //
            // padRight() is a no-op once the section already meets the width,
            // so this cannot widen a row past its budget.
            $leftPadded = Width::padRight($leftSection, $width - Width::string($rightSection) - 1);

            $rows[] = $rowStyle->render($leftPadded)
                . $rightStyle->render($rightSection);
        }

        $body = implode("\n", $rows);

        return Style::new()
            ->border(Border::rounded()->withTitle(' agents '))
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Resolve the colour for a given operational status string.
     * Falls back to the `completed` token for unknown statuses.
     */
    public static function statusColor(string $status, Theme $theme): Color
    {
        $token = self::STATUS_TOKEN[strtolower(trim($status))] ?? self::STATUS_TOKEN['completed'];

        return $theme->$token;
    }

    /**
     * Truncate $text to at most $maxWidth characters, appending "…" when
     * it does not fit.  Preserves multibyte characters.  Returns the text
     * verbatim when it already fits.
     */
    private static function truncate(string $text, int $maxWidth): string
    {
        if ($text === '' || $maxWidth <= 0) {
            return '';
        }

        $visualWidth = self::visualWidth($text);
        if ($visualWidth <= $maxWidth) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = '';
        $w = 0;

        foreach ($chars as $char) {
            $charWidth = self::charWidth($char);
            if ($w + $charWidth > $maxWidth - 1) { // leave room for "…"
                break;
            }
            $result .= $char;
            $w += $charWidth;
        }

        return $result . "\u{2026}";
    }

    /**
     * Compute visual width of a string in terminal cells.
     *
     * Delegates to {@see Width::string()}, so this class's truncator and its
     * right-pad now read the same width TABLE. They did not: the pad counted
     * bytes while the truncator walked a local table, and that table disagreed
     * with Width on real codepoints (emoji scored 1 here, 2 there; a combining
     * accent scored 1 here, 0 there). Two tables cannot hold a column still
     * however carefully either is written.
     *
     * One table is not quite one ANSWER, and the difference is worth stating
     * exactly rather than rounding up to "one width authority". This function
     * measures a whole string, so it is grapheme-aware; {@see truncate()}
     * still sums {@see charWidth()} one codepoint at a time. On a ZWJ sequence
     * the two therefore diverge — measured, the family emoji U+1F468 ZWJ
     * U+1F469 ZWJ U+1F467 is 2 cells whole-string and 6 summed per codepoint.
     *
     * The divergence is bounded in the SAFE direction: the per-codepoint sum
     * is the larger of the two, so the loop spends its budget early and
     * truncates sooner than it had to. It can drop a character that would have
     * fit; it cannot emit a row wider than the budget, which is the failure
     * this file's tests exist to prevent. Recorded in
     * docs/plans/crush_code_hardening_backlog.md rather than fixed here —
     * making the loop grapheme-aware is a change to the truncator, not to the
     * padding measure this bundle is about.
     */
    private static function visualWidth(string $text): int
    {
        return Width::string($text);
    }

    /**
     * Visual width of a single codepoint: 0 for control, 1 for regular, 2 for wide.
     */
    private static function charWidth(string $char): int
    {
        return Width::string($char);
    }
}
