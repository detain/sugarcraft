<?php
/**
 * sugar-flexbox Table — sort, filter, and cursor demo.
 *
 * Run: php examples/sort-filter.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Flexbox\Table\{Table, Column};

// Build a table with user data
$table = (new Table())
    ->withColumn(Column::make('Name', 20))
    ->withColumn(Column::make('Role', 15))
    ->withColumn(Column::make('Active', 10))
    ->withRow(['Alice', 'Admin', 'Yes'])
    ->withRow(['Bob', 'Dev', 'Yes'])
    ->withRow(['Carol', 'Dev', 'No'])
    ->withRow(['Dave', 'Admin', 'Yes'])
    ->withRow(['Eve', 'Guest', 'No']);

echo "=== Original order ===\n";
echo $table->render();
echo "\n";

// Sort by Name ascending
$table = $table->withSort(0, true);
echo "=== Sorted by Name (asc) ===\n";
echo $table->render();
echo "\n";

// Sort by Name descending
$table = $table->withSort(0, false);
echo "=== Sorted by Name (desc) ===\n";
echo $table->render();
echo "\n";

// Sort by Active status (Yes/No — alphabetical)
$table = $table->withSort(2, true);
echo "=== Sorted by Active (asc) ===\n";
echo $table->render();
echo "\n";

// Arm a cursor highlight first: the default cursor style is the empty
// string, so without this the cursor row renders with no marker bytes.
$table = $table->withCursorStyle('1;36');

// Toggle sort on Name, then place the cursor: the sort carries the selection
// with its row, and withCursor() checks the index against the settled view —
// setting it last keeps this walkthrough linear. (previous sort was column 2;
// a different column starts ascending)
$table = $table->sortByNext(0)->withCursor(2);
echo "=== After sortByNext() (Name asc) then setCursor(2) ===\n";
echo $table->render();
echo "\n";

// rawCurrentRow()/rawCurrentCell() hand back the STORED strings — the
// sanitize sweep belongs to render(), not the accessors. This demo only
// echoes its own program-generated rows, which is exactly the trusted
// case the README trust-boundary section describes; with user-supplied
// cells, sweep the values (or render() instead) before printing them.
echo "cursor row: " . json_encode($table->rawCurrentRow()) . ", col0: {$table->rawCurrentCell(0)}\n";
echo "total rows: {$table->rowCount()}, cols: {$table->colCount()}\n";
