<?php

declare(strict_types=1);

/**
 * Window-size demo. Show the current terminal dimensions and update
 * them live as the user resizes the window (SIGWINCH).
 *
 *   php examples/window-size.php
 *
 * Resize the window. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;

final class WindowSize implements Model
{
    public function __construct(
        public readonly int $width  = 0,
        public readonly int $height = 0,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [new self($msg->width, $msg->height), null];
        }
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->width === 0) {
            return "Waiting for first WindowSizeMsg…\n\n(q to quit)\n";
        }
        return sprintf(
            "Window: %d cols × %d rows\n\n(resize to update, q to quit)\n",
            $this->width,
            $this->height,
        );
    }
}

(new Program(new WindowSize()))->run();
