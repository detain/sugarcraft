<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\View;

/**
 * A trivial counter model for self-testing ProgramSimulator.
 *
 * Demonstrates the TEA contract: init(), update(), view().
 */
final class CounterModel implements Model
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
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof Msg\KeyMsg) {
            if ($msg->type === KeyType::Char && $msg->rune === '+') {
                return [new self($this->count + 1), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === '-') {
                return [new self(max(0, $this->count - 1)), null];
            }
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
