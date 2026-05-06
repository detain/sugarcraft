<?php
/**
 * sugar-table — complex table with frozen cols, styled cells, and pagination.
 *
 * Run: php examples/features.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Table\{Table, Column, Row, RowData, StyledCell};

echo "=== Wide table with frozen column and pagination ===\n\n";

// 40 rows of data
$rows = [];
for ($i = 1; $i <= 40; $i++) {
    $status = $i % 5 === 0 ? 'error' : ($i % 3 === 0 ? 'warning' : 'ok');
    $statusColor = match ($status) {
        'error' => '31', // red
        'warning' => '33', // yellow
        default => '32', // green
    };
    $statusText = match ($status) {
        'error' => 'FAIL',
        'warning' => 'WARN',
        default => 'OK',
    };
    $rows[] = Row::new([
        new StyledCell((string) $i, '36'), // id in cyan
        new StyledCell("Server-{$i}", ''),
        new StyledCell("10.0.{$i % 255}.1", '90'), // dim IP
        new StyledCell("{$i * 10}ms", ''),
        new StyledCell($statusText, $statusColor),
    ]);
}

$table = (new Table())
    ->withColumns([
        Column::make('#', 5)->withAlignment('right'),
        Column::make('Hostname', 15),
        Column::make('IP Address', 18),
        Column::make('Latency', 10)->withAlignment('right'),
        Column::make('Status', 8)->withAlignment('center'),
    ])
    ->withRows($rows)
    ->withPagination(10);

echo $table->view() . "\n\n";

echo "Navigate: use NextPage() / PreviousPage() in code.\n";
echo "Total pages: {$table->TotalPages()}\n";
echo "Current page: {$table->CurrentPage()}\n";

// Show page 3
$table = $table->withPage(3);
echo "\n=== Page 3 ===\n";
echo $table->view() . "\n";
