<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport;

/**
 * Legacy transport — runs the middleware stack directly in the
 * supervisor process. STDIN/STDOUT are the slave side of sshd's
 * PTY (sshd allocated it for the SSH session and exposed it via
 * the inherited file descriptors).
 *
 * This is the architecture candy-wish shipped with from day one:
 * one PHP process per SSH connection, middleware run inline, the
 * terminal {@see \SugarCraft\Wish\Middleware\BubbleTea} mounts a
 * SugarCraft `Program` reading STDIN / writing STDOUT directly.
 *
 * Opt-in via `Server::new()->withTransport(new HostSshdTransport())`.
 * The default transport is {@see InProcessTransport}.
 */
final class HostSshdTransport implements Transport
{
    use DispatchesMiddlewareStack;

    public function run(Context $ctx, Session $session, array $stack): int
    {
        $this->dispatch($ctx, $session, $stack, 0);

        // No child process exists under the inline transport — the
        // middleware chain completing without a raise IS success here
        // (a BubbleTea Program's own status never crosses the sshd PTY
        // boundary any differently than an interactive shell's would).
        return 0;
    }
}
