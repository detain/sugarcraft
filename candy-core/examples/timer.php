<?php

declare(strict_types=1);

/**
 * Wall-clock timer driven by Cmd::tick.
 *
 *   php examples/timer.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;

final class TickMsg implements Msg {}

final class Timer implements Model
{
    public function __construct(public readonly int $seconds = 0) {}

    public function init(): ?\Closure
    {
        return Cmd::tick(1.0, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof TickMsg) {
            return [
                new self($this->seconds + 1),
                Cmd::tick(1.0, static fn(): Msg => new TickMsg()),
            ];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $h = intdiv($this->seconds, 3600);
        $m = intdiv($this->seconds % 3600, 60);
        $s = $this->seconds % 60;
        return sprintf("⏱  %02d:%02d:%02d\n\nq to quit\n", $h, $m, $s);
    }
}

(new Program(new Timer()))->run();
