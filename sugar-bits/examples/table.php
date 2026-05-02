<?php

declare(strict_types=1);

/**
 * Table — render a styled tabular layout. Headers, rows, custom
 * widths, alignment, focus highlight.
 *
 *   php examples/table.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Table\Table;

$headers = ['Lang', 'Stars', 'Year'];
$rows = [
    ['Go',         '110k', '2009'],
    ['Rust',       ' 90k', '2010'],
    ['PHP',        ' 38k', '1995'],
    ['TypeScript', '100k', '2012'],
    ['Zig',        ' 35k', '2016'],
];

$table = Table::new($headers, $rows, width: 38, height: 7);

echo "\x1b[36mDefault rendering\x1b[0m\n";
echo $table->view() . "\n\n";

echo "\x1b[36mWith focus on row 3\x1b[0m\n";
[$focused] = $table->focus();
echo $focused->moveDown()->moveDown()->view() . "\n";
