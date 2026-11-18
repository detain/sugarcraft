<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/** Counts paints in $views; a BumpMsg makes the next view show one more. */
final class CountingModel implements Model
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
        return 'count ' . $this->n;
    }
}
