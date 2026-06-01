<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions, FigletText, Marquee};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, BorderText};
use SugarCraft\Dash\Components\Feedback\LoadingText;

/**
 * Dashboard Text - showcasing text components
 *
 * Shows FigletText, BorderText, Marquee, and LoadingText components.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Text Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Figlet + Border Text (2 columns)
// ============================================
$figlet = FigletText::new('DASH');
$figletFrame = Card::titled($figlet, 'Figlet Text');

$borderText = BorderText::new('IMPORTANT');
$borderTextFrame = Card::titled($borderText, 'Border Text');

$grid->addItem(
    $figletFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    $borderTextFrame,
    new ItemOptions(column: 1, expandVertical: false)
);

// ============================================
// ROW 2: Marquee + Loading Text (2 columns)
// ============================================
$marquee = Marquee::new('Welcome to SugarDash!');
$marqueeFrame = Card::titled($marquee, 'Marquee');

$loadingText = LoadingText::new('Processing...');
$loadingTextFrame = Card::titled($loadingText, 'Loading Text');

$grid->addItem(
    $marqueeFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $loadingTextFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->setSize(100, 20);
echo $grid->render();
