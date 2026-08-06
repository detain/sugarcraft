<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Colour-coded left border mirrors AgentStatusBar / AgentViewPane:
 *   Green  (#9ece6a): actively producing output
 *   Yellow (#e0af68): waiting for tool results OR stalled
 *   Red    (#f7768e): error condition
 *   Gray   (#7d6e98): completed / stopped
 *
 * When a stall warning is active the border switches to amber (#e0af68)
 * and a "⚠ stalled" indicator appears in the header line.
 *
 * Mirrors charmbracelet/crush per-agent streaming display design.
 */
final class AgentOutputPane
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

    /** Amber used for stall warning — same as waiting but semantically distinct. */
    private const STALL_HEX = '#e0af68';

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
    public static function render(AgentOutputState $state, int $width, int $height, Mode $mode = Mode::Peek): string
    {
        $isStalled = $state->stallWarning !== null;
        $borderColor = $isStalled
            ? Color::hex(self::STALL_HEX)
            : self::borderColor($state->status);
        $agentColor  = $isStalled
            ? Color::hex(self::STALL_HEX)
            : self::statusColor($state->status);

        // ── Header line ───────────────────────────────────────────────────
        // "● name  [status]  model  tok | $cost  ⚠ stalled"
        $dot     = Style::new()->foreground($agentColor)->render("\u{25CF}");
        $name    = Style::new()->bold()->foreground($agentColor)->render($state->name);
        $status  = Style::new()->foreground($agentColor)->render('[' . $state->status . ']');
        $model   = Style::new()->foreground(Color::hex('#7aa2f7'))->render($state->model);
        $usage   = Style::new()->foreground(Color::hex('#565676'))->render($state->usageDisplay());
        $header  = "{$dot} {$name} {$status}  {$model}  {$usage}";

        if ($isStalled) {
            $stallIndicator = Style::new()
                ->foreground(Color::hex(self::STALL_HEX))
                ->bold()
                ->render('  ⚠ stalled');
            $header .= $stallIndicator;
        }

        // ── Output buffer lines ────────────────────────────────────────────
        $lines = $state->outputBuffer;

        if ($mode === Mode::Peek) {
            return self::renderPeek($header, $lines, $borderColor, $state->name, $width);
        }

        return self::renderAttach($header, $lines, $borderColor, $state->name, $width, $height);
    }

    /**
     * Peek mode: compact tile showing header + last N buffer lines.
     */
    private static function renderPeek(string $header, array $lines, Color $borderColor, string $agentName, int $width): string
    {
        // Show last PEEK_LINES lines (most recent at bottom).
        $peekLines = array_slice($lines, -self::PEEK_LINES);
        $bodyLines = array_merge([$header], $peekLines);

        // Preview label when buffer exceeds peek window.
        if (count($lines) > self::PEEK_LINES) {
            $bodyLines[] = Style::new()
                ->foreground(Color::hex('#565676'))
                ->render('  + ' . (count($lines) - self::PEEK_LINES) . ' more line(s)…');
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
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Attach mode: full-focus view with full buffer and line count footer.
     */
    private static function renderAttach(string $header, array $lines, Color $borderColor, string $agentName, int $width, int $height): string
    {
        // Reserve rows: 1 header + 1 blank + 1 footer = 3; rest for output.
        $outputRows = max(1, $height - 3);
        $visibleLines = array_slice($lines, 0, $outputRows);

        $outputStyled = [];
        foreach ($visibleLines as $line) {
            $outputStyled[] = Style::new()
                ->foreground(Color::hex('#e0e0e0'))
                ->render($line);
        }

        // Footer: line count when buffer exceeds visible area.
        $footerParts = [];
        if (count($lines) > $outputRows) {
            $footerParts[] = 'lines: ' . count($lines);
        }
        if (count($lines) > 0) {
            $footerParts[] = 'showing last ' . count($visibleLines);
        }
        $footerLine = '';
        if ($footerParts !== []) {
            $footerLine = Style::new()
                ->foreground(Color::hex('#565676'))
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
            ->foreground(Color::hex('#c5b6dd'))
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Resolve the border colour for a given operational status string.
     * Green = actively producing output, Yellow = waiting for tool results,
     * Red = error, Gray = completed / stopped.
     */
    public static function borderColor(string $status): Color
    {
        $hex = self::STATUS_HEX[strtolower(trim($status))] ?? self::STATUS_HEX['completed'];

        return Color::hex($hex);
    }

    /**
     * Resolve the foreground colour for a given operational status string.
     */
    public static function statusColor(string $status): Color
    {
        $hex = self::STATUS_HEX[strtolower(trim($status))] ?? self::STATUS_HEX['completed'];

        return Color::hex($hex);
    }
}
