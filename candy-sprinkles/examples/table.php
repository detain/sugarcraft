<?php

declare(strict_types=1);

/**
 * Table — styled table with rounded border, custom alignment, and
 * a styleFunc that paints alternate rows.
 *
 *   php examples/table.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Table\Table;

$table = Table::new()
    ->border(Border::rounded())
    ->headers('Lib', 'Stars', 'Year')
    ->rows([
        ['SugarCraft',    '110', '2026'],
        ['CandySprinkles', '92', '2026'],
        ['SugarWidgets',     '88', '2026'],
        ['CandyScriptui',    '76', '2026'],
        ['SugarSpring',   '54', '2026'],
    ])
    ->headerAlign(Align::Center)
    ->rowAlign(Align::Left)
    ->styleFunc(function (int $row, int $col): Style {
        $base = Style::new()->padding(0, 1);
        if ($row === 0) {
            return $base->bold()->foreground(Color::hex('#ff5fd2'));
        }
        return $row % 2 === 0
            ? $base->foreground(Color::hex('#fde68a'))
            : $base;
    });

echo $table . "\n";
