<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Diff};
use SugarCraft\Dash\Components\System\{LogViewer, Console, Terminal, HexDump};

/**
 * Dashboard Developer Tools - showcasing dev utility components
 *
 * Shows LogViewer, Console, Terminal, HexDump, and Diff components
 * in a multi-column framed layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Developer Tools Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Log Viewer + Console (2 columns)
// ============================================
$logViewer = LogViewer::new([
    ['message' => 'Application started', 'severity' => 'info'],
    ['message' => 'Loading configuration...', 'severity' => 'debug'],
    ['message' => 'Warning: Low memory', 'severity' => 'warning'],
    ['message' => 'Error: Connection failed', 'severity' => 'error'],
]);
$logFrame = Card::titled($logViewer, 'Log Viewer');

$console = Console::new();
$consoleFrame = Card::titled($console, 'Console');

$grid->addItem(
    $logFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $consoleFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 2: Terminal + Hex Dump (2 columns)
// ============================================
$terminal = Terminal::new();
$terminalFrame = Card::titled($terminal, 'Terminal');

$hexDump = HexDump::new('Hello, SugarDash! This is a test string for hex dump display.');
$hexFrame = Card::titled($hexDump, 'Hex Dump');

$grid->addItem(
    $terminalFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $hexFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: Diff View (full width)
// ============================================
$diff = Diff::new(
    "Line 1: Old content\nLine 2: Original text\nLine 3: More old content\nLine 4: Final line",
    "Line 1: New content\nLine 2: Modified text\nLine 3: More new content\nLine 4: Final line"
);
$diffFrame = Card::titled($diff, 'Diff View');

$grid->addItem(
    $diffFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(100, 30);
echo $grid->render();
