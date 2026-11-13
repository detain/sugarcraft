<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\CodeTime\Storage\HeartbeatReader;
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

    public function __construct(
        public readonly HeartbeatReader $source,
        public readonly \DateTimeImmutable $endDay,
        public readonly int $days = 7,
        public readonly Stats $stats = new Stats([], []),
        public readonly ?int $width = null,
        public readonly ?ColorProfile $colorProfile = null,
        public readonly ?int $height = null,
    ) {}

    public static function start(HeartbeatReader $source, ?\DateTimeImmutable $endDay = null, int $days = 7): self
    {
        $end = ($endDay ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        return (new self($source, $end, $days))->reload();
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
        $beats = $this->source->loadRange($from, $this->endDay);
        return new self(
            $this->source,
            $this->endDay,
            $this->days,
            Stats::compute($beats, $from, $this->endDay),
            $this->width,
            $this->colorProfile,
            $this->height,
        );
    }

    private function shift(int $direction): self
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $next  = $this->endDay->modify(($direction > 0 ? '+' : '-') . abs($direction) . ' days');
        if ($next > $today) {
            $next = $today;
        }
        // The width has to ride the shift: without it the next frame is laid
        // out for an unknown terminal and the rows we sized to the window
        // (panes, timeline) silently stop being clipped.
        return (new self($this->source, $next, $this->days, $this->stats, $this->width, $this->colorProfile, $this->height))->recompute();
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
