<?php

declare(strict_types=1);

/**
 * Custom Msg + `Cmd::send` — dispatch a self-defined message into
 * the runtime to drive state transitions out-of-band from the
 * input loop.
 *
 *   php examples/send-msg.php
 *
 * Press space to schedule a tick that pings the model 1 sec later.
 * Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class PingMsg implements Msg
{
    public function __construct(public readonly int $count)
    {
    }
}

final class SendMsg implements Model
{
    public function __construct(public readonly int $pings = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Space) {
                // Schedule a delayed ping.
                $next = $this->pings + 1;
                return [$this, Cmd::tick(1.0, static fn (): Msg => new PingMsg($next))];
            }
        }
        if ($msg instanceof PingMsg) {
            return [new self($msg->count), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "Pings: {$this->pings}\n\n(space to schedule, q to quit)\n";
    }
}

(new Program(new SendMsg()))->run();
