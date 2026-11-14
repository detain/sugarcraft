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
use SugarCraft\Metrics\Util;

$tally = InMemoryBackend::new();
$registry = Registry::new($tally);

// Simulate a handful of TUI sessions.
$registry->counter('ssh.sessions.opened', tags: ['app' => 'demo']);
$registry->counter('ssh.sessions.opened', tags: ['app' => 'demo']);
$registry->counter('ssh.sessions.opened', tags: ['app' => 'docs']);

$registry->gauge('ssh.sessions.active', 2, tags: ['app' => 'demo']);
$registry->gauge('ssh.sessions.active', 1, tags: ['app' => 'docs']);

foreach ([0.012, 0.020, 0.018, 0.041, 0.009] as $latency) {
    $registry->histogram('ssh.session.duration_s', $latency, tags: ['app' => 'demo']);
}

// time() returns a stop-fn — invoke it to record elapsed seconds.
$stop = $registry->time('demo.work_s');
usleep(7_500);  // 7.5 ms of fake work
$stop();

// Snapshot rows carry the caller-supplied tag values back, and they can
// carry hostile bytes (usernames, request ids). Sweep control characters
// through Util::displaySafe — the shared door — before printing so a
// labelled metric can never forge terminal escape sequences or line
// breaks into the demo output. The UTF-8-encoded C1 forms (\xC2\x9B is a
// real CSI once a terminal decodes it) survive a bare C0/DEL character
// class; do not re-implement the sweep in examples.

/** Left-pad to a display width that counts East-Asian wide glyphs as 2. */
function padCell(string $text, int $width): string
{
    return $text . \str_repeat(' ', max(0, $width - mb_strwidth($text)));
}

// Render one snapshot row (sugar-metrics-022 read surface) as `name{k=v,…}`
// with every token swept through the shared displaySafe door.
$renderRow = static function (array $row): string {
    $shown = Util::displaySafe($row['name']);
    if ($row['tags'] !== []) {
        $parts = [];
        foreach ($row['tags'] as $k => $v) {
            $parts[] = Util::displaySafe((string) $k) . '=' . Util::displaySafe((string) $v);
        }
        $shown .= '{' . implode(',', $parts) . '}';
    }
    return $shown;
};

echo "=== Counters ===\n";
foreach ($tally->snapshot() as $row) {
    if ($row['kind'] !== 'counter') {
        continue;
    }
    echo '  ' . padCell($renderRow($row), 44) . "  {$row['value']}\n";
}

echo "\n=== Gauges ===\n";
foreach ($tally->snapshot() as $row) {
    if ($row['kind'] !== 'gauge') {
        continue;
    }
    echo '  ' . padCell($renderRow($row), 44) . "  {$row['value']}\n";
}

echo "\n=== Histograms ===\n";
foreach ($tally->snapshot() as $row) {
    if ($row['kind'] !== 'histogram') {
        continue;
    }
    // The reservoir aggregation is the backend's job (sugar-metrics-022):
    // histogramSummary returns count/observed/sum/mean — no hand-rolled
    // aggregation pass in the example any more.
    $summary = $tally->histogramSummary($row['name'], $row['tags']) ?? ['count' => 0, 'sum' => 0.0, 'mean' => 0.0];
    echo '  ' . padCell($renderRow($row), 44) . sprintf('  count=%d  sum=%.6f  avg=%.6f', $summary['count'], $summary['sum'], $summary['mean']) . "\n";
}

echo "\nBackends ship: InMemoryBackend, JsonStreamBackend, StatsdBackend,\n";
echo "PrometheusFileBackend, MultiBackend (fan-out).\n";
