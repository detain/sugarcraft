<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, FigletText, Marquee};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, BorderText};
use SugarCraft\Dash\Components\Feedback\LoadingText;

// Dashboard Text Components Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Markdown
$markdown = "Hello\nThis is **bold** and *italic* text.";

// Figlet text
$figlet = FigletText::new('DASH');

// Border text
$borderText = BorderText::new('IMPORTANT');

// Marquee
$marquee = Marquee::new('Welcome to SugarDash!');

// Loading text
$loadingText = LoadingText::new('Processing...');

$topRow = HStack::spaced(2,
    Card::titled($figlet, 'Figlet Text'),
    Card::titled($borderText, 'Border Text')
);

$bottomRow = HStack::spaced(2,
    Card::titled($marquee, 'Marquee'),
    Card::titled($loadingText, 'Loading Text')
);

$mainContent = VStack::spaced(2, $topRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(new Text('Dashboard Text Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 20);
echo $grid->render();
