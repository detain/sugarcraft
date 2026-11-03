<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\EnvMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;

/**
 * Trace-recording model for the control-parity pins: appends a token per
 * message it is actually shown. Keys echo their rune, the startup trio
 * lands as `80x24` / `K=V` / `CP`, and ANY other message class lands as
 * `<ClassName>` — so a control message leaking through the simulator's
 * dispatch ladder shows up as a visible token, not silence.
 *
 * A `q` key returns {@see Cmd::quit()}: exactly one QuitMsg, which lets a
 * test pin that the stop is honoured without a second producer.
 */
final class ControlRecorderModel implements Model
{
    public function __construct(
        public readonly string $trace = '',
    ) {
    }

    public function init(): ?\Closure
    {
        return Cmd::send(new KeyMsg(KeyType::Char, rune: 'I'));
    }

    public function update(Msg $msg): array
    {
        $token = match (true) {
            $msg instanceof KeyMsg         => $msg->rune !== '' ? $msg->rune : '<' . $msg->type->name . '>',
            $msg instanceof WindowSizeMsg  => $msg->cols . 'x' . $msg->rows,
            $msg instanceof EnvMsg         => 'K=' . ($msg->vars['K'] ?? '?'),
            $msg instanceof ColorProfileMsg => 'CP',
            default                        => '<' . (new \ReflectionClass($msg))->getShortName() . '>',
        };

        $next = new self($this->trace . $token);

        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            return [$next, Cmd::quit()];
        }

        return [$next, null];
    }

    public function view(): string
    {
        return $this->trace;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
