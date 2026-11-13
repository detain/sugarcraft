<?php

declare(strict_types=1);

/**
 * sugar-quickpick — QuickPick fuzzy finder with cursor + filtering demo.
 *
 * Run: php examples/interaction.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\QuickPick\FilteredItem;
use SugarCraft\QuickPick\QuickPick;

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

// Background to composite the overlay onto (10 lines)
$bg = str_repeat("example background line\n", 10);

// Build a QuickPick instance. Note: each step below rebinds $picker to a
// fluent clone, and the shared width memo follows every clone — a SIGWINCH
// invalidates the whole family, so re-attaching after each step is not
// required (register the withOnResize() callback before attachSigwinch()).
$picker = QuickPick::new($items)
    ->withPrompt('🔍 Search: ')
    ->withWindowHeight(10)
    ->show();

echo "=== QuickPick fuzzy finder — initial state ===\n";
echo $picker->view($bg) . "\n\n";

// Type some characters to filter
$picker = $picker->type('p');
echo "After type('p') — filter: '{$picker->filterText()}'\n";
echo $picker->view($bg) . "\n\n";

$picker = $picker->type('h');
echo "After type('ph') — filter: '{$picker->filterText()}'\n";
echo $picker->view($bg) . "\n\n";

// Cursor down
$picker = $picker->cursorDown();
echo "After cursorDown() — cursor index: {$picker->cursor()}\n";
echo $picker->view($bg) . "\n\n";

// Cursor to bottom
$picker = $picker->cursorBottom();
echo "After cursorBottom() — cursor index: {$picker->cursor()}\n";
echo $picker->view($bg) . "\n\n";

// Cursor up
$picker = $picker->cursorUp(2);
echo "After cursorUp(2) — cursor index: {$picker->cursor()}\n";
echo $picker->view($bg) . "\n\n";

// Backspace
$picker = $picker->backspace();
echo "After backspace() — filter: '{$picker->filterText()}'\n";
echo $picker->view($bg) . "\n\n";

// Clear filter
$picker = $picker->clear();
echo "After clear() — filter: '{$picker->filterText()}'\n";
echo $picker->view($bg) . "\n\n";

// Final state: cursor at bottom, filter cleared
echo "=== Final state ===\n";
echo $picker->view($bg) . "\n";

// The same loop expressed through the composition contract: a host that
// owns NO state, threading every transition through the update() tuple
// ([host, picker]) — the shape QuickPickHost exists to make honest.
final class PickerHost implements \SugarCraft\QuickPick\QuickPickHost
{
    /** @return array{0: \SugarCraft\QuickPick\QuickPickHost, 1: QuickPick} */
    public function update(QuickPick $model, string $msg): array
    {
        return [$this, match ($msg) {
            'down' => $model->cursorDown(),
            'up'   => $model->cursorUp(),
            default => $model->type($msg),
        }];
    }

    public function view(QuickPick $model): string
    {
        // A genuinely stateless host takes its background from script scope
        // ($bg is the top-level variable above); real hosts that hold render
        // context are immutable-by-construction with a readonly property —
        // the tuple only promises the PICKER never needs mutable host state.
        return $model->view($GLOBALS['bg']);
    }
}

echo "\n=== QuickPickHost contract — stateless loop ===\n";
$host  = new PickerHost();
$state = QuickPick::new($items)->withPrompt('> ')->withWindowHeight(8)->show();
foreach (['p', 'h', 'down'] as $event) {
    [$host, $state] = $host->update($state, $event);
}
echo $host->view($state) . "\n";
