<?php

declare(strict_types=1);

/**
 * Programmatic inspection of a cassette — reads it via JsonlFormat
 * and prints the events with their decoded Msg envelopes (when
 * present) using the default serializer Registry.
 *
 * Equivalent to `bin/candy-vcr inspect <cassette>` but written in
 * library code so you can copy/paste into your own tooling.
 *
 * Usage:
 *   php examples/inspect.php /tmp/session.cas
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Msg\Registry;

$path = $argv[1] ?? __DIR__ . '/cassettes/counter.cas';
if (!file_exists($path)) {
    fwrite(STDERR, "cassette not found: {$path}\n");
    exit(1);
}

$cassette = (new JsonlFormat())->read($path);
$registry = Registry::new();

printf(
    "cassette v%d  %dx%d  runtime=%s\n%d event(s), duration %.3fs\n\n",
    $cassette->header->version,
    $cassette->header->cols,
    $cassette->header->rows,
    $cassette->header->runtime,
    $cassette->eventCount(),
    $cassette->duration(),
);

foreach ($cassette->events as $event) {
    printf('  t=%.3fs  %-7s  ', $event->t, $event->kind->value);
    switch ($event->kind->value) {
        case 'resize':
            echo $event->payload['cols'], 'x', $event->payload['rows'], "\n";
            break;
        case 'output':
            echo strlen((string) ($event->payload['b'] ?? '')), " bytes\n";
            break;
        case 'input':
            if (isset($event->payload['msg'])) {
                // VCR-S41 made a claimed-but-malformed envelope a named
                // refusal instead of an escaped \ValueError; the tape is
                // still somebody's data, so the example prints '@?' for
                // it and keeps walking the events instead of dying.
                try {
                    $decoded = $registry->decode($event->payload['msg']);
                } catch (\UnexpectedValueException) {
                    $decoded = null;
                }
                echo $decoded ? '@' . $decoded::class : '@?', "\n";
            } else {
                echo strlen((string) ($event->payload['b'] ?? '')), " bytes\n";
            }
            break;
        case 'quit':
            echo "\n";
            break;
    }
}
