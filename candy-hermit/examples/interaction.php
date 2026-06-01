<?php

declare(strict_types=1);

/**
 * candy-hermit — Hermit fuzzy finder with cursor + filtering demo.
 *
 *   php examples/interaction.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Hermit\FilteredItem;
use SugarCraft\Hermit\Hermit;

// Wrap plain strings in FilteredItem for the Item interface
$rawItems = [
    'composer', 'git', 'docker', 'docker-compose',
    'npm', 'node', 'php', 'phpunit',
    'symfony', 'laravel', 'vendor', 'var/',
    'storage/', 'tests/', 'public/', '.env',
    '.gitignore', 'README.md',
];
$items = [];
foreach ($rawItems as $n => $value) {
    $items[] = new FilteredItem($n + 1, $value);
}

// Background to composite the hermit overlay onto (10 lines)
$bg = str_repeat("example background line\n", 10);

// Build a Hermit instance
$hermit = Hermit::new($items)
    ->setPrompt('🔍 Search: ')
    ->setWindowHeight(10)
    ->show();

echo "=== Hermit fuzzy finder — initial state ===\n";
echo $hermit->view($bg) . "\n\n";

// Type some characters to filter
$hermit = $hermit->type('p');
echo "After type('p') — filter: '{$hermit->filterText()}'\n";
echo $hermit->view($bg) . "\n\n";

$hermit = $hermit->type('h');
echo "After type('ph') — filter: '{$hermit->filterText()}'\n";
echo $hermit->view($bg) . "\n\n";

// Cursor down
$hermit = $hermit->cursorDown();
echo "After cursorDown() — cursor index: {$hermit->cursor()}\n";
echo $hermit->view($bg) . "\n\n";

// Cursor to bottom
$hermit = $hermit->cursorBottom();
echo "After cursorBottom() — cursor index: {$hermit->cursor()}\n";
echo $hermit->view($bg) . "\n\n";

// Cursor up
$hermit = $hermit->cursorUp(2);
echo "After cursorUp(2) — cursor index: {$hermit->cursor()}\n";
echo $hermit->view($bg) . "\n\n";

// Backspace
$hermit = $hermit->backspace();
echo "After backspace() — filter: '{$hermit->filterText()}'\n";
echo $hermit->view($bg) . "\n\n";

// Clear filter
$hermit = $hermit->clear();
echo "After clear() — filter: '{$hermit->filterText()}'\n";
echo $hermit->view($bg) . "\n\n";

// Final state: cursor at bottom, filter cleared
echo "=== Final state ===\n";
echo $hermit->view($bg) . "\n";
