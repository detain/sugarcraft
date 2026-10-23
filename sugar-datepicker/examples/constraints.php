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
echo $picker->View() . "\n\n";

// Navigation is clamped at the bound months — stepping past them is a no-op.
$march = $picker->GoToPreviousMonth()->GoToPreviousMonth();
echo "Two months back (May 2026 — the min month):\n";
echo $march->View() . "\n";
$still = $march->GoToPreviousMonth();
echo "One more step back stays put: "
    . $still->ViewYear() . '-' . $still->ViewMonth() . "\n\n";

// Selection outside the window is refused; the picker comes back unchanged.
$beforeBound = $march
    ->handleKey(DatePicker::KEY_HOME)          // cursor onto the empty lead-in
    ->handleKey(DatePicker::KEY_LEFT);         // stays at 0
$refused = $beforeBound->handleKey(DatePicker::KEY_ENTER);
echo "Enter on the empty lead-in cell (fallback = May 1, before the bound): "
    . ($refused->IsSelecting() ? 'SELECTED?!' : 'refused') . "\n";

// May 10 sits at grid index 14 (May 2026 starts Friday, firstDow=5).
$atBound = $march;
for ($i = 0; $i < 14; $i++) {
    $atBound = $atBound->MoveCursorRight();
}
echo "Date under cursor: " . ($atBound->dateAtCursor()?->format('Y-m-d') ?? 'none') . "\n";
$accepted = $atBound->handleKey(DatePicker::KEY_ENTER);
echo "Enter on the bound itself: "
    . ($accepted->SelectedDate()?->format('Y-m-d') ?? 'refused') . "\n\n";

// Lifting a bound restores free navigation in that direction.
$unlocked = $accepted->withoutMinDate()->GoToPreviousMonth();
echo "After withoutMinDate(), one month back lands in April 2026: "
    . $unlocked->ViewYear() . '-' . $unlocked->ViewMonth() . "\n";
