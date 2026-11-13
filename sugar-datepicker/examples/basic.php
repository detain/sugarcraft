<?php

declare(strict_types=1);

/**
 * SugarDatepicker date picker demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\DatePicker\DatePicker;

echo "=== Date Picker: May 2026 ===\n";
$dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
echo $dp->view() . "\n\n";

// Navigate months
echo "=== Next Month (June 2026) ===\n";
$dp = $dp->goToNextMonth();
echo $dp->view() . "\n\n";

// Selection
echo "=== Select a date ===\n";
$dp2 = DatePicker::new(new \DateTimeImmutable('2026-07-04'))
    ->moveCursorDown()
    ->moveCursorRight()
    ->selectDate();

echo $dp2->view() . "\n";
if ($dp2->selectedDate() !== null) {
    echo "Selected: " . $dp2->selectedDate()->format('Y-m-d') . "\n";
}

// The direct cursor API — keyboard dispatch (handleKey / the Model wrapper
// routing real KeyMsgs) is documented in README and exercised for bounds in
// examples/constraints.php.
echo "\n=== Move the cursor and select (direct API) ===\n";
$dp3 = DatePicker::new(new \DateTimeImmutable('2026-12-01'))
    ->moveCursorRight()
    ->moveCursorRight()
    ->selectDate();

echo $dp3->view() . "\n";
echo "Selected: " . ($dp3->selectedDate()?->format('Y-m-d') ?? 'none') . "\n";
