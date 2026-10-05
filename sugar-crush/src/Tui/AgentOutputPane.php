<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Lang;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * Colour-coded left border mirrors AgentStatusBar / AgentViewPane, by
 * {@see Theme} token:
 *   `shellSuccess`: actively producing output
 *   `shellWarning`: waiting for tool results OR stalled
 *   `shellError`:   error condition
 *   `shellMuted`:   completed / stopped
 *
 * When a stall warning is active the border switches to the palette's warning
 * colour ({@see STALL_TOKEN}) and a "⚠ stalled" indicator appears in the
 * header line.
 *
 * Mirrors charmbracelet/crush per-agent streaming display design.
 */
final class AgentOutputPane
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
     * Token used for the stall warning — the same one `waiting` resolves to
     * but semantically distinct, exactly as the two literals it replaces were
     * both #e0af68.
     */
    private const STALL_TOKEN = 'shellWarning';

    /** Maximum output lines shown in peek mode. */
    public const PEEK_LINES = 4;

    /**
     * Render a single agent's streaming output pane.
     *
     * @param AgentOutputState $state  Agent display state including live output buffer.
     * @param int              $width  Available terminal columns for the pane.
     * @param int              $height Available terminal rows for the pane.
     * @param Mode             $mode   PEEK = last N lines in a tile; ATTACH = full focus.
     */
    public static function render(AgentOutputState $state, int $width, int $height, Theme $theme, Mode $mode = Mode::Peek): string
    {
        $isStalled = $state->stallWarning !== null;
        $stallColor = $theme->{self::STALL_TOKEN};
        $borderColor = $isStalled
            ? $stallColor
            : self::borderColor($state->status, $theme);
        $agentColor  = $isStalled
            ? $stallColor
            : self::statusColor($state->status, $theme);

        // ── Header line ───────────────────────────────────────────────────
        // "● name  [status]  model  tok | $cost  ⚠ stalled"
        $dot     = Style::new()->foreground($agentColor)->render("\u{25CF}");
        $name    = Style::new()->bold()->foreground($agentColor)->render($state->name);
        $status  = Style::new()->foreground($agentColor)->render('[' . AgentStatusBar::statusLabel($state->status) . ']');
        $model   = Style::new()->foreground($theme->shellInfo)->render($state->model);
        $usage   = Style::new()->foreground($theme->shellMuted)->render($state->usageDisplay());
        $header  = "{$dot} {$name} {$status}  {$model}  {$usage}";

        if ($isStalled) {
            $stallIndicator = Style::new()
                ->foreground($stallColor)
                ->bold()
                ->render('  ⚠ ' . Lang::t('tui.agents.stalled'));
            $header .= $stallIndicator;
        }

        // ── Output buffer lines ────────────────────────────────────────────
        $lines = $state->outputBuffer;

        if ($mode === Mode::Peek) {
            // What this worker is doing: with several runs of one agent side
            // by side, the task is the only thing that tells their tiles apart.
            if (trim($state->operation) !== '') {
                // One row, cut to the tile: a wrapped task would grow the
                // tile past the height its stacker budgeted for.
                $header .= "\n" . Style::new()->foreground($theme->shellMuted)
                    ->render(Width::truncate($state->operation, max(1, $width)));
            }

            return self::renderPeek($header, $lines, $state->lineCount(), $borderColor, $state->name, $width, $theme);
        }

        return self::renderAttach($header, $lines, $borderColor, $state->name, $width, $height, $theme);
    }

    /**
     * Peek mode: compact tile showing header + last N buffer lines.
     */
    private static function renderPeek(string $header, array $lines, int $totalLines, Color $borderColor, string $agentName, int $width, Theme $theme): string
    {
        // Show last PEEK_LINES lines (most recent at bottom).
        $peekLines = array_slice($lines, -self::PEEK_LINES);
        $bodyLines = array_merge([$header], $peekLines);

        // Preview label when the agent has produced more than the peek shows.
        // Counted against EVERYTHING it produced, not the buffer handed in:
        // that is a bounded tail, and counting it pinned this at the tail's
        // size ("+ 4 more") however long the agent kept working.
        $hidden = max($totalLines, count($lines)) - count($peekLines);
        if ($hidden > 0) {
            $bodyLines[] = Style::new()
                ->foreground($theme->shellMuted)
                ->render('  ' . Lang::t('tui.agent.more_lines', ['count' => $hidden]));
        }

        $body = implode("\n", $bodyLines);

        $border = Border::rounded()
            ->withTitle(' ' . $agentName . ' ');

        return Style::new()
            ->border($border)
            ->borderLeftForeground($borderColor)
            ->borderTopForeground($borderColor)
            ->borderRightForeground($borderColor)
            ->borderBottomForeground($borderColor)
            ->foreground($theme->shellForeground)
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Attach mode: full-focus view with full buffer and line count footer.
     *
     * Reached through the read-only Agent View (roadmap P-C2): a dashboard
     * worker that keeps no transcript of its own — a workflow stage, a
     * background session — is shown in the main area as this pane. The buffer
     * is a live tail, so the rows kept are the NEWEST ones, which is what the
     * footer's "showing last N" has always said.
     */
    private static function renderAttach(string $header, array $lines, Color $borderColor, string $agentName, int $width, int $height, Theme $theme): string
    {
        // Reserve rows: 1 header + 1 blank + 1 footer = 3; rest for output.
        $outputRows = max(1, $height - 3);
        $visibleLines = array_slice($lines, -$outputRows);

        $outputStyled = [];
        foreach ($visibleLines as $line) {
            $outputStyled[] = Style::new()
                ->foreground($theme->shellForeground)
                ->render($line);
        }

        // Footer: line count when buffer exceeds visible area.
        $footerParts = [];
        if (count($lines) > $outputRows) {
            $footerParts[] = Lang::t('tui.agent.line_count', ['count' => count($lines)]);
        }
        if (count($lines) > 0) {
            $footerParts[] = Lang::t('tui.agent.showing_last', ['count' => count($visibleLines)]);
        }
        $footerLine = '';
        if ($footerParts !== []) {
            $footerLine = Style::new()
                ->foreground($theme->shellMuted)
                ->render('  ' . implode('  ·  ', $footerParts));
        }

        $bodyLines = array_merge([$header, ''], $outputStyled);
        if ($footerLine !== '') {
            $bodyLines[] = $footerLine;
        }

        $body = implode("\n", $bodyLines);

        $border = Border::rounded()
            ->withTitle(' ' . $agentName . ' ');

        return Style::new()
            ->border($border)
            ->borderLeftForeground($borderColor)
            ->borderTopForeground($borderColor)
            ->borderRightForeground($borderColor)
            ->borderBottomForeground($borderColor)
            ->foreground($theme->shellForeground)
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Resolve the border colour for a given operational status string.
     * Green = actively producing output, Yellow = waiting for tool results,
     * Red = error, Gray = completed / stopped.
     */
    public static function borderColor(string $status, Theme $theme): Color
    {
        $token = self::STATUS_TOKEN[strtolower(trim($status))] ?? self::STATUS_TOKEN['completed'];

        return $theme->$token;
    }

    /**
     * Resolve the foreground colour for a given operational status string.
     */
    public static function statusColor(string $status, Theme $theme): Color
    {
        $token = self::STATUS_TOKEN[strtolower(trim($status))] ?? self::STATUS_TOKEN['completed'];

        return $theme->$token;
    }
}
