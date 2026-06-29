<?php

declare(strict_types=1);

/**
 * SugarTable — interactive table component demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table};

echo "=== Basic Table ===\n";
$t = Table::fromColumns([
    Column::new('id',   'ID',     5),
    Column::new('name', 'Name',  20),
    Column::new('city', 'City',  15),
    Column::new('score','Score',  8)->withAlignLeft(),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Alice',   'city' => 'New York',   'score' => '95'])),
    Row::new(RowData::from(['id' => '2', 'name' => 'Bob',     'city' => 'Los Angeles', 'score' => '82'])),
    Row::new(RowData::from(['id' => '3', 'name' => 'Carol',   'city' => 'Chicago',    'score' => '91'])),
    Row::new(RowData::from(['id' => '4', 'name' => 'Dave',    'city' => 'Houston',    'score' => '77'])),
    Row::new(RowData::from(['id' => '5', 'name' => 'Eve',     'city' => 'Phoenix',    'score' => '88'])),
])->withZebra()
  ->withHeaderStyle('1;37');

echo $t->View() . "\n\n";

// Sort by score descending
echo "=== Sorted by Score (desc) ===\n";
$t2 = $t->SortBy('score', ascending: false);
echo $t2->View() . "\n\n";

// Filter
echo "=== Filter: name contains 'a' ===\n";
$t3 = $t->Filter('name', 'a');
echo $t3->View() . "\n\n";

// Select row
echo "=== With cursor (select next twice) ===\n";
$t4 = $t->SelectNext()->SelectNext();
echo $t4->View() . "\n\n";

// Pagination
echo "=== Paginated (3 per page, page 2) ===\n";
$t5 = $t->withPageSize(3)->withPage(1);
echo $t5->View() . "\n";

// Missing data
echo "\n=== Missing Data Indicator ===\n";
$t6 = Table::fromColumns([
    Column::new('id',   'ID',   5),
    Column::new('name', 'Name', 15),
    Column::new('note', 'Note', 20),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),       // no note
    Row::new(RowData::from(['id' => '2', 'name' => 'Bob', 'note' => 'VIP'])),
])->withMissingIndicator('<N/A>');

echo $t6->View() . "\n";
