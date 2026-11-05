<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;

// The README quickstart, runnable. Coordinates are zero-based
// (col, row); the CUP sequences the encoders emit are one-based.

// Create a 20x3 buffer filled with blank cells.
$buf = Buffer::new(20, 3);

// Set a cell at (col=5, row=1).
$buf = $buf->withCellAt(5, 1, Cell::new('H'));

// Blit a seeded 2x2 sub-buffer at origin — seeding matters: blitting an
// all-blank sub-buffer over blank cells is a visible no-op.
$sub = Buffer::new(2, 2);
$sub = $sub->withCellAt(0, 0, Cell::new('A'))->withCellAt(1, 0, Cell::new('B'));
$sub = $sub->withCellAt(0, 1, Cell::new('C'))->withCellAt(1, 1, Cell::new('D'));
$buf = $buf->withRegion(Region::new(Position::new(0, 0), 2, 2), $sub);

// Read a cell back.
$cell = $buf->cellAt(5, 1);
echo $cell->rune(); // 'H'

// Paint the whole grid and show it. toAnsi() joins rows with a bare LF,
// which a canonical (ONLCR) line turns into CRLF; raw-mode callers must
// write "\r\n" or interpose CUP themselves — see the toAnsi() contract.
echo $buf->toAnsi();
