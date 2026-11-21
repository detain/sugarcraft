<?php

declare(strict_types=1);

/**
 * A scripted game with no terminal, for reading the model without the runtime:
 *   php examples/headless-game.php < /dev/null
 *
 * The game is seeded, so the same seed always deals the same pieces. Each key
 * is sent to Game::update() as a KeyMsg, the same message the runtime delivers,
 * and the frame is printed as plain text with the colour codes removed. Nothing
 * is written to disk, because no scores path is given.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;

$seed = 42;
$deal = array_map(
    static fn ($piece) => $piece->name,
    Bag::seeded($seed)->peek(7),
);
echo 'seed ' . $seed . ' deals: ' . implode(' ', $deal) . "\n";

$game = Game::start(seed: $seed, reproducible: true);

// Space is a hard drop. Left and right move the piece, and up rotates it.
$script = [
    new KeyMsg(KeyType::Left, ''),
    new KeyMsg(KeyType::Char, ' '),
    new KeyMsg(KeyType::Up, ''),
    new KeyMsg(KeyType::Right, ''),
    new KeyMsg(KeyType::Char, ' '),
    new KeyMsg(KeyType::Char, ' '),
];
foreach ($script as $msg) {
    [$game, $cmd] = $game->update($msg);
}

$frame = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', Renderer::render($game));
echo $frame, "\n";
printf("points %d, lines %d, level %d\n", $game->score->points, $game->score->lines, $game->score->level);
