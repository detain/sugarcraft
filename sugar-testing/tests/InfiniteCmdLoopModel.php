<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\View;

/**
 * CounterModel variant that creates an infinite cmd loop.
 *
 * update() always returns a cmd that produces the same message,
 * causing an infinite loop. Used to exercise the cmd loop overflow
 * protection in ProgramSimulator::applyMsg().
 */
final class InfiniteCmdLoopModel implements Model
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

    public function update(Msg $msg): array
    {
        // Always return a cmd that produces a KeyMsg('+'), creating infinite loop.
        // The cmd loop detection should throw after 10,000 cycles.
        return [
            $this,
            static fn (): Msg => new KeyMsg(
                type: KeyType::Char,
                rune: '+',
                alt: false,
                ctrl: false,
                shift: false,
            ),
        ];
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
