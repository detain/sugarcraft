<?php

declare(strict_types=1);

/**
 * Headless driver for the demo Model: feeds a fixed key sequence straight
 * into Counter::update() and prints each step, with no terminal, raw mode
 * or event loop. Copy it when you want to check a Model's transitions from
 * a script, a CI job or a cron run.
 *
 * Usage:
 *   php examples/headless.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Counter;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

// A list of [label, Msg] pairs, so the same label may appear twice.
$keys = [
    ['up', new KeyMsg(KeyType::Up, '')],
    ['up', new KeyMsg(KeyType::Up, '')],
    ['down', new KeyMsg(KeyType::Down, '')],
    ['x', new KeyMsg(KeyType::Char, 'x')],
    ['q', new KeyMsg(KeyType::Char, 'q')],
];

$model = new Counter();
printf("start  count=%d\n", $model->n);

foreach ($keys as [$label, $msg]) {
    [$model, $cmd] = $model->update($msg);
    printf("%-6s count=%d  cmd=%s\n", $label, $model->n, $cmd === null ? 'none' : 'quit');
}

echo $model->view(), "\n";
