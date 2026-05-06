<?php
/**
 * sugar-calendar — navigation, cursor movement, and date selection demo.
 *
 * Run: php examples/constraints.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Calendar\DatePicker;

echo "=== Calendar navigation and selection demo ===\n\n";

// Start at June 2026
$picker = DatePicker::new(new \DateTimeImmutable('2026-06-15'));
echo "Initial view (June 2026):\n";
echo $picker->view() . "\n\n";

// Navigate months
$prev = $picker->GoToPreviousMonth();
echo "Previous month (May 2026):\n";
echo $prev->view() . "\n\n";

$next = $picker->GoToNextMonth();
echo "Next month (July 2026):\n";
echo $next->view() . "\n\n";

// Navigate years
$jan = $picker->GoToPreviousYear();
echo "Previous year (June 2025):\n";
echo $jan->view() . "\n\n";

// Go to today
$today = DatePicker::new()->GoToToday();
echo "Today:\n";
echo $today->view() . "\n\n";

// Cursor navigation on June 2026
$june = DatePicker::new(new \DateTimeImmutable('2026-06-01'));
echo "June 2026 with cursor at start:\n";
echo $june->view() . "\n\n";

// Move cursor right 4 times (should land on day 4)
$cursor = $june;
for ($i = 0; $i < 4; $i++) {
    $cursor = $cursor->MoveCursorRight();
}
echo "After MoveCursorRight() x4:\n";
echo $cursor->view() . "\n\n";

// Move cursor down a row
$cursor = $cursor->MoveCursorDown();
echo "After MoveCursorDown() (next row):\n";
echo $cursor->view() . "\n\n";

// Select the date
$selected = $cursor->SelectDate();
echo "After SelectDate():\n";
echo $selected->view() . "\n";
echo "Selected: " . ($selected->SelectedDate()?->format('Y-m-d') ?? 'none') . "\n\n";

// Clear and re-select
$cleared = $selected->ClearDate();
echo "After ClearDate():\n";
echo $cleared->view() . "\n";
echo "Selected after clear: " . ($cleared->SelectedDate()?->format('Y-m-d') ?? 'none') . "\n";
