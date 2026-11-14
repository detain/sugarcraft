<?php

declare(strict_types=1);

/**
 * Wire SugarSshServer + SugarMetrics. Session telemetry is fanned out to
 * a Prometheus textfile (for scraping) and an in-memory backend
 * (so the example can print the totals on shutdown).
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\MultiBackend;
use SugarCraft\Metrics\Backend\PrometheusFileBackend;
use SugarCraft\Metrics\Middleware\SessionMetrics;
use SugarCraft\Metrics\Registry;
use SugarCraft\Metrics\Util;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

// Attacker-influenced bytes (usernames, tag values folded into storage
// keys) are swept with Util::displaySafe — the shared door also handles
// the UTF-8-encoded C1 forms (\xC2\x9B decodes to a real CSI on
// xterm-class terminals) that a bare C0/DEL character class leaks
// through. Do not re-implement the sweep here: examples get copied into
// production, so the doctrine (src/Util.php) is one delegate call away.

/**
 * Terminal middleware of the chain: greet the user, flush the textfile
 * so the Prometheus node exporter sees this session's totals, then stop.
 * The signature mirrors {@see \SugarCraft\Metrics\Middleware\SessionMetrics}
 * — the {@see Middleware} contract is handle(Context, Session, callable).
 */
final class HelloMetrics implements Middleware
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        echo 'Hello, ' . Util::displaySafe($session->user) . "!\n";
        $next($ctx, $session);
        // Long-lived servers must drive Registry::flush() themselves —
        // the library arms no timers. Flushing per session keeps the
        // textfile current between scrapes.
        $this->registry->flush();
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
    ->use(new HelloMetrics($registry))
    ->serve();

fwrite(STDERR, "\n--- session totals ---\n");
foreach ($tally->counters() as $key => $val) {
    fwrite(STDERR, '  counter   ' . Util::displaySafe((string) $key) . "  {$val}\n");
}
foreach ($tally->histograms() as $key => $samples) {
    $sum = array_sum($samples);
    fwrite(STDERR, sprintf("  histogram %s  count=%d sum=%.6f\n", Util::displaySafe((string) $key), count($samples), $sum));
}
