<?php

// Child-process entry for JsonStreamBackendTest::testTwoOwnedProcessesAppendLineCleanRecords
// (sugar-metrics-009 concurrent-append leg). argv: autoload path, target file, process tag.

declare(strict_types=1);

require $argv[1];

$backend = new SugarCraft\Metrics\Backend\JsonStreamBackend($argv[2]);
for ($i = 0; $i < 25; $i++) {
    $backend->counter('proc_' . $argv[3], (float) $i, ['seq' => (string) $i, 'pad' => str_repeat('p', 400)]);
}
