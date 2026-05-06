<?php

declare(strict_types=1);

/**
 * Focus / blur demo — terminals with FocusReportMode enabled emit
 * an event when you switch to / away from the terminal window. The
 * runtime surfaces these as FocusMsg / BlurMsg.
 *
 *   php examples/focus-blur.php
 *
 * Click another window, then click back. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\BlurMsg;
use CandyCore\Core\Msg\FocusMsg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;

final class FocusBlur implements Model
{
    public function __construct(public readonly bool $focused = true) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q'
                => [$this, Cmd::quit()],
            $msg instanceof FocusMsg => [new self(true),  null],
            $msg instanceof BlurMsg  => [new self(false), null],
            default                  => [$this, null],
        };
    }

    public function view(): string
    {
        $state = $this->focused ? 'focused' : 'blurred';
        return "Window state: $state\n\n(switch windows to test, q to quit)\n";
    }
}

(new Program(new FocusBlur(), new ProgramOptions(reportFocus: true)))->run();
