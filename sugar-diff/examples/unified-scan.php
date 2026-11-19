<?php

declare(strict_types=1);

/**
 * Number the rows of an existing patch, the way a gutter or a review pane does.
 * The input is any unified diff text (git, GNU diff or this library); the scanner
 * reports which old and new file line each row points at and what kind of row it
 * is, without drawing anything.
 *
 *   php examples/unified-scan.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Diff\Reader\UnifiedScan;

$patch = <<<'PATCH'
--- a/config.php
+++ b/config.php
@@ -1,4 +1,4 @@
 <?php
-$debug = true;
+$debug = false;
 return [];
@@ -10 +10,2 @@
-old;
+new;
+added;
PATCH;

// A list input is one element per row, so the scan result lines up with the
// rows of the patch by index.
$rows = explode("\n", $patch);

printf("%4s %4s  %-16s %s\n", 'old', 'new', 'kind', 'row');
foreach (UnifiedScan::lines($rows) as $i => $row) {
    printf(
        "%4s %4s  %-16s %s\n",
        $row->old ?? '',
        $row->new ?? '',
        $row->kind->name,
        $rows[$i],
    );
}

// Which rows start a new file, for a viewer that groups by file:
echo "\nfile headers: " . implode(' ', array_map(
    static fn (bool $header): string => $header ? 'yes' : 'no',
    UnifiedScan::fileHeaders($rows),
)) . "\n";
