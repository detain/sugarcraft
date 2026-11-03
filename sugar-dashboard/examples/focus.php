<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Layout\FocusManager;

/**
 * Keyboard focus rotation via FocusManager.
 *
 * This example previously referenced Layout\Grid\Focus — a class that has
 * never existed in this library (it came from a mis-templated generator).
 * FocusManager is the real home of panel rotation (dashboard-live uses it).
 */
$focus = new FocusManager('root');
foreach (['clock', 'system', 'weather'] as $id) {
    $focus = $focus->register($id);
}

echo "initial focus: ", var_export($focus->focusedId(), true), "\n";
$focus = $focus->focusNext();
echo "after Tab:     ", var_export($focus->focusedId(), true), "\n";
$focus = $focus->focusNext();
echo "after Tab:     ", var_export($focus->focusedId(), true), "\n";
$focus = $focus->focusPrevious();
echo "after Shift+Tab:", var_export($focus->focusedId(), true), "\n";
echo "isFocused(system): ", var_export($focus->isFocused('system'), true), "\n";
