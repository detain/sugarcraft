<?php

declare(strict_types=1);

/**
 * MultiSelectPrompt example — pick multiple items with min/max enforcement.
 *
 * Run: php examples/multi-select.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Readline\{Key, MultiSelectPrompt};

echo "\n=== Multi-Select Prompt Demo ===\n\n";

// Basic multi-select (no constraints).
$p = MultiSelectPrompt::new('Top 3 foods?', [
    'Pizza', 'Burger', 'Sushi', 'Salad', 'Pasta', 'Tacos',
    'Ramen', 'Steak', 'Curry', 'Pho', 'Bibimbap', 'Falafel',
])->withPageSize(6);

echo $p->view() . "\n\n";
echo "(Use arrow keys to move, Space to toggle, Enter to confirm)\n";
echo "(Simulating: space (Pizza), down, space (Burger), down, space (Sushi), enter)\n\n";

$p = $p->handleKey(Key::Space)
       ->handleKey(Key::Down)->handleKey(Key::Space)
       ->handleKey(Key::Down)->handleKey(Key::Space)
       ->handleKey(Key::Enter);

echo "Result:\n";
print_r($p->selectedValues());
echo "\n";

// With min/max constraints.
echo "\n=== With min/max constraints ===\n\n";

$p2 = MultiSelectPrompt::new('Pick exactly 2 colors:', ['Red', 'Green', 'Blue', 'Yellow', 'Purple'])
    ->withMinSelections(2)
    ->withMaxSelections(2)
    ->withPageSize(5);

echo $p2->view() . "\n\n";
echo "Constraints: min=2, max=2. Selecting Red and Green:\n\n";

$p2 = $p2->handleKey(Key::Space)
         ->handleKey(Key::Down)->handleKey(Key::Space)
         ->handleKey(Key::Enter);

echo "Result:\n";
print_r($p2->selectedValues());
echo "\n";
