<?php

declare(strict_types=1);

/**
 * SugarSkate glob pattern demo.
 *
 * Run: php examples/glob.php
 *
 * Exercises:
 * - Store::list() with glob patterns (*, ?)
 * - Database::deleteMany() with glob
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Skate\Store;

$tmp = sys_get_temp_dir() . '/skate-glob-demo-' . uniqid();
$store = new Store($tmp);
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmp}/*.db") ?: []));

// Seed a bunch of entries
$entries = [
    'user-alice'   => 'alice@example.com',
    'user-bob'     => 'bob@example.com',
    'user-carol'   => 'carol@example.com',
    'admin-dave'   => 'dave@example.com',
    'config-host'  => 'prod.internal',
    'config-port'  => '8080',
    'secret-token' => 'ghp_xxxx',
];
foreach ($entries as $k => $v) {
    $store->set($k, $v);
}

echo "=== All entries ===\n";
foreach ($store->list() as $e) {
    printf("  %-20s %s\n", $e->key, $e->value);
}

echo "\n=== user-* entries (glob star) ===\n";
foreach ($store->list('user-*') as $e) {
    printf("  %s\n", $e->key);
}

echo "\n=== user-? (glob question mark — single char) ===\n";
foreach ($store->list('user-?') as $e) {
    printf("  %s\n", $e->key);
}

echo "\n=== config-* entries ===\n";
foreach ($store->list('config-*') as $e) {
    printf("  %s = %s\n", $e->key, $e->value);
}

echo "\n=== Delete all user-* entries ===\n";
$deleted = $store->delete('user-*');
echo "Deleted: {$deleted} entries\n";

echo "\n=== Remaining entries ===\n";
foreach ($store->list() as $e) {
    printf("  %s\n", $e->key);
}

echo "\nDone.\n";
