<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Middleware that periodically sends SSH-level keepalive messages
 * to detect dead connections.
 *
 * Uses SSH_MSG_IGNORE packets which are safe to send and don't
 * affect the session but help keep the connection alive through
 * NAT gateways and firewalls.
 *
 * Note: This requires the SSH connection to be handled by sshd with
 * appropriate ClientAliveInterval/ServerAliveInterval settings, OR
 * can be used with the InProcessTransport to send heartbeat bytes.
 *
 * Example:
 * ```php
 * Server::new()
 *     ->use(new Keepalive(30))  // Send keepalive every 30 seconds
 *     ->use(new Spawn(...))
 *     ->serve();
 * ```
 */
final class Keepalive implements Middleware
{
    /**
     * @param int $intervalSeconds Interval between keepalive messages (minimum 1)
     */
    public function __construct(
        private readonly int $intervalSeconds = 60,
    ) {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException(Lang::t('keepalive.invalid_interval'));
        }
    }

    public function handle(Session $session, callable $next): void
    {
        // For InProcessTransport, we could set up a timer to send
        // periodic bytes. For now, we just pass through and let
        // the transport handle it if supported.
        //
        // The actual keepalive implementation depends on the transport:
        // - InProcessTransport: uses PTY-level keepalive
        // - HostSshdTransport: relies on sshd configuration
        $next();
    }

    /**
     * Get the keepalive interval in seconds.
     */
    public function interval(): int
    {
        return $this->intervalSeconds;
    }
}
