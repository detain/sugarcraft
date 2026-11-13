<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\CodeTime\Storage\SqliteBackend;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Read-only dashboard Model. Loads the last `$days` days of
 * heartbeats once, computes Stats, and renders. `r` reloads from
 * disk, `←/→` shifts the window by one day, `q`/`Esc` quits.
 *
 * Reads through the {@see HeartbeatReader} contract, so the same Model
 * runs over the JSONL store or the SQLite backend — whichever the
 * `--backend=` option picked at launch.
 */
final class Dashboard implements Model
{
    /**
     * The window ladder (CT-S06): `+`/`-` step the visible range through
     * these widths. The endpoints are sticky — a stray keypress at 90 or 7
     * cannot run the view off into a single day or a full quarter+.
     */
    public const WINDOW_LADDER = [7, 14, 30, 90];

    /**
     * Window length in days. Validated at the door (CT-S16): a Dashboard can
     * never carry a zero, negative or wrapped window, so Renderer's label
     * re-derivation always paints exactly what the reader will serve.
     */
    public readonly int $days;

    /**
     * CT-S34: the palette choice travels with the model, so resize, shift,
     * window steps and the `t` flip all keep painting the theme the user
     * asked for — a rebuild that forgot it would silently repaint default.
     */
    public readonly Theme $theme;

    public function __construct(
        public readonly HeartbeatReader $source,
        public readonly \DateTimeImmutable $endDay,
        int $days = 7,
        public readonly Stats $stats = new Stats([], []),
        public readonly ?int $width = null,
        public readonly ?ColorProfile $colorProfile = null,
        public readonly ?int $height = null,
        /**
         * Which store this dashboard is actually reading (CT-S07): painted
         * as a `[file]`/`[sqlite]` badge so a split-store user is never
         * shown a number whose provenance is a guess.
         */
        public readonly ?string $backendLabel = null,
        /**
         * Lines the LAST load had to drop (CT-S19). Painted as a footer note
         * so corrupt day files are visible while the alt screen is live,
         * where writing them to stderr would shred the repaint.
         */
        public readonly int $droppedLines = 0,
        /** Day files the last load could not open at all (CT-S19). */
        public readonly int $unreadableFiles = 0,
        /**
         * Set when the last load threw (CT-S19): the previous Stats keep the
         * frame alive and this message rides the banner row, painted through
         * clean(), so a locked or swapped-out database never kills the TUI.
         */
        public readonly ?string $loadError = null,
        /**
         * CT-S30: false paints the lower ranking pane as By language; the `t`
         * key flips it to By tag. Same frame budget, same clip rules — the
         * toggle is a pure view flip over the already-loaded Stats.
         */
        public readonly bool $byTags = false,
        /**
         * CT-S31: the daily goal in seconds (CANDY_CODETIME_GOAL), or null
         * when no goal is set. The header paints today against it and timeline
         * slots that reach it show as full blocks — presentation riding the
         * existing buckets, no schema change anywhere.
         */
        public readonly ?int $goalSeconds = null,
        /** CT-S33/34: the palette this frame paints with. */
        ?Theme $theme = null,
    ) {
        $this->theme = $theme ?? Theme::default();
        // CT-S16 parse-at-boundary honesty: before this door, days=0 silently
        // widened into a two-day window, days=-5 sign-flipped the composed
        // modify() string, and PHP_INT_MAX wrapped around to zero (all
        // probe-verified). The library door now refuses the floor and caps at
        // the store's own ceiling, so no caller can steer a liar's label.
        if ($days < 1) {
            throw new \InvalidArgumentException('Dashboard window must cover at least one day, got ' . $days);
        }
        $this->days = min($days, Store::MAX_RANGE_DAYS);
        if ($goalSeconds !== null && $goalSeconds < 1) {
            throw new \InvalidArgumentException('Dashboard goal must be at least one second, got ' . $goalSeconds);
        }
    }

    public static function start(HeartbeatReader $source, ?\DateTimeImmutable $endDay = null, int $days = 7, ?string $backendLabel = null, ?int $goalSeconds = null, ?Theme $theme = null): self
    {
        $end = ($endDay ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        return (new self($source, $end, $days, backendLabel: $backendLabel, goalSeconds: $goalSeconds, theme: $theme))->reload();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self(
                $this->source,
                $this->endDay,
                $this->days,
                $this->stats,
                $msg->cols,
                $this->colorProfile,
                $msg->rows,
                $this->backendLabel,
                $this->droppedLines,
                $this->unreadableFiles,
                $this->loadError,
                $this->byTags,
                $this->goalSeconds,
                $this->theme,
            ), null];
        }
        if ($msg instanceof ColorProfileMsg) {
            // The profile Program detected is the authority on what the
            // terminal can render; swallowed pre-fix, the view re-derived a
            // partial answer from NO_COLOR alone.
            return [new self(
                $this->source,
                $this->endDay,
                $this->days,
                $this->stats,
                $this->width,
                $msg->profile,
                $this->height,
                $this->backendLabel,
                $this->droppedLines,
                $this->unreadableFiles,
                $this->loadError,
                $this->byTags,
                $this->goalSeconds,
                $this->theme,
            ), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reload(), null];
        }
        if ($msg->type === KeyType::Left) {
            return [$this->shift(-1), null];
        }
        if ($msg->type === KeyType::Right) {
            return [$this->shift(+1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === '+') {
            return [$this->stepWindow(+1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === '-') {
            return [$this->stepWindow(-1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 't') {
            return [new self(
                $this->source,
                $this->endDay,
                $this->days,
                $this->stats,
                $this->width,
                $this->colorProfile,
                $this->height,
                $this->backendLabel,
                $this->droppedLines,
                $this->unreadableFiles,
                $this->loadError,
                !$this->byTags,
                $this->goalSeconds,
                $this->theme,
            ), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function reload(): self
    {
        $this->source->invalidate();
        return $this->recompute();
    }

    private function recompute(): self
    {
        $from = $this->endDay->modify('-' . ($this->days - 1) . ' days');
        // CT-S19: storage trouble must never kill the running TUI. The last
        // good Stats ride; the fresh load's data-loss counts land on the
        // model's note channel, and anything that throws becomes an in-frame
        // banner (painted through clean()) instead of an uncaught exception
        // dumped onto the alt screen mid-repaint.
        try {
            $beats = $this->source->loadRange($from, $this->endDay);
            // CT-S08: the model carries the markers; a file reader has none.
            $milestones = $this->source instanceof SqliteBackend ? $this->source->milestones() : [];
            $notes = $this->source instanceof Store
                ? $this->source->drainDiagnostics()
                : ['dropped' => 0, 'unreadable' => 0];
            return new self(
                $this->source,
                $this->endDay,
                $this->days,
                Stats::compute($beats, $from, $this->endDay, $milestones),
                $this->width,
                $this->colorProfile,
                $this->height,
                $this->backendLabel,
                $notes['dropped'],
                $notes['unreadable'],
                null,
                $this->byTags,
                $this->goalSeconds,
                $this->theme,
            );
        } catch (\Throwable $error) {
            if ($this->source instanceof Store) {
                $this->source->drainDiagnostics();
            }
            return new self(
                $this->source,
                $this->endDay,
                $this->days,
                $this->stats,
                $this->width,
                $this->colorProfile,
                $this->height,
                $this->backendLabel,
                $this->droppedLines,
                $this->unreadableFiles,
                $error->getMessage(),
                $this->byTags,
                $this->goalSeconds,
                $this->theme,
            );
        }
    }

    private function shift(int $direction): self
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $next  = $this->endDay->modify(($direction > 0 ? '+' : '-') . abs($direction) . ' days');
        if ($next > $today) {
            $next = $today;
        }
        // CT-S16: the backward walk stops where the store's ceiling starts —
        // an endDay so early that the window would begin before the earliest
        // servable day is refused; a held left-arrow presses here and sticks.
        $floor = $today->modify('-' . (Store::MAX_RANGE_DAYS - $this->days) . ' days');
        if ($next < $floor) {
            $next = $floor;
        }
        // The width has to ride the shift: without it the next frame is laid
        // out for an unknown terminal and the rows we sized to the window
        // (panes, timeline) silently stop being clipped.
        return (new self($this->source, $next, $this->days, $this->stats, $this->width, $this->colorProfile, $this->height, $this->backendLabel, byTags: $this->byTags, goalSeconds: $this->goalSeconds, theme: $this->theme))->recompute();
    }

    /**
     * Step the window up or down the ladder. From a width the ladder does
     * not carry (the `dashboard [days]` argument) `+` snaps to the next
     * larger rung and `-` to the next smaller one; at the ends the current
     * window is returned untouched, so nothing recomputes for no reason.
     */
    private function stepWindow(int $direction): self
    {
        $days = self::nextWindow($this->days, $direction);
        if ($days === $this->days) {
            return $this;
        }
        return (new self(
            $this->source,
            $this->endDay,
            $days,
            $this->stats,
            $this->width,
            $this->colorProfile,
            $this->height,
            $this->backendLabel,
            byTags: $this->byTags,
            goalSeconds: $this->goalSeconds,
            theme: $this->theme,
        ))->recompute();
    }

    /**
     * The ladder neighbour of an arbitrary width in one direction; the width
     * itself when it is already the end rung.
     */
    public static function nextWindow(int $current, int $direction): int
    {
        if ($direction > 0) {
            foreach (self::WINDOW_LADDER as $rung) {
                if ($rung > $current) {
                    return $rung;
                }
            }
            return $current;
        }
        foreach (\array_reverse(self::WINDOW_LADDER) as $rung) {
            if ($rung < $current) {
                return $rung;
            }
        }
        return $current;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
