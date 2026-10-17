<?php

declare(strict_types=1);

/**
 * `Cmd::sequence` — run a list of Cmds one after the other,
 * waiting for each to dispatch its Msg before starting the
 * next. Compare with `Cmd::batch` which fires every Cmd in
 * parallel.
 *
 *   php examples/sequence.php
 *
 * Press 'q' to quit. The model logs each step as it arrives.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class StepMsg implements Msg
{
    public function __construct(public readonly string $label)
    {
    }
}

final class Sequence implements Model
{
    /** @param list<string> $log */
    public function __construct(public readonly array $log = [])
    {
    }

    public function init(): ?\Closure
    {
        // Sequenced steps: each tick fires its Msg before the next
        // tick starts.
        return Cmd::sequence(
            Cmd::tick(0.4, static fn () => new StepMsg('first')),
            Cmd::tick(0.4, static fn () => new StepMsg('second')),
            Cmd::tick(0.4, static fn () => new StepMsg('third')),
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof StepMsg) {
            return [new self([...$this->log, $msg->label]), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $body = $this->log === [] ? '(waiting…)' : implode("\n", $this->log);
        return "Sequence steps:\n\n$body\n\n(q to quit)\n";
    }
}

(new Program(new Sequence()))->run();
