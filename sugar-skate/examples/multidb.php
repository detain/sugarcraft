<?php

declare(strict_types=1);

/**
 * SugarSkate multi-database demo.
 *
 * Run: php examples/multidb.php
 *
 * Exercises:
 * - Database suffix syntax: key@dbname
 * - Store::list() from a specific database
 * - Store::listDatabases()
 * - Store::deleteDatabase()
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Skate\Store;

$tmp = sys_get_temp_dir() . '/skate-multidb-demo-' . uniqid();
$store = new Store($tmp);
register_shutdown_function(fn() => @array_map('unlink', glob("{$tmp}/*.db") ?: []));

// Use multiple databases via @suffix
$store->set('gh_token@passwords', 'ghp_xxxxxxxxxxxx');
$store->set('smtp_pass@passwords', 'smtp_secret');
$store->set('api_key@passwords', 'sk-xxxxxxxxxxxxxxxx');

$store->set('project-a@bookmarks', 'https://github.com/charmbracelet/bubbletea');
$store->set('project-b@bookmarks', 'https://github.com/charmbracelet/lipgloss');

$store->set('note-1@notes', 'Remember to buy flour');
$store->set('note-2@notes', 'Bake at 375°F for 20 min');

echo "=== All databases ===\n";
foreach ($store->listDatabases() as $db) {
    echo "  {$db}\n";
}

echo "\n=== passwords database ===\n";
foreach ($store->list(null, 'passwords') as $e) {
    // Mask values for demo
    $val = substr($e->value, 0, 4) . '...';
    echo "  {$e->key} = {$val}\n";
}

echo "\n=== bookmarks database ===\n";
foreach ($store->list(null, 'bookmarks') as $e) {
    echo "  {$e->key}: {$e->value}\n";
}

echo "\n=== notes database ===\n";
foreach ($store->list(null, 'notes') as $e) {
    echo "  {$e->key}: {$e->value}\n";
}

echo "\n=== Direct key@db access ===\n";
echo 'gh_token@passwords: ' . $store->get('gh_token@passwords') . "\n";

echo "\nDone.\n";
