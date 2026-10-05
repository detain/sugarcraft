<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Live\AgentLiveRegistry;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Mouse\Mark;
use SugarCraft\Sprinkles\Style;

/**
 * The live agents strip: ONE faint row above the input box while delegated
 * runs are live (roadmap P-B3, Appendix P §4.5).
 *
 *     agents: ⠋ explore · ⠙ explore · ✗ reviewer   (alt+↓)
 *
 * It is the keyboard target for the runs a turn started. `Alt+↓` focuses it
 * ({@see \SugarCraft\Crush\Tui\KeyboardHandler}); then `←`/`→` move, `Enter`
 * opens the focused run, `c` stops it, `x` dismisses a finished one (or
 * stops a running one), and `Esc` or `Alt+↑` gives the keyboard back to the
 * input. The focus itself is the shell's state
 * ({@see \SugarCraft\Crush\App\App::$agentStripFocus}), named by run id so it
 * survives runs arriving and leaving.
 *
 * Claude Code's "panel below the prompt", compressed to one row because the
 * dock's Agents pane already gives the multi-row view.
 *
 * WHICH RUNS. Every run {@see AgentLiveRegistry} holds that is queued or
 * running, plus each finished one for {@see LINGER_SECONDS} after it ended —
 * measured on the registry's own clock, which only `update()` advances, so a
 * frame stays a pure function of state.
 *
 * WIDTH. One row no wider than the width it is given (the diff renderer is
 * one line per row): items are added in order while they fit, the rest
 * collapse into `+N more`, and the hint is the first thing dropped. A focused
 * item is always among the visible ones. Every name passes
 * {@see PaneLabel::safe()} — agent names are model- and file-supplied, and a
 * Private-Use code point in one would break every click zone after it.
 *
 * ZONES. Each visible item is an `agent:<runId>` click zone
 * ({@see ZONE_PREFIX}), marked after layout so no zone is ever cut.
 */
final class AgentStrip
{
    /** Click-zone prefix for one run: `agent:<runId>`, the run id {@see AgentLiveState::$id}. */
    public const ZONE_PREFIX = 'agent:';

    /** How long a finished run stays on the strip. */
    public const LINGER_SECONDS = 30.0;

    /** Lang key of the strip's leading label. */
    public const LABEL = 'tui.agent_strip.label';

    public const SEPARATOR = ' · ';

    /** Lang key of the hint while the strip does not hold the keyboard. */
    public const HINT = 'tui.agent_strip.hint';

    /** Lang key of the hint while the strip holds the keyboard. */
    public const FOCUSED_HINT = 'tui.agent_strip.focused_hint';

    /** Widest a run's name may be on the strip, in cells. */
    public const NAME_COLS = 20;

    /** The same charset {@see Mark} accepts for an id. */
    private const ZONE_ID_CHARSET = '/\A[A-Za-z0-9._:-]+\z/';

    private function __construct()
    {
    }

    /**
     * The runs the strip shows, in the registry's order, minus any the user
     * dismissed.
     *
     * @param array<string, true> $dismissed run ids taken off the strip
     *
     * @return list<AgentLiveState>
     */
    public static function items(AgentLiveRegistry $live, array $dismissed = []): array
    {
        $now = $live->now();
        $items = [];
        foreach ($live->all() as $state) {
            if (isset($dismissed[$state->id])) {
                continue;
            }
            if ($state->isFinished()) {
                $ended = $state->finishedAt ?? $now;
                if ($now - $ended > self::LINGER_SECONDS) {
                    continue;
                }
            }
            $items[] = $state;
        }

        return $items;
    }

    /**
     * Index of the run with $id in $items, or -1.
     *
     * @param list<AgentLiveState> $items
     */
    public static function indexOf(array $items, ?string $id): int
    {
        if ($id === null) {
            return -1;
        }
        foreach ($items as $index => $state) {
            if ($state->id === $id) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * The one row, or '' when there is nothing to show or no room.
     *
     * @param list<AgentLiveState> $items
     * @param ?string              $focused the focused run's id, highlighted (and kept visible)
     * @param bool                 $zones   wrap each visible item in its click zone
     */
    public static function render(array $items, int $width, Theme $theme, int $spinnerFrame, ?string $focused = null, bool $zones = false): string
    {
        if ($items === [] || $width <= 0) {
            return '';
        }

        $labels = [];
        foreach ($items as $state) {
            $name = PaneLabel::safe($state->name);
            if ($name === '') {
                $name = Lang::t('tui.agent.unnamed');
            }
            [$glyph] = AgentActivityLine::glyph($state, $theme, $spinnerFrame);
            $labels[] = $glyph . ' ' . Width::truncate($name, self::NAME_COLS);
        }

        $focusIndex = self::indexOf($items, $focused);
        $hint = '   ' . Lang::t($focusIndex >= 0 ? self::FOCUSED_HINT : self::HINT);

        // Start where the focused item stays visible: the window opens at it
        // when it would not otherwise fit.
        $start = 0;
        if ($focusIndex > 0 && self::fits($labels, 0, $focusIndex, $width, count($labels) - $focusIndex - 1) === false) {
            $start = $focusIndex;
        }

        // The hint goes first: it stays only while it costs no item.
        $shown = self::visible($labels, $start, $width);
        $tail = $shown > 0 && self::visible($labels, $start, $width - Width::string($hint)) === $shown ? $hint : '';
        if ($shown === 0) {
            // Not even one item: the label and the count, cut to the row.
            return Style::new()->foreground($theme->systemLabel)
                ->render(Width::truncate(Lang::t(self::LABEL) . ' +' . count($items), $width));
        }

        $dim = Style::new()->foreground($theme->systemLabel);
        $row = $dim->render(Lang::t(self::LABEL) . ' ');
        for ($i = $start; $i < $start + $shown; $i++) {
            if ($i > $start) {
                $row .= $dim->render(self::SEPARATOR);
            }
            $row .= self::item($items[$i], $labels[$i], $i === $focusIndex, $theme, $spinnerFrame, $zones);
        }
        $hidden = count($items) - $shown;
        if ($hidden > 0) {
            $row .= $dim->render(self::more($hidden));
        }
        if ($tail !== '') {
            $row .= $dim->render($tail);
        }

        return $row;
    }

    /** The `· +N more` trailer for runs that did not fit, separator included. */
    private static function more(int $count): string
    {
        return self::SEPARATOR . Lang::t('tui.agent_strip.more', ['count' => $count]);
    }

    /**
     * How many labels from $start fit in $width cells, counting the
     * separators and the `+N more` the rest would need.
     *
     * @param list<string> $labels
     */
    private static function visible(array $labels, int $start, int $width): int
    {
        $total = count($labels);
        $shown = 0;
        $used = Width::string(Lang::t(self::LABEL) . ' ');
        for ($i = $start; $i < $total; $i++) {
            $add = ($shown > 0 ? Width::string(self::SEPARATOR) : 0) + Width::string($labels[$i]);
            $left = $total - ($shown + 1);
            $more = $left > 0 ? Width::string(self::more($left)) : 0;
            if ($used + $add + $more > $width) {
                break;
            }
            $used += $add;
            $shown++;
        }

        return $shown;
    }

    /**
     * Whether labels $from..$to all fit on one row with $after more counted.
     *
     * @param list<string> $labels
     */
    private static function fits(array $labels, int $from, int $to, int $width, int $after): bool
    {
        $used = Width::string(Lang::t(self::LABEL) . ' ');
        for ($i = $from; $i <= $to; $i++) {
            $used += ($i > $from ? Width::string(self::SEPARATOR) : 0) + Width::string($labels[$i]);
        }
        if ($after > 0) {
            $used += Width::string(self::more($after));
        }

        return $used <= $width;
    }

    private static function item(AgentLiveState $state, string $label, bool $focused, Theme $theme, int $spinnerFrame, bool $zones): string
    {
        [, $color] = AgentActivityLine::glyph($state, $theme, $spinnerFrame);
        $style = Style::new()->foreground($color);
        $painted = $focused ? $style->reverse()->bold()->render($label) : $style->render($label);

        $zoneId = self::ZONE_PREFIX . $state->id;
        if (!$zones || preg_match(self::ZONE_ID_CHARSET, $state->id) !== 1 || strlen($zoneId) > Mark::MAX_ID_BYTES) {
            return $painted;
        }

        return Mark::zone($zoneId, $painted);
    }
}
