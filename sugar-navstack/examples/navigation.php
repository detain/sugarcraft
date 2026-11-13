<?php
/**
 * sugar-navstack — back-navigation demo (pop() standing in for a host's
 * back key; the scripted run shows the stack the moment before and after
 * each back step).
 *
 * Run: php examples/navigation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\NavStack\NavStack;

echo "=== NavStack — push / pop / back navigation ===\n\n";

$nav = NavStack::new();
$nav->push('Home', '/');
echo "Home: " . $nav->view() . "\n";

$nav->push('Settings', '/settings');
echo "Settings: " . $nav->view() . "\n";

$nav->push('Display', '/settings/display');
echo "Display: " . $nav->view() . "\n";

$nav->push('Resolution', '/settings/display/resolution');
echo "Resolution: " . $nav->view() . "\n\n";

echo "--- Pop back one level (Resolution dropped; Display is current) ---\n";
$nav->pop(); // pop() mutates the stack in place and returns the removed item
echo $nav->view() . "\n\n";

echo "--- Pop back to Home ---\n";
$nav->pop(); // pop twice to get back to Home
$nav->pop();
echo $nav->view() . "\n\n";

echo "--- Push more items ---\n";
$nav2 = (new NavStack())
    ->push('Home', '/')
    ->push('Settings', '/settings')
    ->push('Display', '/settings/display')
    ->push('Sound', '/settings/sound')
    ->push('About', '/about');

echo "Filter 'dis': " . $nav2->filtered('dis')->view() . "\n";
echo "Total items: " . $nav2->depth() . ", Filtered: " . $nav2->filtered('dis')->depth() . "\n\n";

echo "--- Shell integration (explicit pushDirectory — nothing auto-detects; watching for directory changes is the host app's job) ---\n";
$shell = \SugarCraft\NavStack\Shell::new();
$shell = $shell->pushDirectory('/home/user/projects/sugarcraft/src');
echo "Shell crumb: " . $shell->renderBreadcrumb() . "\n";

echo "\n--- Focus: the renderer is told which crumb is selected ---\n";
$focusBc = (new \SugarCraft\NavStack\Breadcrumb())
    ->setItemRenderer(static fn ($item, int $i, bool $focused = false): ?string => $focused ? '[' . $item->title . ']' : $item->title);
$focusBc->setFocusedIndex(2);
echo "focus 2:        " . $focusBc->render($nav2) . "\n";
$focusBc->setFocusedIndex(99); // off the stack: treated as no selection
echo "focus off-range: " . $focusBc->render($nav2) . ' -> focusedIndex(): '
    . var_export($focusBc->focusedIndex(), true) . "\n";
