<?php

declare(strict_types=1);

/**
 * SugarSkate basic usage — set, get, delete, list.
 *
 * Run: php examples/basic.php
 *
 * Exercises:
 * - Store::set() / get() / delete()
 * - Store::list() iteration
 * - Store::entry() for metadata
 * - Fallback value on get()
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Skate\Store;

$tmp = sys_get_temp_dir() . '/skate-demo-' . uniqid();
$store = new Store($tmp);

// Clean up on exit
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmp}/*.db") ?: []));

// Basic set/get
$store->set('greeting', 'Hello, SugarSkate!');
echo 'get greeting: ' . $store->get('greeting') . "\n";

// Non-existent key with fallback
echo 'get missing:  ' . $store->get('missing', '(not found)') . "\n";

// Entry with metadata
$store->set('flavor', 'chocolate');
$entry = $store->entry('flavor');
echo "entry flavor: key={$entry->key}, value={$entry->value}, "
    . "binary=" . ($entry->binary ? 'yes' : 'no') . "\n";

// Multiple entries
$store->set('alpha', '1');
$store->set('beta',  '2');
$store->set('gamma', '3');

echo "\n--- All entries ---\n";
foreach ($store->list() as $e) {
    echo "  {$e->key} => {$e->value}\n";
}

// Keys only
echo "\n--- Keys only ---\n";
echo '  ' . implode(', ', [...$store->list(mode: 'keys')]) . "\n";

// Values only
echo "\n--- Values only ---\n";
echo '  ' . implode(', ', [...$store->list(mode: 'values')]) . "\n";

// Delete
$store->set('temporary', 'remove me');
echo "\nBefore delete: " . ($store->get('temporary', 'GONE') !== 'remove me' ? 'N/A' : 'exists');
$store->delete('temporary');
echo "\nAfter delete:  " . ($store->entry('temporary') === null ? 'deleted' : 'still there') . "\n";

echo "\nDone. Data in: {$tmp}\n";
