<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\GridTable\{GridTable, Column, Row};

/**
 * gridtable-demo.php — Full-featured GridTable demo with sort, filter, pagination.
 *
 * Demonstrates:
 * - Multiple columns with sortable and filterable flags
 * - Many rows showing pagination
 * - Sort state (click column to sort)
 * - Filter text applied
 *
 * Run: php examples/gridtable-demo.php
 */
$cols = [
    new Column('id', 'ID', sortable: true),
    new Column('name', 'Name', sortable: true, filterable: true),
    new Column('email', 'Email', filterable: true),
    new Column('dept', 'Department', sortable: true),
    new Column('score', 'Score', sortable: true),
    new Column('status', 'Status', sortable: true),
];

$rows = [];
$names = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi', 'Ivan', 'Judy', 'Kevin', 'Laura'];
$depts = ['Engineering', 'Design', 'Product', 'Marketing', 'Sales'];
$statuses = ['active', 'pending', 'inactive', 'suspended'];
$emails = ['acme.io', 'example.com', 'test.org', 'demo.net'];
for ($i = 1; $i <= 25; $i++) {
    $rows[] = new Row([
        'id'     => (string) $i,
        'name'   => $names[($i - 1) % count($names)],
        'email'  => strtolower($names[($i - 1) % count($names)]) . '@' . $emails[($i - 1) % count($emails)],
        'dept'   => $depts[($i - 1) % count($depts)],
        'score'  => (string) (100 - $i * 2 + ($i % 3) * 5),
        'status' => $statuses[($i - 1) % count($statuses)],
    ]);
}

$table = GridTable::create($cols, $rows)
    ->filter('a')   // Pre-apply a filter to demonstrate filtering
    ->page(1);

$table->setSize(80, 20);
echo $table->render();
