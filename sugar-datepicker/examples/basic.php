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
echo $dp->View() . "\n\n";

// Navigate months
echo "=== Next Month (June 2026) ===\n";
$dp = $dp->GoToNextMonth();
echo $dp->View() . "\n\n";

// Selection
echo "=== Select a date ===\n";
$dp2 = DatePicker::new(new \DateTimeImmutable('2026-07-04'))
    ->MoveCursorDown()
    ->MoveCursorRight()
    ->SelectDate();

echo $dp2->View() . "\n";
if ($dp2->SelectedDate() !== null) {
    echo "Selected: " . $dp2->SelectedDate()->format('Y-m-d') . "\n";
}

// The direct cursor API — keyboard dispatch (handleKey / the Model wrapper
// routing real KeyMsgs) is documented in README and exercised for bounds in
// examples/constraints.php.
echo "\n=== Move the cursor and select (direct API) ===\n";
$dp3 = DatePicker::new(new \DateTimeImmutable('2026-12-01'))
    ->MoveCursorRight()
    ->MoveCursorRight()
    ->SelectDate();

echo $dp3->View() . "\n";
echo "Selected: " . ($dp3->SelectedDate()?->format('Y-m-d') ?? 'none') . "\n";
