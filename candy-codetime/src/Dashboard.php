<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * Read-only dashboard Model. Loads the last `$days` days of
 * heartbeats once, computes Stats, and renders. `r` reloads from
 * disk, `←/→` shifts the window by one day, `q` quits.
 *
 * Reads through the {@see HeartbeatReader} contract, so the same Model
 * runs over the JSONL store or the SQLite backend — whichever the
 * `--backend=` option picked at launch.
 */
final class Dashboard implements Model
{
    public function __construct(
        public readonly HeartbeatReader $source,
        public readonly \DateTimeImmutable $endDay,
        public readonly int $days = 7,
        public readonly Stats $stats = new Stats([], []),
        public readonly ?int $width = null,
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
        );
    }

    private function shift(int $direction): self
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $next  = $this->endDay->modify(($direction > 0 ? '+' : '-') . abs($direction) . ' days');
        if ($next > $today) {
            $next = $today;
        }
        return (new self($this->source, $next, $this->days))->recompute();
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
