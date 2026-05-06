<?php

declare(strict_types=1);

/**
 * Mouse demo — log every click / wheel / motion event the runtime
 * surfaces. Run in a terminal with mouse support enabled.
 *
 *   php examples/mouse.php
 *
 * Click anywhere, scroll, drag. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\MouseMode;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;

final class MouseDemo implements Model
{
    /** @param list<string> $log */
    public function __construct(public readonly array $log = []) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof MouseMsg) {
            $entry = sprintf(
                '[%s] %s @ (%d, %d)',
                date('H:i:s'),
                $msg->action->name,
                $msg->col,
                $msg->row,
            );
            $log = array_slice([...$this->log, $entry], -10);
            return [new self($log), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $body = $this->log === []
            ? "(move / click anywhere)"
            : implode("\n", $this->log);
        return "Mouse events — last 10:\n\n$body\n\n(q to quit)\n";
    }
}

(new Program(new MouseDemo(), new ProgramOptions(
    useAltScreen: true,
    mouseMode:    MouseMode::AllMotion,
)))->run();
