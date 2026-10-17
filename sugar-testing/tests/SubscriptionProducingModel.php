<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;

/**
 * CounterModel variant that has subscriptions producing messages.
 *
 * Used to exercise ProgramSimulator's pumpSubscriptions() method.
 * The subscription produces a KeyMsg('*') on each pump.
 */
final class SubscriptionProducingModel implements Model
{
    private int $count;
    private bool $producedInitial = false;

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
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === '*') {
            return [new self($this->count + 1), null];
        }
        return [$this, null];
    }

    public function view(): string|View
    {
        return "Count: {$this->count}\n";
    }

    public function subscriptions(): ?Subscriptions
    {
        // Return a subscription that produces a KeyMsg on each produce() call.
        // This exercises pumpSubscriptions() which calls produce() and enqueues messages.
        return new Subscriptions([
            new \SugarCraft\Core\Subscription(
                id: 'test-sub',
                kind: \SugarCraft\Core\Kind::Custom,
                params: [],
                produce: static function (): Msg {
                    return new KeyMsg(
                        type: KeyType::Char,
                        rune: '*',
                        alt: false,
                        ctrl: false,
                        shift: false,
                    );
                },
            ),
        ]);
    }
}
