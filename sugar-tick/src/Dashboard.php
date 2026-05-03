<?php

declare(strict_types=1);

namespace CandyCore\Tick;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Read-only dashboard Model. Loads the last `$days` days of
 * heartbeats once, computes Stats, and renders. `r` reloads from
 * disk, `←/→` shifts the window by one day, `q` quits.
 */
final class Dashboard implements Model
{
    public function __construct(
        public readonly Store $store,
        public readonly \DateTimeImmutable $endDay,
        public readonly int $days = 7,
        public readonly Stats $stats = new Stats([], []),
    ) {}

    public static function start(Store $store, ?\DateTimeImmutable $endDay = null, int $days = 7): self
    {
        $end = ($endDay ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        return (new self($store, $end, $days))->reload();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
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
        $from = $this->endDay->modify('-' . ($this->days - 1) . ' days');
        $beats = $this->store->loadRange($from, $this->endDay);
        return new self(
            $this->store,
            $this->endDay,
            $this->days,
            Stats::compute($beats, $from, $this->endDay),
        );
    }

    private function shift(int $direction): self
    {
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $next  = $this->endDay->modify(($direction > 0 ? '+' : '-') . abs($direction) . ' days');
        if ($next > $today) {
            $next = $today;
        }
        return (new self($this->store, $next, $this->days))->reload();
    }
}
