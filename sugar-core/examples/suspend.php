<?php

declare(strict_types=1);

/**
 * `Cmd::suspend` — drop into a Ctrl+Z background, then resume on
 * SIGCONT. The runtime sends ResumeMsg when the program comes
 * back to the foreground.
 *
 *   php examples/suspend.php
 *
 * Press space to suspend the program. Run `fg` in your shell to
 * bring it back. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\ResumeMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Program;

final class SuspendDemo implements Model
{
    public function __construct(public readonly int $resumeCount = 0)
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
                return [$this, Cmd::suspend()];
            }
        }
        if ($msg instanceof ResumeMsg) {
            return [new self($this->resumeCount + 1), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "Suspend demo — resumed {$this->resumeCount} time(s).\n\n"
             . "(space to suspend, type `fg` to resume, q to quit)\n";
    }
}

(new Program(new SuspendDemo()))->run();
