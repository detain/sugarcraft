<?php

declare(strict_types=1);

/**
 * Label the escape sequences in a byte string, the way the CLI does. Walk the
 * segments with Inspector::parse() when you need each piece, or call
 * Inspector::report() for the finished text.
 *
 *   $ php examples/inspect-string.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\SequenceSegment;
use SugarCraft\AnsiInspect\TextSegment;

$bytes = "\e[1;31mboom\e[0m\e]0;build finished\e\\";

// Each segment is either a SequenceSegment (an escape sequence) or a
// TextSegment (visible text). Both carry the byte offset they started at.
foreach (Inspector::parse($bytes) as $segment) {
    $kind = $segment instanceof SequenceSegment ? 'sequence' : 'text';
    printf("%-8s @%-3s %s\n", $kind, dechex((int) $segment->byteOffset()), $segment->describe());
}

echo "\n", Inspector::report($bytes);
