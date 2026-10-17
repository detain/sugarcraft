<?php

declare(strict_types=1);

/**
 * Multiple-view pattern — keep several view bodies behind one
 * Model and switch between them via state.
 *
 *   php examples/views.php
 *
 * Press 1, 2, 3 to switch views. Press 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class Views implements Model
{
    public function __construct(public readonly int $current = 0)
    {
    }

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
        return match ($msg->rune) {
            '1' => [new self(0), null],
            '2' => [new self(1), null],
            '3' => [new self(2), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $views = [
            "🍬  Welcome screen\n\nThree-step wizard, view 1 of 3.\nPress 2 to advance.",
            "🚀  Configure step\n\nCheck things, type things.\nPress 3 to advance.",
            "🎉  Done!\n\nYou made it. Press 1 to start over.",
        ];
        return $views[$this->current] . "\n\n(1/2/3 to switch, q to quit)\n";
    }
}

(new Program(new Views()))->run();
