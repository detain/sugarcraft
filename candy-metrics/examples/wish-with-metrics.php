<?php

/**
 * Wire CandyWish + CandyMetrics. Session telemetry is fanned out to
 * a Prometheus textfile (for scraping) and an in-memory backend
 * (so the example can print the totals on shutdown).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Metrics\Backend\InMemoryBackend;
use CandyCore\Metrics\Backend\MultiBackend;
use CandyCore\Metrics\Backend\PrometheusFileBackend;
use CandyCore\Metrics\Middleware\SessionMetrics;
use CandyCore\Metrics\Registry;
use CandyCore\Wish\Middleware\Logger;
use CandyCore\Wish\Server;
use CandyCore\Wish\Session;

$tally = new InMemoryBackend();
$registry = new Registry(new MultiBackend(
    $tally,
    new PrometheusFileBackend('/tmp/candy-wish-metrics.prom'),
));

Server::new()
    ->use(new Logger())
    ->use(new SessionMetrics($registry))
    ->use(new class implements \CandyCore\Wish\Middleware {
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
