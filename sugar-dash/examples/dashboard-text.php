<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, FigletText, Marquee};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Code, Kbd, BorderText, Markdown};
use SugarCraft\Dash\Components\Feedback\LoadingText;

// Dashboard Text Components Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Code block
$code = Code::new('echo "Hello, SugarDash!";');

// Markdown
$markdown = Markdown::new('# Hello
This is **bold** and *italic* text.

- List item 1
- List item 2');

// Keyboard key - use Kbd::combo for multiple keys
$kbd = Kbd::combo('Ctrl', 'S');

// Figlet text
$figlet = FigletText::new('DASH');

// Border text
$borderText = BorderText::new('IMPORTANT');

// Marquee
$marquee = Marquee::new('Welcome to SugarDash - The TUI Component Library!');

// Loading text
$loadingText = LoadingText::new('Processing...');

$topRow = HStack::spaced(2,
    Card::titled($code, 'Code'),
    Card::titled($kbd, 'Keyboard Key')
);

$middleRow = HStack::spaced(2,
    Card::titled($figlet, 'Figlet Text'),
    Card::titled($borderText, 'Border Text')
);

$bottomRow = HStack::spaced(2,
    Card::titled($marquee, 'Marquee'),
    Card::titled($loadingText, 'Loading Text')
);

$mainContent = VStack::spaced(2, $topRow, $middleRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Text Components Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
