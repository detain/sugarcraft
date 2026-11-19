<?php

declare(strict_types=1);

/**
 * Get the machine-readable report: one object per segment with its type,
 * raw content bytes, human description and input byte offset. This is what
 * `candy-ansi-inspect --json` prints.
 *
 *   $ php examples/json-report.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\AnsiInspect\Inspector;

$json = Inspector::reportAsJson("\e[1;31mboom\e[0m");

// The output is a JSON array, so json_decode() reads it back for scripting.
$segments = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

foreach ($segments as $segment) {
    printf("%-9s offset=%d %s\n", $segment['type'], $segment['byteOffset'], $segment['description']);
}
