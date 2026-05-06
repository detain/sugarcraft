<?php
/**
 * sugar-stickers Table — sort, filter, and cursor demo.
 *
 * Run: php examples/sort-filter.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Stickers\Table\{Table, Column};

// Build a table with user data
$table = (new Table())
    ->addColumn(Column::make('Name', 20))
    ->addColumn(Column::make('Role', 15))
    ->addColumn(Column::make('Active', 10))
    ->addRow(['Alice', 'Admin', 'Yes'])
    ->addRow(['Bob', 'Dev', 'Yes'])
    ->addRow(['Carol', 'Dev', 'No'])
    ->addRow(['Dave', 'Admin', 'Yes'])
    ->addRow(['Eve', 'Guest', 'No']);

echo "=== Original order ===\n";
echo $table->render();
echo "\n";

// Sort by Name ascending
$table = $table->sortBy(0, false);
echo "=== Sorted by Name (asc) ===\n";
echo $table->render();
echo "\n";

// Sort by Name descending
$table = $table->sortBy(0, true);
echo "=== Sorted by Name (desc) ===\n";
echo $table->render();
echo "\n";

// Sort by Active status (Yes/No — alphabetical)
$table = $table->sortBy(2, false);
echo "=== Sorted by Active (asc) ===\n";
echo $table->render();
echo "\n";

// Set cursor to row 2 (0-indexed), toggle sort
$table = $table->setCursor(2)->sortByNext();
echo "=== After setCursor(2) then sortByNext() (Name desc) ===\n";
echo $table->render();
echo "\n";

echo "cursor row: {$table->currentRow()}, col: {$table->currentCell()}\n";
echo "total rows: {$table->rowCount()}, cols: {$table->colCount()}\n";
