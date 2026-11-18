<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/** A static frame of about 1 MiB, enough to overrun a unix socket buffer. */
final class BigFrameModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return str_repeat("0123456789abcdef\n", intdiv(1024 * 1024, 17));
    }
}
