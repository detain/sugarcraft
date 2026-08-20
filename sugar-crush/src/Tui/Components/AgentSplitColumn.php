<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentOutputPane;
use SugarCraft\Crush\Tui\AgentOutputState;
use SugarCraft\Crush\Tui\Mode;
use SugarCraft\Sprinkles\Style;

/**
 * The split-pane compositor's second pane: a stack of live-agent peek tiles,
 * sized to an exact column budget.
 *
 * This is the consumer {@see AgentManager::liveOutputs()} was written for and
 * that its docblock names — "who is producing text right now", laid out beside
 * the shell rather than one name at a time. {@see
 * \SugarCraft\Crush\Tui\Renderer::renderView()} calls it whenever that map is
 * non-empty and the terminal is wide enough to carry two panes.
 *
 * ## Width contract
 *
 * `render()` returns lines of AT MOST `$width` cells and at most `$rows`
 * lines — never more of either, whatever the tiles do. That is not a
 * politeness: the compositor hands this block to
 * {@see \SugarCraft\Crush\Tui\SplitLayout}, which pads the FIRST pane to its
 * budget and then appends the divider and this pane's line verbatim, so one
 * over-wide row here is one over-wide row in the finished frame — and
 * candy-core's renderer paints one line per terminal row with an absolute
 * `cursorTo()`, so the terminal wraps it and every row below lands on the
 * wrong line (`docs/ARCHITECTURE.md`, render invariants).
 *
 * {@see AgentOutputPane::render()} returns `$width + 4` cells, MEASURED, not
 * assumed: its border contributes 2 and its `padding(0, 1)` another 2, and
 * both sit OUTSIDE the `Style::width()` it is given. {@see PANE_CHROME} is
 * that measurement, and the {@see Width::truncateAnsi()} sweep at the end is
 * the backstop for any tile that mis-sizes itself anyway — the same
 * belt-and-braces discipline `Tui\Renderer::clipWidth()` applies to the whole
 * frame.
 *
 * ## Untrusted text
 *
 * Every byte in a live output buffer is model- or tool-authored, and it
 * reaches the terminal through this class without passing
 * {@see \SugarCraft\Crush\Renderer}'s boundary, exactly as
 * {@see AgentDashboardPane}'s does. So the same two-part boundary is applied
 * here: {@see PaneLabel::of()} for escapes and line breaks, plus a
 * Private-Use sweep — U+E000 is where candy-core's image markers AND
 * candy-mouse's zone sentinels both begin, so an agent that echoes one would
 * corrupt the frame's graphics/zone bookkeeping rather than merely look wrong.
 */
final class AgentSplitColumn
{
    /**
     * Cells {@see AgentOutputPane::render()} adds beyond the width it is
     * asked for: rounded border (1 + 1) plus `padding(0, 1)` (1 + 1).
     */
    public const PANE_CHROME = 4;

    /** Bytes of each agent's buffer tail considered, before line splitting. */
    private const TAIL_BYTES = 4096;

    /** Lines kept from each agent's tail; the pane peeks fewer still. */
    private const TAIL_LINES = 8;

    /**
     * Render the live-agent column.
     *
     * Tiles are emitted in {@see AgentManager::liveOutputs()} order until the
     * row budget is spent; a tile that would not fit WHOLE is not started, so
     * the column never shows a box with its bottom border cut off. Whatever is
     * left over is reported as one muted "+ N more" line when there is a row
     * to spare for it.
     *
     * @param array<string, string> $liveOutputs agent name => live output, as
     *                                           {@see AgentManager::liveOutputs()} returns it
     * @param int                   $width       exact column budget in cells
     * @param int                   $rows        exact row budget
     */
    public static function render(
        array $liveOutputs,
        ?AgentManager $manager,
        Theme $theme,
        int $width,
        int $rows,
    ): string {
        if ($liveOutputs === [] || $width <= 0 || $rows <= 0) {
            return '';
        }

        $inner = max(1, $width - self::PANE_CHROME);
        $tiles = [];
        $used = 0;
        $shown = 0;

        foreach ($liveOutputs as $name => $output) {
            $tile = AgentOutputPane::render(
                self::state((string) $name, $output, $manager),
                $inner,
                $rows,
                $theme,
                Mode::Peek,
            );

            $height = substr_count($tile, "\n") + 1;

            // A tile is all-or-nothing (see the method docblock); the first one
            // is exempt so a terminal too short for even one tile still shows
            // its head rather than an empty column.
            if ($shown > 0 && $used + $height > $rows) {
                break;
            }

            $tiles[] = $tile;
            $used += $height;
            $shown++;

            if ($used >= $rows) {
                break;
            }
        }

        $hidden = count($liveOutputs) - $shown;
        if ($hidden > 0 && $used < $rows) {
            $tiles[] = Style::new()
                ->foreground($theme->shellMuted)
                ->render('  + ' . $hidden . ' more agent(s)…');
        }

        return self::clip(implode("\n", $tiles), $width, $rows);
    }

    /**
     * A live agent's display state.
     *
     * The manager is optional and THE AGENT IS ROUTINELY UNREGISTERED: the
     * caller derives the name list from {@see AgentManager::liveOutputs()},
     * which is keyed off the SUB-AGENT map, and the agents a workflow's
     * parallel stage spawns are ad-hoc — built from `$task->name ??
     * $task->agentType` and never passed to `register()`. So `get()` returning
     * null is the ordinary case here, not the defensive one, and every field
     * below has to read sensibly without it.
     *
     * Status comes from {@see AgentManager::isWorking()} rather than from the
     * registration's `isActive` flag, which mirrors what {@see
     * AgentManager::active()} does and is the only reading that survives both
     * shapes: `Bootstrap::agentRoster()` registers its roster INACTIVE, so a
     * registered agent that IS mid-flight would otherwise render "stopped"
     * beside its own streaming output. `isWorking()` consults the sub-agent
     * map, so it answers for unregistered names too.
     */
    private static function state(string $name, string $output, ?AgentManager $manager): AgentOutputState
    {
        $agent = $manager?->get($name);

        return AgentOutputState::fromDisplayState(
            AgentDisplayState::new(
                name: self::safe($name),
                status: ($manager?->isWorking($name) ?? true) ? 'working' : 'stopped',
                operation: self::safe($agent->description ?? ''),
                // Telemetry keys off the RAW name — sanitising is a display
                // concern and a sanitised key misses the map.
                elapsedSeconds: $manager?->elapsedSeconds($name) ?? 0,
                tokensUsed: $manager?->tokensUsed($name) ?? 0,
                costUsd: $manager?->costUsd($name) ?? 0.0,
            ),
            model: self::safe($agent->model ?? ''),
            outputBuffer: self::tail($output),
        );
    }

    /**
     * The bounded, sanitised tail of one output buffer.
     *
     * Byte-bounded first so a megabyte of streamed output is not exploded into
     * an array to throw all but eight entries away; the first surviving line is
     * dropped when the cut happened mid-buffer because a byte cut lands
     * mid-line and mid-codepoint.
     *
     * @return list<string>
     */
    private static function tail(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $cut = strlen($output) > self::TAIL_BYTES;
        $tail = $cut ? substr($output, -self::TAIL_BYTES) : $output;

        $lines = explode("\n", rtrim($tail, "\n"));
        if ($cut && count($lines) > 1) {
            array_shift($lines);
        }

        return array_map(
            self::safe(...),
            array_values(array_slice($lines, -self::TAIL_LINES)),
        );
    }

    /** This column's untrusted-text boundary; see the class docblock. */
    private static function safe(string $raw): string
    {
        return PaneLabel::safe($raw);
    }

    /** Hold the block to its exact budget in both axes; see the class docblock. */
    private static function clip(string $block, int $width, int $rows): string
    {
        $lines = explode("\n", $block);
        if (count($lines) > $rows) {
            $lines = array_slice($lines, 0, $rows);
        }

        foreach ($lines as $i => $line) {
            if (Width::string($line) > $width) {
                $lines[$i] = Width::truncateAnsi($line, $width);
            }
        }

        return implode("\n", $lines);
    }
}
