<?php

declare(strict_types=1);

/**
 * Drive the board without a terminal: the pure Board API, no Program loop.
 *
 *   php examples/headless.php
 *
 * Takes no input. The mine layout comes from a fixed-seed generator, so the
 * output is the same on every run. The example reveals one cell, prints the
 * grid as plain text, then reveals every safe cell and prints the win result.
 * Use it as the starting point for a bot, a solver or a test fixture.
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;

// A linear congruential generator makes the mine layout repeatable.
$seed = 7;
$rand = static function (int $n) use (&$seed): int {
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $seed % $n;
};

/** Render the board as plain text: '#' hidden, 'F' flag, '.' empty, digit count, '*' mine. */
$render = static function (Board $board): string {
    $lines = [];
    foreach ($board->rows() as $row) {
        $line = '';
        foreach ($row as $cell) {
            $line .= glyph($cell);
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
};

function glyph(Cell $cell): string
{
    if ($cell->flagged) {
        return 'F';
    }
    if (!$cell->revealed) {
        return '#';
    }
    if ($cell->mine) {
        return '*';
    }
    return $cell->adjacent === 0 ? '.' : (string) $cell->adjacent;
}

// Board::blank() builds the 8x5 grid with 6 mines. Mines are placed on the
// first reveal, so the first click is always safe.
$board = Board::blank(8, 5, 6);
$board = $board->reveal(0, 0, $rand);

echo "after revealing (0,0):\n";
echo $render($board), "\n";
echo 'won: ', $board->isWon() ? 'yes' : 'no', "\n\n";

// Reveal every cell that is not a mine. isWon() checks a counter, so the
// win test costs the same on a 9x9 board as on a 50x50 one.
foreach ($board->rows() as $y => $row) {
    foreach ($row as $x => $cell) {
        if (!$cell->mine) {
            $board = $board->reveal($x, $y, $rand);
        }
    }
}

echo "after revealing every safe cell:\n";
echo $render($board), "\n";
echo 'won: ', $board->isWon() ? 'yes' : 'no', "\n";
