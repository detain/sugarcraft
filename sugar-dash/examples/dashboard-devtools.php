<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Diff};
use SugarCraft\Dash\Components\System\{LogViewer, Console, Terminal, HexDump};

// Dashboard Developer Tools Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Log viewer - requires array of entries
$logViewer = LogViewer::new([
    ['message' => 'Application started', 'severity' => 'info'],
    ['message' => 'Loading configuration...', 'severity' => 'debug'],
    ['message' => 'Warning: Low memory', 'severity' => 'warning'],
    ['message' => 'Error: Connection failed', 'severity' => 'error'],
]);

// Console
$console = Console::new();

// Terminal
$terminal = Terminal::new();

// Hex dump
$hexDump = HexDump::new('Hello, SugarDash! This is a test string for hex dump display.');

// Diff
$diff = Diff::new(
    "Line 1: Old content\nLine 2: Original text\nLine 3: More old content\nLine 4: Final line",
    "Line 1: New content\nLine 2: Modified text\nLine 3: More new content\nLine 4: Final line"
);

$topRow = HStack::spaced(2,
    Card::titled($logViewer, 'Log Viewer'),
    Card::titled($console, 'Console')
);

$middleRow = HStack::spaced(2,
    Card::titled($terminal, 'Terminal'),
    Card::titled($hexDump, 'Hex Dump')
);

$bottomRow = Card::titled($diff, 'Diff View');

$mainContent = VStack::spaced(2, $topRow, $middleRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Developer Tools Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
