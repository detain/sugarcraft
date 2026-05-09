<?php

/**
 * Minimal CandyWish server. Wire this up via sshd's ForceCommand
 * and any user logging in sees a tiny session banner. Useful as
 * the smallest possible end-to-end smoke test of the deployment.
 *
 * /etc/ssh/sshd_config.d/wish.conf:
 *
 *   Match User wishuser
 *       ForceCommand /usr/bin/php /path/to/this/file.php
 *       PermitTTY yes
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\HostSshdTransport;

final class Banner implements Middleware
{
    public function handle(Session $s, callable $next): void
    {
        $cols = max(40, $s->cols);
        $line = str_repeat('─', $cols);
        echo "\n";
        echo "  Hello, {$s->user}.\n";
        echo "  You connected from {$s->clientHost}:{$s->clientPort}.\n";
        echo "  Your terminal is {$s->term} ({$s->cols}×{$s->rows}).\n";
        echo "  Press any key to disconnect.\n";
        echo "  {$line}\n";
        // Wait for one byte from stdin so the user actually sees the
        // banner before sshd tears down the session.
        if ($s->isInteractive()) {
            fread(STDIN, 1);
        }
        $next($s);
    }
}

// HostSshdTransport pinned explicitly because Banner reads STDIN
// directly via fread(STDIN, 1). Under the new InProcessTransport
// default, that STDIN is the supervisor's input pipe (already
// being pumped into a candy-pty master), so an inline read would
// race with the pump. HostSshd keeps STDIN/STDOUT attached to
// sshd's PTY, the way pre-PTY-upgrade candy-wish always was.
Server::new()
    ->withTransport(new HostSshdTransport())
    ->use(new Logger())
    ->use(new Banner())
    ->serve();
