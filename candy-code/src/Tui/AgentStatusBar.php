<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Lang;
use SugarCraft\Style\Style;
use SugarCraft\Code\Theme;

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
        return '[' . self::statusLabel($status) . ']';
    }

    /**
     * The on-screen word for an operational status. The status itself stays
     * the canonical English id {@see STATUS_TOKEN} and {@see statusColor()}
     * key on; only what the row says is translated. An id this map does not
     * know is shown as it came.
     */
    public static function statusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'working' => Lang::t('tui.agent.status.working'),
            'waiting' => Lang::t('tui.agent.status.waiting'),
            'streaming' => Lang::t('tui.agent.status.streaming'),
            'pending' => Lang::t('tui.agent.status.pending'),
            'failed' => Lang::t('tui.agent.status.failed'),
            'completed' => Lang::t('tui.agent.status.completed'),
            'stopped' => Lang::t('tui.agent.status.stopped'),
            default => $status,
        };
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
     * Truncate a string to at most $maxWidth CELLS, appending "…" when
     * truncation occurs.
     *
     * Measurement and cutting both route through the canonical {@see Width}
     * (ICU UAX#29 clusters + the Markus-Kuhn cell table) rather than a local
     * per-codepoint guess. The private table this replaces mis-scored every
     * combining mark, ZWJ sequence and emoji (an emoji read as 1 cell, a
     * family emoji as 5), and — worse — byte-sliced the result outright on
     * invalid UTF-8: `preg_split('//u')` returns false there, so the old
     * fallback was a raw `substr` cutting mid-codepoint, a mojibake row in the
     * bar. The status line is drawn on one terminal row, so an under-count
     * pushes it past the pane width; the canonical measure is the fix.
     *
     * The overflow leg first runs the text through {@see Sanitize::untrusted()}:
     * an agent operation is model/tool-supplied, and dropping escape and
     * control bytes before we cut keeps both the cell math honest and the
     * terminal safe. A string that already fits is returned byte-for-byte
     * unchanged, so the common short-operation path is untouched.
     */
    private static function truncate(string $text, int $maxWidth): string
    {
        if ($text === '') {
            return '';
        }

        if (Width::of($text) <= $maxWidth) {
            return $text;
        }

        return Width::truncate(Sanitize::untrusted($text), max(0, $maxWidth - 1)) . "\u{2026}";
    }
}
