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

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Middleware\Logger;
use CandyCore\Wish\Server;
use CandyCore\Wish\Session;

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

Server::new()
    ->use(new Logger())
    ->use(new Banner())
    ->serve();
