<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Style;

// The README worked example, runnable. Two 5x2 buffers: before (all blanks)
// and after (styled A at col 0, bold blue BBB at cols 1-3). The delta stream
// rides CUP + SGR + a literal run; a full toAnsi() repaint pays the blank
// sweep instead.

$prev = Buffer::new(5, 2);

$red = Style::new(0xFF0000);
$blueBold = Style::new(0x0000FF)->withAttrs(Style::ATTR_BOLD);

$curr = $prev
    ->withCellAt(0, 0, Cell::new('A', $red))
    ->withCellAt(1, 0, Cell::new('B', $blueBold))
    ->withCellAt(2, 0, Cell::new('B', $blueBold))
    ->withCellAt(3, 0, Cell::new('B', $blueBold));

$ops = $curr->diff($prev);

printf("delta ops (%d):\n", count($ops));
foreach ($ops as $op) {
    printf("  %s\n", substr($op::class, strrpos($op::class, '\\') + 1));
}

$bytes = (new DiffEncoder())->encode($ops);
printf("\nencoded delta: %d bytes\n", strlen($bytes));
printf("toAnsi() repaint of the same frame: %d bytes\n", strlen($curr->toAnsi()));
