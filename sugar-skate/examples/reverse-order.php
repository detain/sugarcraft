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

use CandyCore\Skate\Store;

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
echo "Total entries: " . $store->entry('apple')->key; // just to show entry access
$count = 0;
foreach ($store->list() as $_) { $count++; }
echo "Count (via iteration): {$count}\n";

// Count via Database directly
use CandyCore\Skate\Database;
$dbFiles = glob("{$tmp}/*.db") ?: [];
$db = new Database($dbFiles[0], 'demo');
echo "Count (via Database::count): " . $db->count() . "\n";

echo "\nDone.\n";
