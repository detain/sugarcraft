<?php

declare(strict_types=1);

/**
 * SugarSkate ordered listing demo — forward and reverse order.
 *
 * Run: php examples/reverse-order.php
 *
 * Exercises:
 * - Store::list() / Database::list() reverse ordering
 * - count() API
 * - Database::deleteMany()
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Skate\Store;

$tmp = sys_get_temp_dir() . '/skate-order-demo-' . uniqid();
$store = new Store($tmp);
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmp}/*.db") ?: []));

// Add entries with keys that will sort non-trivially
$keys = ['zebra', 'apple', 'mango', 'banana', 'cherry'];
foreach ($keys as $k) {
    $store->set($k, "value-{$k}");
}

echo "=== Forward order (default) ===\n";
foreach ($store->list() as $e) {
    echo "  {$e->key}\n";
}

echo "\n=== Reverse order ===\n";
foreach ($store->list(null, null, reverse: true) as $e) {
    echo "  {$e->key}\n";
}

echo "\n=== Count ===\n";
$total = 0;
foreach ($store->list() as $_) { $total++; }
echo "Total entries: {$total}\n";
$apple = $store->entry('apple');
echo "Entry access example — apple => {$apple->value}\n";

// Count via Database directly
use SugarCraft\Skate\Database;
$dbFiles = glob("{$tmp}/*.db") ?: [];
$db = new Database($dbFiles[0], 'demo');
echo "Count (via Database::count): " . $db->count() . "\n";

echo "\nDone.\n";
