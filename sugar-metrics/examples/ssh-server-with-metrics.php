<?php

/**
 * Wire SugarSshServer + SugarMetrics. Session telemetry is fanned out to
 * a Prometheus textfile (for scraping) and an in-memory backend
 * (so the example can print the totals on shutdown).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\MultiBackend;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;
use SugarCraft\Metrics\Registry;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

$tally = new InMemoryBackend();
$registry = new Registry(new MultiBackend(
    $tally,
    new PrometheusFileBackend('/tmp/sugar-ssh-server-metrics.prom'),
));

Server::new()
    ->use(new Logger())
    ->use(new SessionMetrics($registry))
    ->use(new class implements \SugarCraft\SshServer\Middleware {
        public function handle(Session $s, callable $next): void
        {
            echo "Hello, {$s->user}!\n";
            $next($s);
        }
    })
    ->serve();

fwrite(STDERR, "\n--- session totals ---\n");
foreach ($tally->counters() as $key => $val) {
    fwrite(STDERR, "  counter   {$key}  {$val}\n");
}
foreach ($tally->histograms() as $key => $samples) {
    $sum = array_sum($samples);
    fwrite(STDERR, sprintf("  histogram %s  count=%d sum=%.6f\n", $key, count($samples), $sum));
}
