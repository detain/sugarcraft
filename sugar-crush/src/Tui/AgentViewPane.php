<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Renders the Agent View list pane — a scrollable, selectable list of all
 * active agents with their real-time status, current operation, elapsed
 * time, and token usage.
 *
 * Agent row format (left-to-right):
 *   [dot] name  [status]  operation…  elapsed  usage
 *
 * Colour coding mirrors AgentStatusBar:
 *   Green  (#9ece6a): working
 *   Yellow (#e0af68): waiting
 *   Blue   (#7aa2f7): streaming
 *   Red    (#f7768e): failed
 *   Gray   (#7d6e98): completed / stopped
 *
 * The selected-agent highlight uses a dark background inversion so it is
 * legible regardless of the terminal colour scheme.  Arrow-key navigation
 * is handled by the caller (KeyboardHandler / App state); this class only
 * renders the current selection state passed in.
 *
 * Mirrors charmbracelet/crush agent view list design.
 */
final class AgentViewPane
{
    /** Hex values matched to AgentStatusBar::STATUS_HEX. */
    private const STATUS_HEX = [
        'working'   => '#9ece6a',
        'waiting'   => '#e0af68',
        'streaming' => '#7aa2f7',
        'failed'    => '#f7768e',
        'completed' => '#7d6e98',
        'stopped'   => '#7d6e98',
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
    public static function render(array $agents, int $selectedIndex, int $width, int $maxRows): string
    {
        // Early exit — empty list: show a tasteful placeholder inside the border.
        if ($agents === []) {
            $body = Style::new()
                ->foreground(Color::hex('#565676'))
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
            $statusColor = self::statusColor($agent->status);

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
                    ->background(Color::hex('#232338'));
            } else {
                $rowStyle = Style::new()
                    ->foreground(Color::hex('#7d6e98'));
            }

            $rightStyle = Style::new()->foreground(Color::hex('#565676'));

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
     * Defaults to gray (#7d6e98) for unknown statuses.
     */
    public static function statusColor(string $status): Color
    {
        $hex = self::STATUS_HEX[strtolower(trim($status))] ?? self::STATUS_HEX['completed'];

        return Color::hex($hex);
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
