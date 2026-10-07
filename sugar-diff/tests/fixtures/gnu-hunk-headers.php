<?php

declare(strict_types=1);

/**
 * GNU `diff` parity corpus for hunk headers (audit 2026-10-07).
 *
 * Generated from the shell oracle (14 cases; regenerate with the sketch in
 * tests/GnuHunkHeaderParityTest.php's docblock):
 *   diff -U<context> old new   — files end in a trailing newline so no
 *   '\ No newline at end of file' rows appear; the two '--- '/'+++ ' rows
 *   are stripped, leaving exactly what Diff::hunkText() must reproduce.
 * Byte-equality here is checked WITHOUT /usr/bin/diff at test time, so the
 * pins hold on hosts without GNU diff and across diff versions.
 */

return [
    "mid-insertion-u0" => [
        'old' => "a1\na2\na3\n",
        'new' => "a1\nX\na2\na3\n",
        'context' => 0,
        'hunks' => "@@ -1,0 +2 @@\n+X\n",
    ],
    "append-u0" => [
        'old' => "a1\na2\na3\n",
        'new' => "a1\na2\na3\nZ\n",
        'context' => 0,
        'hunks' => "@@ -3,0 +4 @@\n+Z\n",
    ],
    "head-insertion-u0" => [
        'old' => "a1\na2\na3\n",
        'new' => "W\na1\na2\na3\n",
        'context' => 0,
        'hunks' => "@@ -0,0 +1 @@\n+W\n",
    ],
    "two-deletions-u0" => [
        'old' => "a1\na2\na3\na4\n",
        'new' => "a1\na3\n",
        'context' => 0,
        'hunks' => "@@ -2 +1,0 @@\n-a2\n@@ -4 +2,0 @@\n-a4\n",
    ],
    "one-to-one-change-u1" => [
        'old' => "a1\na2\na3\n",
        'new' => "a1\nQ\na3\n",
        'context' => 1,
        'hunks' => "@@ -1,3 +1,3 @@\n a1\n-a2\n+Q\n a3\n",
    ],
    "one-to-one-change-u0" => [
        'old' => "a1\na2\na3\n",
        'new' => "a1\nQ\na3\n",
        'context' => 0,
        'hunks' => "@@ -2 +2 @@\n-a2\n+Q\n",
    ],
    "full-replacement-u0" => [
        'old' => "a1\n",
        'new' => "b1\nb2\n",
        'context' => 0,
        'hunks' => "@@ -1 +1,2 @@\n-a1\n+b1\n+b2\n",
    ],
    "append-after-five-u0" => [
        'old' => "a1\na2\na3\na4\na5\n",
        'new' => "a1\na2\na3\na4\na5\nb\n",
        'context' => 0,
        'hunks' => "@@ -5,0 +6 @@\n+b\n",
    ],
    "context-three-insert" => [
        'old' => "l1\nl2\nl3\nl4\nl5\nl6\nl7\n",
        'new' => "l1\nl2\nl3\nNEW\nl4\nl5\nl6\nl7\n",
        'context' => 3,
        'hunks' => "@@ -1,6 +1,7 @@\n l1\n l2\n l3\n+NEW\n l4\n l5\n l6\n",
    ],
    "interior-deletion-u1" => [
        'old' => "a1\na2\na3\na4\na5\n",
        'new' => "a1\na2\na4\na5\n",
        'context' => 1,
        'hunks' => "@@ -2,3 +2,2 @@\n a2\n-a3\n a4\n",
    ],
    "multi-hunk-u1" => [
        'old' => "a\nb\nc\nd\ne\nf\ng\n",
        'new' => "a\nB\nc\nd\ne\nF\ng\n",
        'context' => 1,
        'hunks' => "@@ -1,3 +1,3 @@\n a\n-b\n+B\n c\n@@ -5,3 +5,3 @@\n e\n-f\n+F\n g\n",
    ],
    "prepend-block-u2" => [
        'old' => "k1\nk2\nk3\n",
        'new' => "P1\nP2\nk1\nk2\nk3\n",
        'context' => 2,
        'hunks' => "@@ -1,2 +1,4 @@\n+P1\n+P2\n k1\n k2\n",
    ],
    "delete-all-u0" => [
        'old' => "solo\n",
        'new' => "",
        'context' => 0,
        'hunks' => "@@ -1 +0,0 @@\n-solo\n",
    ],
    "insert-into-empty-u0" => [
        'old' => "",
        'new' => "solo\n",
        'context' => 0,
        'hunks' => "@@ -0,0 +1 @@\n+solo\n",
    ],
];
