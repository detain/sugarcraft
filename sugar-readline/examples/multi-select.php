<?php
/**
 * MultiSelectPrompt example — pick multiple items with min/max enforcement.
 *
 * Run: php examples/multi-select.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Readline\MultiSelectPrompt;

echo "\n=== Multi-Select Prompt Demo ===\n\n";

// Basic multi-select (no constraints)
echo "Basic multi-select — pick your Top 3 foods:\n\n";

$p = MultiSelectPrompt::new('Top 3 foods?', [
    'Pizza',
    'Burger',
    'Sushi',
    'Salad',
    'Pasta',
    'Tacos',
    'Ramen',
    'Steak',
    'Curry',
    'Pho',
    'Bibimbap',
    'Falafel',
])->WithPerPage(6);

echo $p->View() . "\n\n";
echo "(Use arrow keys to move, Space to toggle, Enter to confirm)\n";
echo "(Simulating: down, space, down, space, down, space, enter)\n\n";

// Simulate user interaction
$p = $p->HandleKey('down')  // cursor to Pizza
       ->HandleKey('space') // select Pizza
       ->HandleKey('down')  // cursor to Burger
       ->HandleKey('space') // select Burger
       ->HandleKey('down')  // cursor to Sushi
       ->HandleKey('space') // select Sushi
       ->HandleKey('enter'); // confirm

echo "Result: ";
var_dump($p->SelectedValues());

// With min/max constraints
echo "\n=== With min/max constraints ===\n\n";

$p2 = MultiSelectPrompt::new('Pick exactly 2 colors:', [
    'Red',
    'Green',
    'Blue',
    'Yellow',
    'Purple',
])->WithMinSelections(2)
  ->WithMaxSelections(2)
  ->WithPerPage(5);

echo $p2->View() . "\n\n";
echo "Constraints: min=2, max=2 selections.\n";
echo "Selecting Red and Green:\n\n";

$p2 = $p2->HandleKey('space') // select Red
         ->HandleKey('down')
         ->HandleKey('space') // select Green
         ->HandleKey('enter');

echo "Result: ";
var_dump($p2->SelectedValues());
echo "\n";
