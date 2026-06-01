<?php

declare(strict_types=1);

/**
 * CandyWish showcase — drives the middleware pipeline against a
 * synthesised Session so the pipeline is observable without spinning
 * up an actual SSH server. Demonstrates Logger + RateLimit + a
 * custom hello middleware in one process.
 *
 * In production you'd point sshd's ForceCommand at hello-server.php
 * and let OpenSSH handle auth / ciphers / PTY allocation; this file
 * just shows the middleware-composition surface.
 *
 * Run: php examples/showcase.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Middleware\RateLimit;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;

// Synthesise a session that would normally come from sshd's env vars.
$session = new Session(
    user:       'ada',
    clientHost: '198.51.100.42',
    clientPort: 51234,
    serverHost: '203.0.113.7',
    serverPort: 22,
    term:       'xterm-256color',
    cols:       120,
    rows:       40,
    tty:        '/dev/pts/3',
    command:    null,
    lang:       'en_US.UTF-8',
);

echo "=== Synthetic session ===\n";
foreach ($session->toLogContext() as $k => $v) {
    echo sprintf("  %-12s = %s\n", $k, var_export($v, true));
}
echo "\n";

echo "=== Pipeline: Logger → RateLimit → hello ===\n";
Server::new()
    ->use(new Logger())
    ->use(new RateLimit(statePath: sys_get_temp_dir() . '/wish-showcase-buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new class implements Middleware {
        public function handle(Context $ctx, Session $s, callable $next): void
        {
            echo "  [hello] welcome, {$s->user}@{$s->clientHost}\n";
            echo "  [hello]   pty {$s->cols}x{$s->rows}, term={$s->term}\n";
            $next($ctx, $s);
        }
    })
    ->serve($session);

echo "\nDeploy via OpenSSH ForceCommand — sshd handles auth, ciphers,\n";
echo "fail2ban, audit logs. CandyWish gives you the middleware model.\n";
