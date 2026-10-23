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
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

/**
 * Terminal middleware of the chain: greet the user, then stop. The
 * signature mirrors {@see \SugarCraft\Metrics\Middleware\SessionMetrics}
 * — the {@see Middleware} contract is handle(Context, Session, callable).
 */
final class HelloMetrics implements Middleware
{
    public function handle(Context $ctx, Session $session, callable $next): void
    {
        echo "Hello, {$session->user}!\n";
        $next($ctx, $session);
    }
}

$tally = new InMemoryBackend();
// The scratch textfile is per-process unique: two runs (or CI shards) must
// never share the inode, and the smoke test passes SSH_METRICS_TEXTFILE to
// aim the child at a path it can clean up after itself.
$textfile = (string) (\getenv('SSH_METRICS_TEXTFILE') ?: \sys_get_temp_dir() . '/sugar-ssh-server-metrics-' . \uniqid((string) \getmypid(), true) . '.prom');
$registry = new Registry(new MultiBackend(
    $tally,
    new PrometheusFileBackend($textfile),
));

Server::new()
    ->use(new Logger())
    ->use(new SessionMetrics($registry))
    ->use(new HelloMetrics())
    ->serve();

fwrite(STDERR, "\n--- session totals ---\n");
foreach ($tally->counters() as $key => $val) {
    fwrite(STDERR, "  counter   {$key}  {$val}\n");
}
foreach ($tally->histograms() as $key => $samples) {
    $sum = array_sum($samples);
    fwrite(STDERR, sprintf("  histogram %s  count=%d sum=%.6f\n", $key, count($samples), $sum));
}
