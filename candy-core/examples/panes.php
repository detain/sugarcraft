<?php

declare(strict_types=1);

/**
 * Demonstrates multiple viewport support via Panes.
 *
 * Two side-by-side panes show different content. Press Tab to switch
 * focus between panes. Press any other key to quit.
 *
 *   php examples/panes.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Pane;
use SugarCraft\Core\Panes;
use SugarCraft\Core\Rect;

/**
 * A simple counter model for the left pane.
 */
final class Counter implements Model
{
    private int $count = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Char && $msg->rune === '+') {
                $this->count++;
            } elseif ($msg->type === KeyType::Char && $msg->rune === '-') {
                $this->count--;
            }
        }
        return [$this, null];
    }

    public function view(): string
    {
        return <<<VIEW
        Counter: {$this->count}

        Press + or - to adjust.
        Press Tab to switch panes.
        Press any other key to quit.
        VIEW;
    }
}

/**
 * A text display model for the right pane.
 */
final class TextDisplay implements Model
{
    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return <<<VIEW
        Right Pane

        This pane shows static content.
        It demonstrates that different
        model types can coexist in a
        Panes composition.
        VIEW;
    }
}

// Build the two-pane layout: left half and right half of the terminal.
$leftPane = new Pane(
    new Counter(),
    new Rect(0, 0, 40, 24),
);

$rightPane = new Pane(
    new TextDisplay(),
    new Rect(40, 0, 40, 24),
);

$model = new Panes([$leftPane, $rightPane], activeIndex: 0);
(new Program($model))->run();
