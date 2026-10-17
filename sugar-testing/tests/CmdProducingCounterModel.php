<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\View;

/**
 * CounterModel variant that emits a non-null cmd from init().
 * Used to exercise ProgramSimulator's fakeCmdRunner branch.
 */
final class CmdProducingCounterModel implements Model
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
        return function (): void {
            $this->count++;
        };
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof Msg\KeyMsg && $msg->type === KeyType::Char && $msg->rune === '+') {
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
