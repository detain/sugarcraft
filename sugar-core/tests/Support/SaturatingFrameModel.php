<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Every BumpMsg changes every row of a roughly 1 MiB frame, so each dirtied
 * frame is a full-size write: a stalled reader backs the writer up past its
 * saturation threshold after a handful of frames. $views counts paints.
 */
final class SaturatingFrameModel implements Model
{
    use SubscriptionCapable;

    public static int $views = 0;

    public function __construct(private readonly int $n = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return $msg instanceof BumpMsg ? [new self($this->n + 1), null] : [$this, null];
    }

    public function view(): string
    {
        self::$views++;
        return str_repeat(sprintf("%08d\n", $this->n), intdiv(1024 * 1024, 9));
    }
}
