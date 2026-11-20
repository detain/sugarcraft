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
 * Flush the textfile after each session so the Prometheus node exporter sees
 * this session's totals. Registered before SessionMetrics so the flush runs
 * after SessionMetrics has recorded the session's duration and error samples,
 * and in a finally so a session that throws still reaches the textfile.
 */
final class FlushMetrics implements Middleware
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $completed = false;
        try {
            $next($ctx, $session);
            $completed = true;
        } finally {
            try {
                // Long-lived servers must drive Registry::flush() themselves —
                // the library arms no timers.
                $this->registry->flush();
            } catch (\Throwable $flushError) {
                // A flush failure must not replace the session's own exception;
                // on a clean session there is none to protect, so it surfaces.
                if ($completed) {
                    throw $flushError;
                }
            }
        }
    }
}

/**
 * Terminal middleware of the chain: greet the user, then stop. The signature
 * mirrors {@see \SugarCraft\Metrics\Middleware\SessionMetrics} — the
 * {@see Middleware} contract is handle(Context, Session, callable).
 */
final class HelloMetrics implements Middleware
{
    public function handle(Context $ctx, Session $session, callable $next): void
    {
        echo 'Hello, ' . Util::displaySafe($session->user) . "!\n";
        $next($ctx, $session);
    }
}

$tally = InMemoryBackend::new();
// The scratch textfile is per-process unique: two runs (or CI shards) must
// never share the inode, and the smoke test passes SSH_METRICS_TEXTFILE to
// aim the child at a path it can clean up after itself.
$textfile = (string) (\getenv('SSH_METRICS_TEXTFILE') ?: \sys_get_temp_dir() . '/sugar-ssh-server-metrics-' . \uniqid((string) \getmypid(), true) . '.prom');
$registry = Registry::new(MultiBackend::new(
    $tally,
    PrometheusFileBackend::new($textfile),
));

Server::new()
    ->use(new Logger())
    ->use(new FlushMetrics($registry))
    ->use(SessionMetrics::new($registry))
    ->use(new HelloMetrics())
    ->serve();

fwrite(STDERR, "\n--- session totals ---\n");
// One loop over the structured read surface (sugar-metrics-022): snapshot
// decodes the faithful name + tags, histogramSummary owns the reservoir
// aggregation — no hand-rolled key parsing or reservoir aggregation here.
foreach ($tally->snapshot() as $row) {
    $shown = Util::displaySafe($row['name']);
    if ($row['tags'] !== []) {
        $parts = [];
        foreach ($row['tags'] as $k => $v) {
            $parts[] = Util::displaySafe((string) $k) . '=' . Util::displaySafe((string) $v);
        }
        $shown .= '{' . implode(',', $parts) . '}';
    }
    if ($row['kind'] === 'histogram') {
        $summary = $tally->histogramSummary($row['name'], $row['tags']) ?? ['count' => 0, 'sum' => 0.0];
        fwrite(STDERR, sprintf("  histogram %s  count=%d sum=%.6f\n", $shown, $summary['count'], $summary['sum']));
        continue;
    }
    fwrite(STDERR, '  ' . $row['kind'] . '   ' . $shown . "  {$row['value']}\n");
}
