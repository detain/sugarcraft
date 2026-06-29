<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\View;

/**
 * CounterModel variant whose init() closure returns a KeyMsg('+').
 *
 * This exercises the init-cmd message-threading path: the init closure
 * produces a Msg that should be fed through update() as the first message.
 */
final class MsgProducingInitModel implements Model
{
    private int $count;

    public function __construct(int $initial = 0)
    {
        $this->count = $initial;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function init(): ?\Closure
    {
        // Return a closure that produces a KeyMsg('+') — this Msg
        // should be threaded through update() as the first message.
        return static fn (): Msg => new KeyMsg(
            type: KeyType::Char,
            rune: '+',
            alt: false,
            ctrl: false,
            shift: false,
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === '+') {
            return [new self($this->count + 1), null];
        }
        return [$this, null];
    }

    public function view(): string|View
    {
        return "Count: {$this->count}\n";
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
