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

// Selection outside the window is refused; the picker comes back unchanged.
$beforeBound = $may
    ->handleKey(DatePicker::KEY_HOME)          // HOME lands on the month-first day-cell (May 1); min/max are not consulted
    ->handleKey(DatePicker::KEY_LEFT);         // clamps into the empty lead-in
$refused = $beforeBound->handleKey(DatePicker::KEY_ENTER);
echo "Enter on the empty lead-in cell (fallback = May 1, before the bound): "
    . ($refused->isSelecting() ? 'SELECTED?!' : 'refused') . "\n";

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
