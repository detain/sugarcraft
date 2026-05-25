<?php

declare(strict_types=1);

/**
 * COMPOSITION: sugar-charts Sparkline  +  sugar-table Table  +  candy-forms Spinner
 *
 * Demonstrates a "metrics dashboard" frame that composes three libraries:
 *   - each sugar-charts `Sparkline::view()` is dropped into a sugar-table
 *     cell (one inline trend chart per host metric),
 *   - a candy-forms `Spinner` frame is rendered beside the table as a
 *     "live / refreshing" status line.
 *
 * The key composability point: a chart is just a component whose `view()`
 * returns a string, so it embeds in a table cell exactly like plain text.
 *
 * Run:
 *   composer install && php sparkline-in-table.php
 */

require __DIR__ . '/vendor/autoload.php';

use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Forms\Spinner\TickMsg;
use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table};

/** A few synthetic time series, one per host. */
function series(float $phase): array
{
    $out = [];
    for ($i = 0; $i < 20; $i++) {
        $out[] = sin($i * 0.4 + $phase) * 40 + 50;
    }
    return $out;
}

$hosts = [
    ['web-01', series(0.0)],
    ['web-02', series(1.2)],
    ['db-01',  series(2.4)],
    ['cache-01', series(3.6)],
];

$rows = [];
foreach ($hosts as $i => [$name, $data]) {
    // Sparkline::view() -> a one-line braille/block trend; wrap it in a
    // StyledCell so the table renders the chart with a colour.
    $spark = Sparkline::new($data, 20)->withMin(0.0)->withMax(100.0)->view();
    $last  = (int) round(end($data));
    $rows[] = Row::new(RowData::from([
        'host'  => $name,
        'cpu'   => StyledCell::new($spark, '36'),       // cyan trend
        'now'   => StyledCell::new($last . '%', $last > 80 ? '31' : '32'),
    ]));
}

$table = Table::withColumns([
    Column::new('host', 'Host',       12),
    Column::new('cpu',  'CPU (20m)',  24)->withAlignLeft(),
    Column::new('now',  'Current',     9),
])->withRows($rows)->withHeaderStyle('1;37');

// candy-forms spinner used as the panel's "refreshing" indicator.
$spinner = Spinner::new(SpinnerStyle::meter());
for ($i = 0; $i < 2; $i++) {
    [$spinner, ] = $spinner->update(new TickMsg($spinner->id()));
}

echo "\x1b[1mCluster metrics\x1b[0m  \x1b[33m{$spinner->view()}\x1b[0m refreshing…\n\n";
echo $table->View() . "\n";
