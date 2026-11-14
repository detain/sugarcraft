<?php

declare(strict_types=1);

/**
 * Quickstart — one change read two ways: the GNU `diff -u` text from
 * `Diff::unified()` and the paired `[left?, right?]` rows from
 * `Writer\SideBySideFormat::rows()` that a split viewer paints. The new
 * side's last line is unterminated on purpose: with `withNoNewlineAnnotation()`
 * a string side carries GNU's `\ No newline at end of file` annotation — the
 * annotation attaches to string sides only, a `list<string>` side always reads
 * as terminated and stays inert.
 *
 *   php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\Writer\SideBySideFormat;

$before = "alpha\nbravo\ncharlie\ndelta\n";
$after  = "alpha\nbravo was here\ncharlie\ndelta\necho";

$diff = Diff::compute($before, $after, DiffOptions::new()->withNoNewlineAnnotation());

echo "--- unified (diff -u shape) ---\n";
echo $diff->unified('notes.txt');

echo "\n--- side by side (split viewer rows) ---\n";
foreach (SideBySideFormat::rows($diff) as $row) {
    printf(
        "%4s %-20s | %4s %-20s%s\n",
        $row->left?->oldNumber ?? '',
        $row->left?->text ?? '',
        $row->right?->newNumber ?? '',
        $row->right?->text ?? '',
        $row->isPaired() ? '' : '  (leftover)',
    );
}
