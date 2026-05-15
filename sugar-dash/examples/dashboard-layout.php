<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, ZStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};

/**
 * Dashboard Layout - showcasing layout components
 *
 * Demonstrates StackedGrid, VStack, HStack, ZStack with proper framing.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$header = HStack::spaced(3,
    Text::new('SugarDash Layout Demo'),
    Text::new('v1.0.0')
);
$grid->addItem(
    Card::titled($header, ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Left Column with VStack of Cards
// ============================================
$leftColumn = VStack::spaced(1,
    Card::titled(Text::new('Panel 1'), 'Card 1'),
    Card::titled(Text::new('Panel 2'), 'Card 2'),
    Card::titled(Text::new('Panel 3'), 'Card 3')
);
$grid->addItem(
    $leftColumn,
    new ItemOptions(column: 0, expandVertical: true)
);

// ============================================
// ROW 2: Right Column with Wide Panels
// ============================================
$rightColumn = VStack::spaced(1,
    Card::titled(Text::new('Wide panel content goes here'), 'Wide Panel'),
    Card::titled(Text::new('Another panel'), 'Short Panel')
);
$grid->addItem(
    $rightColumn,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->setSize(100, 25);
echo $grid->render();
