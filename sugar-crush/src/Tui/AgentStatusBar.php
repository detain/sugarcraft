<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * Renders agent status indicators for the bottom status bar.
 *
 * Each indicator shows the agent name/role, current operational status,
 * current operation description, elapsed time, and token usage/cost.
 *
 * Status colour coding, by {@see Theme} token rather than by literal — the
 * hues below are the roles, and which RGB each resolves to is the active
 * palette's business (and, where a palette token would be illegible against the
 * terminal's real background, {@see Theme}'s escalation's business):
 *   - `shellSuccess`: actively processing / Working
 *   - `shellWarning`: waiting for input or blocked on a dependency / Waiting
 *   - `shellInfo`:    streaming output from the LLM / Streaming
 *   - `shellError`:   failed or encountered an error / Failed
 *   - `shellMuted`:   completed successfully or stopped / Completed
 *
 * Mirrors the charmbracelet/crush agent view status indicator design.
 */
final class AgentStatusBar
{
    /**
     * Maps status keyword to the {@see Theme} property that colours it.
     *
     * A token NAME rather than a Color, because a Theme is resolved per frame
     * (the palette can move mid-session when OSC 11 answers) and a const
     * cannot hold one. `completed` and `stopped` share `shellMuted` — the two
     * hand-picked greys this replaced (#7d6e98 twice) were already identical.
     *
     * @var array<'working'|'waiting'|'streaming'|'failed'|'completed'|'stopped', string>
     */
    private const STATUS_TOKEN = [
        'working'   => 'shellSuccess',
        'waiting'   => 'shellWarning',
        'streaming' => 'shellInfo',
        'failed'    => 'shellError',
        'completed' => 'shellMuted',
        'stopped'   => 'shellMuted',
    ];

    /**
     * Render a single agent's status indicator line.
     *
     * Format: "[status-dot] name  status  operation  elapsed  usage"
     *
     * Returns an empty string when the agent list is empty (caller may
     * choose to suppress the bar entirely in that case).
     */
    public static function renderAgentLine(AgentDisplayState $agent, Theme $theme): string
    {
        $color = self::statusColor($agent->status, $theme);
        $dot = self::colouredDot($color);
        $name = Style::new()->bold()->foreground($color)->render($agent->name);
        $status = Style::new()->foreground($color)->render(self::bracket($agent->status));
        $operation = Style::new()->foreground($theme->shellForeground)->render(self::truncate($agent->operation, 30));
        $elapsed = Style::new()->foreground($theme->shellMuted)->render($agent->elapsedDisplay());
        $usage = Style::new()->foreground($theme->shellMuted)->render($agent->usageDisplay());

        return "{$dot} {$name} {$status} {$operation} {$elapsed} {$usage}";
    }

    /**
     * Render a multi-agent status bar, one line per agent.
     *
     * When the list is empty returns an empty string so callers can
     * suppress the bar without special-case logic in the renderer.
     *
     * @param list<AgentDisplayState> $agents
     */
    public static function render(array $agents, Theme $theme): string
    {
        if ($agents === []) {
            return '';
        }

        $lines = [];
        foreach ($agents as $agent) {
            $lines[] = self::renderAgentLine($agent, $theme);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the colour for a given operational status string.
     * Falls back to the `completed` token when the status is not recognised.
     */
    public static function statusColor(string $status, Theme $theme): Color
    {
        $key = strtolower(trim($status));
        $token = self::STATUS_TOKEN[$key] ?? self::STATUS_TOKEN['completed'];

        return $theme->$token;
    }

    /**
     * Returns the canonical status string with bracket markers so it
     * visually separates from surrounding text in the indicator line.
     */
    private static function bracket(string $status): string
    {
        return '[' . $status . ']';
    }

    /**
     * Returns a coloured Unicode bullet character (full block) for use
     * as the status-dot at the start of each indicator line.
     */
    private static function colouredDot(Color $color): string
    {
        return Style::new()->foreground($color)->render("\u{25CF}");
    }

    /**
     * Truncate a string to at most $maxWidth characters, appending "…"
     * when truncation occurs.  Preserves multibyte characters.
     */
    private static function truncate(string $text, int $maxWidth): string
    {
        if ($text === '') {
            return '';
        }

        // Measure by visual width (accounts for wide / combining chars).
        $width = self::visualWidth($text);
        if ($width <= $maxWidth) {
            return $text;
        }

        // Back up to the last codepoint that fits.
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return self::truncateAscii($text, $maxWidth);
        }

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
     * Compute visual width of a string for truncation purposes.
     * East Asian wide characters count as 2; control characters as 0.
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
     * Width of a single codepoint: 0 for control, 1 for regular, 2 for wide/EastAsian.
     */
    private static function charWidth(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code === false || $code < 32) {
            return 0;
        }

        // East Asian Wide (W) and Full-width (F) categories.
        if (
            ($code >= 0x1100 && $code <= 0x115F)   // Hangul Jamo
            || ($code >= 0x2329 && $code <= 0x232A) // Angle brackets
            || ($code >= 0x2E80 && $code <= 0x303E) // CJK Radicals … Cao
            || ($code >= 0x3040 && $code <= 0xA4CF) // Hiragana … Yi
            || ($code >= 0xAC00 && $code <= 0xD7A3) // Hangul Syllables
            || ($code >= 0xF900 && $code <= 0xFAFF) // CJK Compatibility Ideographs
            || ($code >= 0xFE10 && $code <= 0xFE1F) // Vertical forms
            || ($code >= 0xFE30 && $code <= 0xFE6F) // CJK Compatibility Forms … Small Form Variants
            || ($code >= 0xFF00 && $code <= 0xFFE5) // Full-width forms
            || ($code >= 0x20000 && $code <= 0x2FFFD) // CJK Unified Ideographs Extension B …
            || ($code >= 0x30000 && $code <= 0x3FFFD) // CJK Unified Ideographs Extension G …
        ) {
            return 2;
        }

        return 1;
    }

    /**
     * ASCII-only fallback truncate when preg_split fails.
     */
    private static function truncateAscii(string $text, int $maxWidth): string
    {
        if (strlen($text) <= $maxWidth - 1) {
            return $text;
        }

        return substr($text, 0, $maxWidth - 1) . "\u{2026}";
    }
}
