<?php

declare(strict_types=1);

/**
 * "Prevent quit" demo — eat Ctrl+C until the user confirms.
 *
 *   php examples/prevent-quit.php
 *
 * Press Ctrl+C. The first press flips the model into 'really quit?'
 * mode. Press 'y' to confirm or 'n' / Esc to cancel.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;

final class PreventQuit implements Model
{
    public function __construct(public readonly bool $confirming = false) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($this->confirming) {
            return match (true) {
                $msg->type === KeyType::Char && $msg->rune === 'y'
                    => [$this, Cmd::quit()],
                default
                    => [new self(false), null],
            };
        }
        if ($msg->ctrl && $msg->rune === 'c') {
            return [new self(true), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->confirming) {
            return "Really quit? (y / n)\n";
        }
        return "Working hard. Press Ctrl+C to attempt quit.\n";
    }
}

// Disable the runtime's built-in Ctrl-C handler so our update()
// gets the keystroke first. Quit happens explicitly via Cmd::quit().
(new Program(new PreventQuit(), new ProgramOptions(catchInterrupts: false)))->run();
