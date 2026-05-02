<?php

declare(strict_types=1);

/**
 * The classic bubbletea counter app.
 *
 *   php examples/counter.php
 *
 * ↑ / ↓ to count, q to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;

final class Counter implements Model
{
    public function __construct(public readonly int $count = 0) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return match ($msg->type) {
            KeyType::Up   => [new self($this->count + 1), null],
            KeyType::Down => [new self($this->count - 1), null],
            default       => [$this, null],
        };
    }

    public function view(): string
    {
        return "Count: {$this->count}\n\n↑ / ↓ to count, q to quit\n";
    }
}

(new Program(new Counter()))->run();
