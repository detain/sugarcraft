<?php

declare(strict_types=1);

/**
 * Help — render the short and full help views from a sample
 * KeyMap.
 *
 *   php examples/help.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Help\Help;
use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\KeyMap;

$bind = static fn(string $key, string $desc) =>
    (new Binding([$key]))->withHelp($key, $desc);

$keymap = new class($bind) implements KeyMap {
    public function __construct(private \Closure $bind) {}

    public function shortHelp(): array
    {
        $b = $this->bind;
        return [
            $b('↑/k',   'up'),
            $b('↓/j',   'down'),
            $b('enter', 'select'),
            $b('?',     'help'),
            $b('q',     'quit'),
        ];
    }

    public function fullHelp(): array
    {
        $b = $this->bind;
        return [
            [$b('↑/k', 'move up'),  $b('↓/j', 'move down'),
             $b('g',   'top'),     $b('G',   'bottom')],
            [$b('/',   'filter'),  $b('enter', 'select'), $b('esc', 'cancel')],
            [$b('?',   'toggle help'), $b('q', 'quit')],
        ];
    }
};

$help = new Help();

echo "\x1b[36mShort view (one line)\x1b[0m\n";
echo $help->shortView($keymap) . "\n\n";

echo "\x1b[36mFull view (grouped columns)\x1b[0m\n";
echo $help->fullView($keymap) . "\n";
