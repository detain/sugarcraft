<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use SugarCraft\SshServer\Lang;

/**
 * The keepalive interval — a value, not a middleware.
 *
 * (audit sugar-ssh-server-079) This class used to implement the
 * `Middleware` contract with a pure pass-through `handle()` while all
 * of its behaviour lived in a `setTransport()` side effect —
 * discoverable only by reading this docblock. It is now the value
 * holder behind the door that arms it: {@see \SugarCraft\SshServer\Server::withKeepalive()}
 * stores the interval on the server, and `serve()` registers the beat
 * through the transport's {@see \SugarCraft\SshServer\Transport\KeepaliveArming}
 * seam — where the pump actually idles. Passing one to `Server::use()`
 * is now a hard `TypeError`: behaviour that never rode the request
 * path no longer dresses as if it did.
 *
 * The beat itself: under InProcessTransport, every `$intervalSeconds`
 * of pump-loop idle a `\0` byte is written into the child's input side
 * through the PTY master. The byte travels the remote wire only where
 * the tty echoes input — line-oriented shells echo it back, while
 * raw-mode TUIs suppress echo and those sessions see nothing. It is
 * NOT an SSH_MSG_IGNORE packet: the ForceCommand process never touches
 * the SSH binary protocol. Where the echo path exists, the periodic
 * traffic keeps NAT gateways and firewalls from timing out idle SSH
 * connections.
 *
 * Note: For HostSshdTransport, the keepalive relies on sshd
 * configuration (ClientAliveInterval/ServerAliveInterval); the
 * transport does not implement the arming seam and the server's arm
 * is a documented no-op.
 *
 * Example:
 * ```php
 * Server::new()
 *     ->withKeepalive(30)  // Idle byte every 30 seconds
 *     ->use(new Spawn(...))
 *     ->serve();
 * ```
 */
final class Keepalive
{
    /**
     * @param int $intervalSeconds Seconds of pump idle between keepalive bytes (minimum 1)
     */
    public function __construct(
        private readonly int $intervalSeconds = 60,
    ) {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException(Lang::t('keepalive.invalid_interval'));
        }
    }

    /**
     * Get the keepalive interval in seconds.
     */
    public function interval(): int
    {
        return $this->intervalSeconds;
    }
}
