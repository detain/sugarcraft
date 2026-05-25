<?php

declare(strict_types=1);

/**
 * COMPOSITION: candy-forms Spinner  +  sugar-table Table
 *
 * Demonstrates embedding a live candy-forms `Spinner` frame inside a
 * sugar-table cell. A table of "jobs" shows a per-row status column; rows
 * that are still running render the spinner's current frame (advanced via
 * its own `update()`/`TickMsg` contract) as a `StyledCell`, while finished
 * rows render a static check mark.
 *
 * Because `StyledCell::__toString()` emits whatever string it is given, any
 * component whose `view()` returns a string — a Spinner, a Sparkline, a
 * Sparkline, etc. — drops straight into a table cell.
 *
 * Run:
 *   composer install && php spinner-in-table.php
 */

require __DIR__ . '/vendor/autoload.php';

use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Forms\Spinner\TickMsg;
use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table};

// A spinner is a normal candy-forms model: build it, then advance its frame
// by feeding it the TickMsg its own update() expects. We step it a few times
// so the rendered frame below is deterministic rather than always frame 0.
$spinner = Spinner::new(SpinnerStyle::dot());
for ($i = 0; $i < 3; $i++) {
    [$spinner, ] = $spinner->update(new TickMsg($spinner->id()));
}

$frame = $spinner->view();                       // e.g. "⠹"
$running = StyledCell::new($frame . ' running', '33');   // yellow
$done    = StyledCell::new('✓ done', '32');              // green

$table = Table::withColumns([
    Column::new('id',     'Job',     6),
    Column::new('name',   'Task',    22),
    Column::new('status', 'Status',  16)->withAlignLeft(),
])->withRows([
    Row::new(RowData::from(['id' => '1', 'name' => 'Provision database', 'status' => $done])),
    Row::new(RowData::from(['id' => '2', 'name' => 'Migrate schema',     'status' => $running])),
    Row::new(RowData::from(['id' => '3', 'name' => 'Seed fixtures',      'status' => $running])),
    Row::new(RowData::from(['id' => '4', 'name' => 'Warm cache',         'status' => $done])),
])->withHeaderStyle('1;37');

echo "Job runner — spinner frame embedded in the Status column\n";
echo "(spinner advanced to frame {$spinner->frame()}: '{$frame}')\n\n";
echo $table->View() . "\n";
