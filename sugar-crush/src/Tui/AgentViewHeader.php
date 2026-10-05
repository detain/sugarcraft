<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Live\AgentLiveState;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\PaneLabel;
use SugarCraft\Crush\Util\TokenCount;
use SugarCraft\Mouse\Mark;
use SugarCraft\Sprinkles\Style;

/**
 * The one row pinned above the read-only Agent View (roadmap P-C2, Appendix P
 * §5.2): where the user is, and how the run they are looking at is doing.
 *
 *     main ▸ explore  ‹ 1 of 3 ›  ⠋ running · step 4/50 · 0:12 · 4.1K tok · $0.0021   esc back
 *
 * - `main` is the breadcrumb back to the parent transcript — an
 *   `agent-nav:main` click zone ({@see BACK_ZONE}), the mouse twin of `Esc`.
 * - `‹ i of N ›` names the run's place among its siblings (the runs of the
 *   same Task batch) when it has any; each arrow is an `agent-nav:<runId>`
 *   zone naming that sibling, so a click opens it. Not the strip's
 *   `agent:<runId>`: the strip shows the same runs in the same frame, and two
 *   zones with one id make the scan throw.
 * - The status is {@see AgentActivityLine::glyph()}'s glyph plus a word, then
 *   the step, the clock, the tokens and the cost the frames reported.
 *
 * WIDTH-SAFE BY CONSTRUCTION, like {@see AgentActivityLine}: the figures are
 * dropped (cost, tokens, step, clock), then the hint, then the sibling
 * counter, and the name is clipped to what is left — the row is never wider
 * than its width. Zones are marked only after that layout, so none is cut.
 *
 * The name is agent-originated (a preset's `name:`), so it passes
 * {@see PaneLabel::safe()}: no escape, control or Private-Use code point — the
 * range the zone sentinels live in — reaches the frame.
 */
final class AgentViewHeader
{
    /** The breadcrumb's click zone: back to the parent transcript. */
    public const BACK_ZONE = 'agent-nav:main';

    /** The prefix of the header's own navigation zones ({@see BACK_ZONE}). */
    public const NAV_ZONE_PREFIX = 'agent-nav:';

    public const CRUMB = 'main';

    public const ARROW = ' ▸ ';

    public const SEPARATOR = ' · ';

    /** The key that leaves the view, said on the row itself. */
    public const HINT = 'esc back';

    /** What the view shows for a worker that wrote no transcript of its own. */
    public const NO_TRANSCRIPT = 'No transcript was recorded for this run — live transcripts need ext-pcntl and a saved session.';

    /** What it shows before the run's log has its first line. */
    public const WAITING = 'Waiting for the agent\'s first step…';

    /** The same charset {@see Mark} accepts for an id. */
    private const ZONE_ID_CHARSET = '/\A[A-Za-z0-9._:-]+\z/';

    private function __construct()
    {
    }

    /**
     * The header row.
     *
     * @param string              $name     the run's agent name (sanitised here)
     * @param AgentLiveState|null $state    the run's live state, or null for a
     *        worker the frames never described (a workflow stage, a stored run)
     * @param list<string>        $siblings the run ids of its Task batch, in
     *        spawn order, itself included ([] or one id: no counter)
     * @param bool                $zones    mark the crumb and the arrows as click zones
     */
    public static function render(
        string $name,
        ?AgentLiveState $state,
        array $siblings,
        int $width,
        Theme $theme,
        int $spinnerFrame,
        float $now,
        bool $zones = false,
    ): string {
        if ($width <= 0) {
            return '';
        }

        $name = PaneLabel::safe($name);
        if ($name === '') {
            $name = 'agent';
        }

        $at = $state === null ? -1 : array_search($state->id, $siblings, true);
        $at = \is_int($at) ? $at : -1;
        $counter = \count($siblings) > 1 && $at >= 0 ? sprintf('‹ %d of %d ›', $at + 1, \count($siblings)) : '';

        $status = '';
        $glyph = '';
        $glyphColor = $theme->systemLabel;
        $figures = [];
        if ($state !== null) {
            [$glyph, $glyphColor] = AgentActivityLine::glyph($state, $theme, $spinnerFrame);
            $status = self::statusWord($state);
            // Display order; each carries its drop rank (lowest goes first).
            if ($state->maxSteps > 0 || $state->step > 0) {
                $figures[] = [1, $state->maxSteps > 0 ? "step {$state->step}/{$state->maxSteps}" : "step {$state->step}"];
            }
            $elapsed = $state->elapsed($now);
            if ($elapsed !== null) {
                $figures[] = [3, self::clock($elapsed)];
            }
            if ($state->tokens() > 0) {
                $figures[] = [2, TokenCount::compact($state->tokens()) . ' tok'];
            }
            if ($state->costUsd > 0.0) {
                $figures[] = [0, sprintf('$%.4f', $state->costUsd)];
            }
        }

        $hint = self::HINT;
        $crumb = self::CRUMB . self::ARROW;
        $layout = static function (array $figures, string $hint, string $counter) use ($crumb, $glyph, $status): int {
            $cells = Width::string($crumb);
            if ($counter !== '') {
                $cells += 2 + Width::string($counter);
            }
            if ($status !== '') {
                $cells += 2 + Width::string($glyph . ' ' . $status);
            }
            foreach ($figures as [, $text]) {
                $cells += Width::string(self::SEPARATOR . $text);
            }
            if ($hint !== '') {
                $cells += 3 + Width::string($hint);
            }

            return $cells;
        };

        // Keep at least this much of the name before anything else is cut.
        $nameFloor = min(Width::string($name), 8);
        while ($layout($figures, $hint, $counter) + $nameFloor > $width) {
            if ($figures !== []) {
                $lowest = 0;
                foreach ($figures as $index => [$rank]) {
                    if ($rank < $figures[$lowest][0]) {
                        $lowest = $index;
                    }
                }
                array_splice($figures, $lowest, 1);
                continue;
            }
            if ($hint !== '') {
                $hint = '';
                continue;
            }
            if ($counter !== '') {
                $counter = '';
                continue;
            }
            break;
        }

        $room = $width - $layout($figures, $hint, $counter);
        if ($room < 1) {
            // Too narrow for the crumb and one cell of name: the plain row, cut.
            return Width::truncate(self::CRUMB . self::ARROW . $name, $width);
        }
        if (Width::string($name) > $room) {
            $name = Width::truncate($name, $room);
        }

        $dim = Style::new()->foreground($theme->systemLabel);
        $crumbPainted = Style::new()->foreground($theme->assistantLabel)->underline()->render(self::CRUMB);
        if ($zones) {
            $crumbPainted = Mark::zone(self::BACK_ZONE, $crumbPainted);
        }

        $row = $crumbPainted . $dim->render(self::ARROW) . Style::new()->bold()->render($name);
        if ($counter !== '') {
            $row .= '  ' . self::counter($counter, $siblings, $at, $dim, $zones);
        }
        if ($status !== '') {
            $tail = '';
            foreach ($figures as [, $text]) {
                $tail .= self::SEPARATOR . $text;
            }
            $row .= '  ' . Style::new()->foreground($glyphColor)->render($glyph) . ' ' . $dim->render($status . $tail);
        }
        if ($hint !== '') {
            $row .= '   ' . $dim->render($hint);
        }

        return $row;
    }

    /**
     * The status word after the glyph: what the run is doing, or how it ended.
     */
    public static function statusWord(AgentLiveState $state): string
    {
        if ($state->isQueued()) {
            return 'queued';
        }
        if (!$state->isFinished()) {
            return 'running';
        }

        return match ($state->outcome) {
            SubAgentActivity::OUTCOME_COMPLETE => 'done',
            SubAgentActivity::OUTCOME_CANCELLED => 'cancelled',
            SubAgentActivity::OUTCOME_EMPTY => 'stopped without a report',
            SubAgentActivity::OUTCOME_BACKGROUNDED => 'in the background',
            default => 'failed',
        };
    }

    /**
     * `‹ i of N ›` with each arrow its sibling's zone. An arrow with no
     * sibling on its side is drawn dim and unzoned.
     *
     * @param list<string> $siblings
     */
    private static function counter(string $counter, array $siblings, int $at, Style $dim, bool $zones): string
    {
        $middle = mb_substr($counter, 1, -1);
        $prev = $siblings[$at - 1] ?? null;
        $next = $siblings[$at + 1] ?? null;

        return self::arrow('‹', $prev, $dim, $zones) . $dim->render($middle) . self::arrow('›', $next, $dim, $zones);
    }

    private static function arrow(string $glyph, ?string $runId, Style $dim, bool $zones): string
    {
        if ($runId === null) {
            return $dim->render($glyph);
        }

        $painted = Style::new()->bold()->render($glyph);
        $zoneId = self::NAV_ZONE_PREFIX . $runId;
        if (!$zones || preg_match(self::ZONE_ID_CHARSET, $runId) !== 1 || \strlen($zoneId) > Mark::MAX_ID_BYTES) {
            return $painted;
        }

        return Mark::zone($zoneId, $painted);
    }

    private static function clock(float $seconds): string
    {
        $whole = (int) $seconds;

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}
