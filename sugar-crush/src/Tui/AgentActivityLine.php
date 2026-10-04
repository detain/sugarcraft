<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Crush\Util\TokenCount;
use SugarCraft\Sprinkles\Style;

/**
 * The one row a delegated run gets under its Task row in the transcript
 * (Appendix P §4.1, step P-B2):
 *
 *     └ ⠋ Grep "LoginController" routes/ · 7 tools · 0:12 · 4.1K tok · $0.0021
 *     └ ✓ done · 11 tools · 0:31 · 9.8K tok
 *     └ ✗ failed: step cap 50 reached · 11 tools · 0:31 · 9.8K tok · resumable
 *
 * The glyph is the animated spinner while the run works, then ✓ (complete),
 * ✗ (failed), ⏹ (cancelled) or ⏸ (ended without a report — stopped, and
 * resumable). The text after it is the run's latest item while it runs
 * ({@see AgentLiveState}) and its outcome once it finished.
 *
 * WIDTH-SAFE BY CONSTRUCTION. The diff renderer paints one line per row, and a
 * row wider than the pane corrupts the frame, so the line is laid out as
 * segments with a drop order: the cost goes first, then the tokens, then the
 * tool count, then the clock, then "resumable"; whatever is left goes to the
 * latest item, elided in the middle. Below the narrowest useful width the
 * whole line is cut. The result is never wider than `$width` cells.
 *
 * Every agent-originated string — tool names, summaries, prose, the error —
 * passes through {@see PaneLabel::safe()}: ANSI and control bytes stripped,
 * Private-Use code points (zone and image markers) stripped, tabs and
 * newlines folded to spaces.
 */
final class AgentActivityLine
{
    /** The cells before the glyph: indent and branch. */
    public const PREFIX = '  └ ';

    /** Between segments. */
    public const SEPARATOR = ' · ';

    /** The fewest cells the latest item keeps before a figure is dropped for it. */
    public const MIN_ITEM_CELLS = 10;

    private function __construct()
    {
    }

    /**
     * @param int        $spinnerFrame  index into {@see AgentLiveRegistry::SPINNER}
     * @param float      $now           the registry's clock ({@see AgentLiveRegistry::now()})
     * @param float|null $elapsedSeconds the run's duration when the caller knows
     *        it better than the frames do (a settled Task row's own duration);
     *        null reads it off the state
     */
    public static function render(AgentLiveState $state, int $width, Theme $theme, int $spinnerFrame, float $now, ?float $elapsedSeconds = null): string
    {
        if ($width <= 0) {
            return '';
        }

        [$glyph, $glyphColor] = self::glyph($state, $theme, $spinnerFrame);
        $item = self::item($state);
        $elapsed = $elapsedSeconds ?? $state->elapsed($now);

        // Display order; each carries its drop rank (lowest goes first).
        $segments = [];
        if ($state->toolCount > 0) {
            $segments[] = [2, $state->toolCount === 1 ? '1 tool' : $state->toolCount . ' tools'];
        }
        if ($elapsed !== null) {
            $segments[] = [3, self::clock($elapsed)];
        }
        if ($state->tokens() > 0) {
            $segments[] = [1, TokenCount::compact($state->tokens()) . ' tok'];
        }
        if ($state->costUsd > 0.0) {
            $segments[] = [0, self::cost($state->costUsd)];
        }
        if ($state->isFinished() && $state->outcome !== SubAgentActivity::OUTCOME_COMPLETE && $state->resumeId !== null) {
            $segments[] = [4, 'resumable'];
        }

        $head = self::PREFIX . $glyph . ' ';
        $headCells = Width::string($head);
        $fixed = static function (array $kept): int {
            $cells = 0;
            foreach ($kept as [, $text]) {
                $cells += Width::string(self::SEPARATOR) + Width::string($text);
            }

            return $cells;
        };

        // Drop figures, lowest rank first, until the item keeps its minimum.
        $itemCells = Width::string($item);
        $wanted = min($itemCells, self::MIN_ITEM_CELLS);
        while ($segments !== [] && $headCells + $wanted + $fixed($segments) > $width) {
            $lowest = 0;
            foreach ($segments as $index => [$rank]) {
                if ($rank < $segments[$lowest][0]) {
                    $lowest = $index;
                }
            }
            array_splice($segments, $lowest, 1);
        }

        $room = $width - $headCells - $fixed($segments);
        if ($room < 1) {
            // Too narrow for even a glyph and one cell of text: the plain
            // head, cut to the pane.
            return Width::truncate($head . $item, $width);
        }

        $item = $itemCells <= $room ? $item : Width::truncateMiddle($item, $room);
        $tail = '';
        foreach ($segments as [, $text]) {
            $tail .= self::SEPARATOR . $text;
        }

        $dim = Style::new()->foreground($theme->systemLabel);

        return $dim->render(self::PREFIX)
            . Style::new()->foreground($glyphColor)->render($glyph)
            . ' '
            . $dim->render($item . $tail);
    }

    /**
     * @return array{0: string, 1: Color}
     */
    private static function glyph(AgentLiveState $state, Theme $theme, int $spinnerFrame): array
    {
        if (!$state->isFinished()) {
            $frames = AgentLiveRegistry::SPINNER;

            return [$frames[(($spinnerFrame % count($frames)) + count($frames)) % count($frames)], $theme->assistantLabel];
        }

        return match ($state->outcome) {
            SubAgentActivity::OUTCOME_COMPLETE => ['✓', $theme->shellSuccess],
            SubAgentActivity::OUTCOME_CANCELLED => ['⏹', $theme->shellWarning],
            SubAgentActivity::OUTCOME_EMPTY => ['⏸', $theme->shellWarning],
            default => ['✗', $theme->shellError],
        };
    }

    /**
     * What the run is doing, or how it ended — already one safe line.
     */
    private static function item(AgentLiveState $state): string
    {
        if ($state->isFinished()) {
            return match ($state->outcome) {
                SubAgentActivity::OUTCOME_COMPLETE => 'done',
                SubAgentActivity::OUTCOME_CANCELLED => 'cancelled',
                SubAgentActivity::OUTCOME_EMPTY => 'stopped without a report',
                default => 'failed' . (($error = self::reason($state)) === '' ? '' : ': ' . $error),
            };
        }

        $current = $state->currentCall();
        if ($current !== null) {
            return self::call($current->tool, $current->summary);
        }

        $latest = $state->latest;
        if ($latest === null) {
            return 'starting…';
        }

        return match ($latest->type) {
            ActivityItem::TOOL_FINISHED => ($latest->ok ? '✓ ' : '✗ ') . self::call($latest->tool, $state->latestSummary),
            ActivityItem::THINKING => 'thinking…',
            ActivityItem::TEXT => ($prose = PaneLabel::safe($latest->delta)) === '' ? 'writing…' : '"' . $prose . '"',
            default => 'starting…',
        };
    }

    /**
     * Why a run failed, minus the `sub-agent "<name>" failed: ` preamble
     * TaskTool writes for the model — on this line the row already says
     * which agent, and "failed: … failed:" says it twice.
     */
    private static function reason(AgentLiveState $state): string
    {
        $error = PaneLabel::safe((string) $state->error);
        $preamble = 'sub-agent "' . PaneLabel::safe($state->name) . '" ';
        if (str_starts_with($error, $preamble)) {
            $error = substr($error, strlen($preamble));
        }
        if (str_starts_with($error, 'failed: ')) {
            $error = substr($error, strlen('failed: '));
        }

        return $error;
    }

    private static function call(string $tool, string $summary): string
    {
        $tool = PaneLabel::safe($tool);
        $summary = PaneLabel::safe($summary);

        return $summary === '' ? $tool : $tool . ' ' . $summary;
    }

    /** `m:ss`, or `h:mm:ss` from an hour. */
    private static function clock(float $seconds): string
    {
        $total = (int) floor($seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    private static function cost(float $usd): string
    {
        return $usd >= 0.01 ? sprintf('$%.2f', $usd) : sprintf('$%.4f', $usd);
    }
}
