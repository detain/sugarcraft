<?php

declare(strict_types=1);

/**
 * SugarMetrics showcase — counter / gauge / histogram primitives,
 * collected by InMemoryBackend so the run is self-contained (no
 * StatsD, no Prometheus textfile, no SSH server). Mirrors what the
 * SessionMetrics middleware records under the hood.
 *
 * Run: php examples/showcase.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Registry;

$tally = new InMemoryBackend();
$registry = new Registry($tally);

// Simulate a handful of TUI sessions.
$registry->counter('wish.sessions.opened', tags: ['app' => 'demo']);
$registry->counter('wish.sessions.opened', tags: ['app' => 'demo']);
$registry->counter('wish.sessions.opened', tags: ['app' => 'docs']);

$registry->gauge('wish.sessions.active', 2, tags: ['app' => 'demo']);
$registry->gauge('wish.sessions.active', 1, tags: ['app' => 'docs']);

foreach ([0.012, 0.020, 0.018, 0.041, 0.009] as $latency) {
    $registry->histogram('wish.session.duration_s', $latency, tags: ['app' => 'demo']);
}

// time() returns a stop-fn — invoke it to record elapsed seconds.
$stop = $registry->time('demo.work_s');
usleep(7_500);  // 7.5 ms of fake work
$stop();

echo "=== Counters ===\n";
foreach ($tally->counters() as $key => $val) {
    echo "  {$key}  →  {$val}\n";
}

echo "\n=== Gauges ===\n";
foreach ($tally->gauges() as $key => $val) {
    echo "  {$key}  →  {$val}\n";
}

echo "\n=== Histograms ===\n";
foreach ($tally->histograms() as $key => $samples) {
    $sum   = array_sum($samples);
    $count = count($samples);
    $avg   = $count > 0 ? $sum / $count : 0.0;
    echo sprintf("  %-44s  count=%d  sum=%.6f  avg=%.6f\n", $key, $count, $sum, $avg);
}

echo "\nBackends ship: InMemoryBackend, JsonBackend, StatsdBackend,\n";
echo "PrometheusFileBackend, MultiBackend (fan-out).\n";
