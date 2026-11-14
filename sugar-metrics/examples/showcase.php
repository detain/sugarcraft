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

$tally = new InMemoryBackend();
$registry = new Registry($tally);

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

// Storage keys fold caller-supplied tag values in, and those values can
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

echo "=== Counters ===\n";
foreach ($tally->counters() as $key => $val) {
    $shown = Util::displaySafe((string) $key);
    echo "  {$shown}  →  {$val}\n";
}

echo "\n=== Gauges ===\n";
foreach ($tally->gauges() as $key => $val) {
    $shown = Util::displaySafe((string) $key);
    echo "  {$shown}  →  {$val}\n";
}

echo "\n=== Histograms ===\n";
foreach ($tally->histograms() as $key => $samples) {
    $sum   = array_sum($samples);
    $count = count($samples);
    $avg   = $count > 0 ? $sum / $count : 0.0;
    echo '  ' . padCell(Util::displaySafe((string) $key), 44) . sprintf('  count=%d  sum=%.6f  avg=%.6f', $count, $sum, $avg) . "\n";
}

echo "\nBackends ship: InMemoryBackend, JsonStreamBackend, StatsdBackend,\n";
echo "PrometheusFileBackend, MultiBackend (fan-out).\n";
