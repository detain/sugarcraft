<?php

declare(strict_types=1);

/**
 * Concurrent-flush probe child for PrometheusFileBackendTest.
 *
 * Stands up its own backend on the path argv[1] and flushes in a loop while
 * the parent test process flushes the SAME path from its own instance. Any
 * shared staging file between the two writers surfaces here as a
 * rename_failed RuntimeException (exit 255) — the shape the unique-per-writer
 * temp name is designed to make impossible.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Metrics\Backend\PrometheusFileBackend;

$backend = new PrometheusFileBackend($argv[1]);
for ($i = 0; $i < 30; $i++) {
    $backend->counter('child_tally', 1.0, ['writer' => 'child']);
    $backend->flush();
    usleep(2000);
}

exit(0);
