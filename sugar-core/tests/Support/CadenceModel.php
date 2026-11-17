<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Support;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Counts applied CadenceBump messages in $count and view() calls in a shared
 * probe, so a test can tell how many frames the program painted.
 */
final class CadenceModel implements Model
{
    use SubscriptionCapable;

    public function __construct(private \stdClass $probe, public readonly int $count = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof CadenceBump) {
            return [new self($this->probe, $this->count + 1), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $this->probe->views++;
        return 'count: ' . $this->count;
    }
}
