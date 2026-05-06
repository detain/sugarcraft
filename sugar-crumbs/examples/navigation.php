<?php
/**
 * sugar-crumbs — navigation stack with pop-on-backspace demo.
 *
 * Run: php examples/navigation.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Crumbs\NavStack;

echo "=== NavStack — push / pop / back navigation ===\n\n";

$nav = new NavStack();
$nav = $nav->push('Home', '/');
echo "Home: " . $nav->view() . "\n";

$nav = $nav->push('Settings', '/settings');
echo "Settings: " . $nav->view() . "\n";

$nav = $nav->push('Display', '/settings/display');
echo "Display: " . $nav->view() . "\n";

$nav = $nav->push('Resolution', '/settings/display/resolution');
echo "Resolution: " . $nav->view() . "\n\n";

echo "--- Pop back one level (Settings) ---\n";
$nav = $nav->pop();
echo $nav->view() . "\n\n";

echo "--- Pop back to Home ---\n";
$nav = $nav->pop()->pop();
echo $nav->view() . "\n\n";

echo "--- Push more items ---\n";
$nav2 = (new NavStack())
    ->push('Home', '/')
    ->push('Settings', '/settings')
    ->push('Display', '/settings/display')
    ->push('Sound', '/settings/sound')
    ->push('About', '/about');

echo "Filter 'dis': " . $nav2->filter('dis')->view() . "\n";
echo "Total items: " . $nav2->depth() . ", Filtered: " . $nav2->filter('dis')->depth() . "\n\n";

echo "--- Shell integration (auto-detect directory changes) ---\n";
$shell = new \CandyCore\Crumbs\Shell();
$shell = $shell->pushDirectory('/home/user/projects/sugarcraft/src');
echo "Shell crumb: " . $shell->view() . "\n";
