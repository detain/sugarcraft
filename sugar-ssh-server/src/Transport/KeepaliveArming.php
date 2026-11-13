<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

/**
 * The one pump-idle hook that {@see \SugarCraft\SshServer\Server::withKeepalive()} arms.
 *
 * Registration lives in the Server wiring, not inside a middleware's
 * transport side effect (audit sugar-ssh-server-079): `Server::serve()`
 * is the single legitimate caller in this library, once per entry, and
 * only when the server was armed. Transports that own an idle path
 * implement this seam and tickle it on their own mechanism; transports
 * without one (HostSshdTransport) simply do not implement it, and the
 * server's arm degrades to the documented no-op that sshd's
 * `ClientAliveInterval`/`ServerAliveInterval` territory already is.
 */
interface KeepaliveArming
{
    /**
     * Begin emitting the keepalive beat: at most one per transport,
     * replaced (not stacked) by later calls.
     *
     * @param int $intervalSeconds Seconds of pump idle between beats (minimum 1)
     *
     * @throws \InvalidArgumentException when $intervalSeconds is below 1
     */
    public function armKeepalive(int $intervalSeconds): void;
}
