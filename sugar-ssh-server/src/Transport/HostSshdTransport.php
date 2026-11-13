<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport;

/**
 * Legacy transport — runs the middleware stack directly in the
 * supervisor process. STDIN/STDOUT are the slave side of sshd's
 * PTY (sshd allocated it for the SSH session and exposed it via
 * the inherited file descriptors).
 *
 * This is the architecture sugar-ssh-server shipped with from day one:
 * one PHP process per SSH connection, middleware run inline, the
 * terminal {@see \SugarCraft\SshServer\Middleware\BubbleTea} mounts a
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
        // No child of ours exists under the inline transport, but the
        // chain can still refuse: an Auth gate that says no must not
        // answer 0 just because nothing was spawned (2026-11 audit).
        // A BubbleTea Program's own status still never crosses the sshd
        // PTY boundary — that remains the documented difference from a
        // spawner transport.
        return $this->dispatch($ctx, $session, $stack, 0);
    }
}
