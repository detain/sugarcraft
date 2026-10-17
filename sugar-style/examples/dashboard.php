<?php

declare(strict_types=1);

/**
 * A small dashboard built with JoinHorizontal / JoinVertical / Place.
 * Demonstrates the Layout helpers from Phase 1.
 *
 *   php examples/dashboard.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;
use SugarCraft\Style\Table\Table;

$header = Style::new()
    ->bold()
    ->foreground(Color::hex('#ff5fd2'))
    ->padding(0, 2)
    ->render('  SugarCraft Dashboard  ');

$status = Style::new()
    ->foreground(Color::ansi(10))
    ->padding(1, 2)
    ->border(Border::rounded())
    ->borderForeground(Color::ansi(8))
    ->render("✓ All systems nominal");

$metrics = Table::new()
    ->headers('Metric',  'Value')
    ->row('Tests',       '1,023')
    ->row('Assertions',  '5,775')
    ->row('Libraries',   '13')
    ->row('PHP version', '8.1+')
    ->border(Border::rounded())
    ->styleFunc(static fn(int $row): Style
        => $row === Table::HEADER_ROW
            ? Style::new()->bold()->foreground(Color::ansi(13))
            : Style::new())
    ->render();

$row = Layout::joinHorizontal(Position::TOP, $status . '  ', $metrics);

echo Layout::joinVertical(Position::LEFT, $header, '', $row) . "\n";
