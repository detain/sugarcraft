<?php

declare(strict_types=1);

/**
 * The simplest possible bubbletea program — print a fixed view, quit
 * on any key.
 *
 *   php examples/simple.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;

final class Hello implements Model
{
    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            return [$this, Cmd::quit()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "Hello, world.\n\n(press any key to quit)\n";
    }
}

(new Program(new Hello()))->run();
