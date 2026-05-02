<?php

declare(strict_types=1);

namespace App;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

/**
 * Demo Model — a counter you can drive with ↑ / ↓ and quit with `q`.
 *
 * Picked deliberately as the "hello, world" of TUI development: small
 * enough to read in one screen, but exercises every piece of the
 * runtime: Model immutability, Msg dispatch, Cmd::quit() shutdown,
 * keyboard handling, and CandySprinkles styling in view().
 *
 * The Model is immutable — `update()` returns a *new* Counter
 * rather than mutating $n. This is the Elm-architecture pattern
 * CandyCore is built on: state lives in value objects, transitions
 * are pure functions, side effects (timers, HTTP, file I/O) get
 * scheduled as Cmds.
 *
 * To extend:
 *   1. Add a field to the constructor (e.g. `int $step = 1`).
 *   2. Add a Msg handler in update() that returns a new Model with
 *      the field updated.
 *   3. Reflect the new state in view().
 */
final class Counter implements Model
{
    public function __construct(public readonly int $n = 0) {}

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
        return [match ($msg->type) {
            KeyType::Up   => new self($this->n + 1),
            KeyType::Down => new self($this->n - 1),
            default       => $this,
        }, null];
    }

    public function view(): string
    {
        $body = sprintf("  count: %d  \n  ↑ ↓ to change · q to quit  ", $this->n);
        return Style::new()
            ->border(Border::rounded())
            ->padding(1, 2)
            ->render($body);
    }
}
