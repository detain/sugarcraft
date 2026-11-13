<?php
/**
 * sugar-datepicker — min/max date-constraint demo: clamped navigation and
 * refused out-of-range selection.
 *
 * Run: php examples/constraints.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\DatePicker\DatePicker;

echo "=== Date constraints (min 2026-05-10, max 2026-07-20) ===\n\n";

$picker = DatePicker::new(new \DateTimeImmutable('2026-06-15'))
    ->withMinDate(new \DateTimeImmutable('2026-05-10'))
    ->withMaxDate(new \DateTimeImmutable('2026-07-20'));

echo "Initial view (June 2026, inside the window):\n";
echo $picker->view() . "\n\n";

// Navigation is clamped at the bound months — stepping past them is a no-op.
$may = $picker->goToPreviousMonth()->goToPreviousMonth();
echo "Two months back (May 2026 — the min month):\n";
echo $may->view() . "\n";
$still = $may->goToPreviousMonth();
echo "One more step back stays put: "
    . \sprintf('%04d-%02d', $still->viewYear(), $still->viewMonth()) . "\n\n";

// Home/End clamp to selectable days: with min 2026-05-10, Home in May lands on
// May 10 itself — never a dimmed out-of-bounds day (the pre-clamp trap).
$atFirstSelectable = $may->handleKey(DatePicker::KEY_HOME);
echo "Home clamps to the first selectable day: "
    . ($atFirstSelectable->dateAtCursor()?->format('Y-m-d') ?? 'none') . "\n";

// The arrows still rest on dimmed cells by design; selection there is refused
// and the picker comes back unchanged.
$onDimmed = $atFirstSelectable->handleKey(DatePicker::KEY_LEFT);  // May 9, before the bound
$refused = $onDimmed->handleKey(DatePicker::KEY_ENTER);
echo "Enter on the dimmed out-of-bounds cell: "
    . ($refused->selectedDate() === null ? 'refused' : 'SELECTED?!') . "\n";

// The acceptance leg must land exactly on the bound. MoveToDate positions the
// cursor on a chosen day — a short walk would not: the seed cursor was already
// re-clamped to May 11 when May was opened, so 14 rights reached May 25 and
// announced a mid-month acceptance as the min-date bound.
$atBound = $may->moveToDate(new \DateTimeImmutable('2026-05-10'));
echo "Date under cursor: " . ($atBound->dateAtCursor()?->format('Y-m-d') ?? 'none') . "\n";
$accepted = $atBound->handleKey(DatePicker::KEY_ENTER);
echo "Enter on the bound itself: "
    . ($accepted->selectedDate()?->format('Y-m-d') ?? 'refused') . "\n\n";

// Lifting a bound restores free navigation in that direction.
$unlocked = $accepted->withoutMinDate()->goToPreviousMonth();
echo "After withoutMinDate(), one month back lands in April 2026: "
    . \sprintf('%04d-%02d', $unlocked->viewYear(), $unlocked->viewMonth()) . "\n";
