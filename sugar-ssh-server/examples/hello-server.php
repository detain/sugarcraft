<?php

/**
 * Minimal SugarSshServer server. Wire this up via sshd's ForceCommand
 * and any user logging in sees a tiny session banner. Useful as
 * the smallest possible end-to-end smoke test of the deployment.
 *
 * /etc/ssh/sshd_config.d/wish.conf:
 *
 *   Match User wishuser
 *       ForceCommand /usr/bin/php /path/to/this/file.php
 *       PermitTTY yes
 *
 * WHAT THIS EXAMPLE DELIBERATELY OMITS. There is no `Auth` and no
 * `RateLimit` in this stack, so *any* user sshd lets through reaches it, and
 * nothing throttles a script hammering the endpoint. That is the open-default
 * posture the library README tabulates under 'Defaults that are open until
 * you close them' - fine for a smoke test, not a deployment. The fields it
 * prints (`$s->user`, `$s->clientHost`, `$s->term`, the geometry) are read
 * from the environment, and any of them an `AcceptEnv` line allows is
 * something the connecting peer asserted, not something sshd proved; treat
 * them as display input, never as an authorization key or a log you trust.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\HostSshdTransport;

final class Banner implements Middleware
{
    public function handle(Context $ctx, Session $s, callable $next): void
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
        $next($ctx, $s);
    }
}

// The same hook, without the class tax: Server::onSession($closure)
// appends the identical stack position (a ClosureMiddleware wrapping
// the callable). Keep whichever door matches the body's size — the
// named class below for a body with logic worth naming, a closure for
// a true one-liner. See the README's Server doors section.

// HostSshdTransport pinned explicitly because Banner reads STDIN
// directly via fread(STDIN, 1). Under the new InProcessTransport
// default, that STDIN is the supervisor's input pipe (already
// being pumped into a sugar-pty master), so an inline read would
// race with the pump. HostSshd keeps STDIN/STDOUT attached to
// sshd's PTY, the way pre-PTY-upgrade sugar-ssh-server always was.
exit(Server::new()
    ->withTransport(new HostSshdTransport())
    // Logger::auto() appends to $SSH_SERVER_LOG when the service sets
    // it; unset, it writes to stderr, and under ForceCommand stderr is
    // the CONNECTED USER's channel — an operator run wants the env var
    // (or Logger::toFile()) so the records are actually kept.
    ->use(Logger::auto())
    ->use(new Banner())
    ->serve());
