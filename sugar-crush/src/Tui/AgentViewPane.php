<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
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
            $opBudget = max(5, $width - strlen($name) - 60);
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
            $leftPadded = str_pad($leftSection, $width - strlen($rightSection) - 1, ' ', STR_PAD_RIGHT);

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
     * Compute visual width of a string (wide / combining characters count as 2).
     *
     * @param string $text
     * @return int
     */
    private static function visualWidth(string $text): int
    {
        $width = 0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $width += self::charWidth($char);
        }

        return $width;
    }

    /**
     * Visual width of a single codepoint: 0 for control, 1 for regular, 2 for wide.
     */
    private static function charWidth(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code === false || $code < 32) {
            return 0;
        }

        if (
            ($code >= 0x1100 && $code <= 0x115F)
            || ($code >= 0x2329 && $code <= 0x232A)
            || ($code >= 0x2E80 && $code <= 0x303E)
            || ($code >= 0x3040 && $code <= 0xA4CF)
            || ($code >= 0xAC00 && $code <= 0xD7A3)
            || ($code >= 0xF900 && $code <= 0xFAFF)
            || ($code >= 0xFE10 && $code <= 0xFE1F)
            || ($code >= 0xFE30 && $code <= 0xFE6F)
            || ($code >= 0xFF00 && $code <= 0xFFE5)
            || ($code >= 0x20000 && $code <= 0x2FFFD)
            || ($code >= 0x30000 && $code <= 0x3FFFD)
        ) {
            return 2;
        }

        return 1;
    }
}
