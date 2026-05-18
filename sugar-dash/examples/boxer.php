<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Boxer\Boxer;
use SugarCraft\Dash\Layout\Boxer\Node;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\FocusManager;
use SugarCraft\Dash\Layout\Frame;

/**
 * boxer.php — Three-panel Boxer layout with focus rotation demo.
 *
 * Demonstrates:
 * - Horizontal split into three named leaves (A, B, C)
 * - FocusManager tracking keyboard-focus state
 * - Visual focus indicator on panel "B"
 * - Immutable withers for panel updates
 *
 * Run: php examples/boxer.php
 */

// Build a FocusManager with three registered panels
$focus = (new FocusManager('root'))
    ->register('A')
    ->register('B')
    ->register('C')
    ->focus('B');  // Panel B starts focused

// Build panel content with focus indicator
function panelContent(string $id, bool $focused): string
{
    $marker = $focused ? '◉' : '○';
    $label = $focused ? "[{$id}]" : " {$id} ";
    $status = $focused ? 'FOCUSED' : 'idle';
    return "  {$marker} Panel {$label}  \n  Status: {$status}  \n  ──────────────────────\n  Content area for\n  panel {$id}              \n";
}

$contentA = panelContent('A', $focus->isFocused('A'));
$contentB = panelContent('B', $focus->isFocused('B'));
$contentC = panelContent('C', $focus->isFocused('C'));

// Build the Boxer tree: root horizontal split with three leaf panels
$boxer = Boxer::tree(
    Node::horizontal(
        Node::leaf('A'),
        Node::leaf('B'),
        Node::leaf('C'),
    ),
    [
        'A' => new Text($contentA),
        'B' => new Text($contentB),
        'C' => new Text($contentC),
    ]
);

$boxer = $boxer->setSize(80, 14);
echo $boxer->render();
echo "\n";
echo "Focus: [A] ○  [B] ◉  [C] ○   ← Tab to rotate focus\n";
