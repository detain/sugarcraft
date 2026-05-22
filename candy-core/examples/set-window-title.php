<?php

declare(strict_types=1);

/**
 * `Cmd::setWindowTitle` — set the terminal-window title via OSC 0/2.
 * Most modern terminals respect this; some tile-based / multiplexer
 * setups override it.
 *
 *   php examples/set-window-title.php
 *
 * Press 1, 2, or 3 to flip titles. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class WindowTitle implements Model
{
    public function __construct(public readonly string $current = '(default)')
    {
    }

    public function init(): ?\Closure
    {
        return Cmd::setWindowTitle('SugarCraft demo');
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return match ($msg->rune) {
            '1' => [new self('🍬 candy land'),  Cmd::setWindowTitle('🍬 candy land')],
            '2' => [new self('🚀 rocketship'),  Cmd::setWindowTitle('🚀 rocketship')],
            '3' => [new self('🎉 party time'),  Cmd::setWindowTitle('🎉 party time')],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        return "Title: {$this->current}\n\n(1 / 2 / 3 to switch, q to quit)\n";
    }
}

(new Program(new WindowTitle()))->run();
