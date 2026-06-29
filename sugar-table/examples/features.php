<?php
/**
 * sugar-table — wide table with frozen columns, styled cells, pagination.
 *
 * Run: php examples/features.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Table\{Table, Column, Row, RowData, StyledCell};

echo "=== Wide table with frozen column + pagination ===\n\n";

// 40 rows of host telemetry, every 3rd warning, every 5th failing.
$rows = [];
for ($i = 1; $i <= 40; $i++) {
    $status = $i % 5 === 0 ? 'error' : ($i % 3 === 0 ? 'warning' : 'ok');
    [$statusText, $statusColor] = match ($status) {
        'error'   => ['FAIL', '31'],
        'warning' => ['WARN', '33'],
        default   => ['OK',   '32'],
    };
    $rows[] = Row::new(RowData::from([
        'id'      => new StyledCell((string) $i, '36'),
        'host'    => new StyledCell('Server-' . $i, ''),
        'ip'      => new StyledCell('10.0.' . ($i % 255) . '.1', '90'),
        'latency' => new StyledCell(($i * 10) . 'ms', ''),
        'status'  => new StyledCell($statusText, $statusColor),
    ]));
}

$table = Table::fromColumns([
    Column::new('id',      '#',          5),
    Column::new('host',    'Hostname',  15)->withAlignLeft(),
    Column::new('ip',      'IP',        18)->withAlignLeft(),
    Column::new('latency', 'Latency',   10),
    Column::new('status',  'Status',     8),
])
    ->withRows($rows)
    ->withPageSize(10)
    ->withZebra()
    ->withHeaderStyle('1;37');

echo $table->View() . "\n\n";

echo "Navigate: use NextPage() / PreviousPage() in code.\n";
echo "Total pages: " . $table->TotalPages() . "\n";
echo "Current page: " . ($table->CurrentPage() + 1) . " (zero-indexed underneath)\n";

// Jump straight to page 3 (zero-indexed → 2).
$table = $table->withPage(2);
echo "\n=== Page 3 (frozen # column stays on the left) ===\n";
echo $table->View() . "\n";
