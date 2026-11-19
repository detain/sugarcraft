<?php

declare(strict_types=1);

/**
 * Diff two files on disk and print the GNU-shaped patch, the way a tool would
 * report an edit. Real files go through the string channel: file_get_contents()
 * keeps a missing final newline visible, which a file() list side would hide.
 *
 *   php examples/file-diff.php OLD NEW
 *
 * Exit codes: 0 the files match, 1 they differ, 2 usage or read error.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;

if ($argc !== 3) {
    fwrite(STDERR, "usage: php examples/file-diff.php OLD NEW\n");
    exit(2);
}

[, $oldPath, $newPath] = $argv;

$before = @file_get_contents($oldPath);
$after = @file_get_contents($newPath);
if ($before === false || $after === false) {
    fwrite(STDERR, "cannot read {$oldPath} or {$newPath}\n");
    exit(2);
}

// Annotate a missing final newline so a change that only touches it is visible.
$diff = Diff::compute($before, $after, DiffOptions::new()->withNoNewlineAnnotation());

if ($diff->isEmpty()) {
    echo "no differences\n";
    exit(0);
}

echo $diff->unified($oldPath, $newPath);
echo sprintf("\n%d added, %d removed\n", $diff->addedLines(), $diff->removedLines());
exit(1);
