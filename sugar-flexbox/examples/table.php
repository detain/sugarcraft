<?php

declare(strict_types=1);

/**
 * SugarFlexbox Table demo — sortable, filterable data table.
 *
 * Run: php examples/table.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Flexbox\Table\{Column, Table};

$t = (new Table())
    ->withColumn(Column::make('Name',    15))
    ->withColumn(Column::make('Age',      5)->withAlign('right'))
    ->withColumn(Column::make('City',    15))
    ->withColumn(Column::make('Score',    6)->withAlign('right'))
    ->withRow(['Alice',   30, 'New York',     95.5])
    ->withRow(['Bob',     25, 'Los Angeles',  82.3])
    ->withRow(['Carol',   35, 'Chicago',      91.0])
    ->withRow(['Dave',    28, 'Houston',      77.8])
    ->withRow(['Eve',     22, 'Phoenix',      88.2])
    ->withHeaderStyle('1;37');  // bold white

echo "=== Table (sorted by Name, no filter) ===\n";
echo $t->render() . "\n\n";

// Sort by Age asc
$t2 = $t->withSort(1, true);
echo "=== Sorted by Age (ascending) ===\n";
echo $t2->render() . "\n\n";

// Sort by Score desc
$t3 = $t->withSort(3, false);
echo "=== Sorted by Score (descending) ===\n";
echo $t3->render() . "\n\n";

// Filter
$t4 = $t2->filter('e');
echo "=== Filtered by 'e' ===\n";
echo $t4->render() . "\n";
