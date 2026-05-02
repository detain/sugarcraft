<?php

declare(strict_types=1);

/**
 * Table — styled table with rounded border, custom alignment, and
 * a styleFunc that paints alternate rows.
 *
 *   php examples/table.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Align;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;
use CandyCore\Sprinkles\Table\Table;

$table = Table::new()
    ->border(Border::rounded())
    ->headers('Lib', 'Stars', 'Year')
    ->rows([
        ['CandyCore',    '110', '2026'],
        ['CandySprinkles', '92', '2026'],
        ['SugarBits',     '88', '2026'],
        ['CandyShell',    '76', '2026'],
        ['HoneyBounce',   '54', '2026'],
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
