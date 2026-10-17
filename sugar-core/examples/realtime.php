<?php

declare(strict_types=1);

/**
 * Realtime updates — `Cmd::tick` for periodic refresh; the model
 * re-schedules itself after every tick to keep the loop alive.
 *
 *   php examples/realtime.php
 *
 * Watch the clock. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class TickMsg implements Msg
{
    public function __construct(public readonly float $now)
    {
    }
}

final class Realtime implements Model
{
    public function __construct(public readonly float $now = 0.0)
    {
    }

    public function init(): ?\Closure
    {
        return Cmd::tick(0.1, static fn (): Msg => new TickMsg(microtime(true)));
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof TickMsg) {
            return [
                new self($msg->now),
                Cmd::tick(0.1, static fn (): Msg => new TickMsg(microtime(true))),
            ];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->now === 0.0) {
            return "Starting clock…\n";
        }
        $pretty = date('H:i:s', (int) $this->now) . sprintf('.%03d', ($this->now - (int) $this->now) * 1000);
        return "Time: $pretty\n\n(q to quit)\n";
    }
}

(new Program(new Realtime()))->run();
