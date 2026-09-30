<?php

declare(strict_types=1);

/**
 * CandyHermit — basic fuzzy finder demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Hermit\Hermit;

$items = [
    'apple', 'banana', 'cherry', 'date', 'elderberry',
    'fig', 'grape', 'kiwi', 'lemon', 'mango',
];

// Build a simple background view
$bgLines = [];
for ($i = 0; $i < 20; $i++) {
    $bgLines[] = "  Background line " . ($i + 1) . " — more content here";
}
$bg = \implode("\n", $bgLines);

echo "=== Background view ===\n{$bg}\n\n";

// Show hermit
$h = Hermit::new($items)
    ->setPrompt('Filter: ')
    ->setMatchStyle("\x1b[33m")
    ->setWindowHeight(8)
    ->setItemFormatter(fn($item, $sel) => ($sel ? '▶ ' : '  ') . $item)
    ->show();

echo "=== Hermit overlay (filtering nothing — all shown) ===\n";
echo $h->view($bg) . "\n\n";

// Type to filter
$h2 = $h->type('ba');
echo "=== After typing 'ba' ===\n";
echo $h2->view($bg) . "\n\n";

// Navigate
$h3 = $h2->cursorDown();
echo "=== Cursor down one ===\n";
echo $h3->view($bg) . "\n";
