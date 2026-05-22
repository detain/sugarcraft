<?php

declare(strict_types=1);

/**
 * Focus / blur demo — terminals with FocusReportMode enabled emit
 * an event when you switch to / away from the terminal window. The
 * runtime surfaces these as FocusGainedMsg / BlurMsg.
 *
 *   php examples/focus-blur.php
 *
 * Click another window, then click back. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;

final class FocusBlur implements Model
{
    public function __construct(public readonly bool $focused = true)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return match (true) {
            $msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q'
                => [$this, Cmd::quit()],
            $msg instanceof FocusGainedMsg => [new self(true),  null],
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
