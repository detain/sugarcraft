<?php
/**
 * candy-hermit — Hermit fuzzy finder with cursor + filtering demo.
 *
 * Run: php examples/interaction.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Hermit\Hermit;

$items = [
    'composer',
    'git',
    'docker',
    'docker-compose',
    'npm',
    'node',
    'php',
    'phpunit',
    'symfony',
    'laravel',
    'vendor',
    'var/',
    'storage/',
    'tests/',
    'public/',
    '.env',
    '.gitignore',
    'README.md',
];

// Build a Hermit instance
$hermit = Hermit::new($items)
    ->setPrompt('🔍 Search: ')
    ->setMatchStyle('33'); // yellow for matches

echo "=== Hermit fuzzy finder — initial state ===\n";
echo $hermit->view() . "\n\n";

// Type some characters to filter
$hermit = $hermit->type('p');
echo "After type('p') — filter: '{$hermit->filterText()}'\n";
echo $hermit->view() . "\n\n";

$hermit = $hermit->type('h');
echo "After type('ph') — filter: '{$hermit->filterText()}'\n";
echo $hermit->view() . "\n\n";

// Cursor down
$hermit = $hermit->cursorDown();
echo "After cursorDown() — cursor index: {$hermit->cursor()}\n";
echo $hermit->view() . "\n\n";

// Cursor to bottom
$hermit = $hermit->cursorBottom();
echo "After cursorBottom() — cursor index: {$hermit->cursor()}\n";
echo $hermit->view() . "\n\n";

// Cursor up
$hermit = $hermit->cursorUp(2);
echo "After cursorUp(2) — cursor index: {$hermit->cursor()}\n";
echo $hermit->view() . "\n\n";

// Backspace
$hermit = $hermit->backspace();
echo "After backspace() — filter: '{$hermit->filterText()}'\n";
echo $hermit->view() . "\n\n";

// Clear filter
$hermit = $hermit->clear();
echo "After clear() — filter: '{$hermit->filterText()}'\n";
echo $hermit->view() . "\n\n";

// Custom formatter example (show matched characters in bold)
$hermit = Hermit::new($items)
    ->setItemFormatter(fn(string $item, string $filter, int $cursor): string => "  {$item}")
    ->setPrompt('🔍 ');

echo "=== With custom item formatter ===\n";
echo $hermit->view() . "\n";
