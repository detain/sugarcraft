<?php

declare(strict_types=1);

/**
 * SugarCalendar date picker demo.
 *
 * Run: php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Calendar\DatePicker;

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

// Simulate keyboard navigation
echo "\n=== Simulate arrow key navigation ===\n";
$dp3 = DatePicker::new(new \DateTimeImmutable('2026-12-01'))
    ->MoveCursorRight()
    ->MoveCursorRight()
    ->SelectDate();

echo $dp3->View() . "\n";
echo "Selected: " . ($dp3->SelectedDate()?->format('Y-m-d') ?? 'none') . "\n";
