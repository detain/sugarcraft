<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * Middleware that periodically sends SSH-level keepalive messages
 * to detect dead connections.
 *
 * When used with InProcessTransport, this middleware registers a
 * callback that writes a null byte through the PTY master at the
 * configured interval. This keeps NAT gateways and firewalls from
 * timing out idle SSH connections.
 *
 * Note: For HostSshdTransport, the keepalive relies on sshd
 * configuration (ClientAliveInterval/ServerAliveInterval).
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

    /**
     * Capture the transport reference when InProcessTransport injects
     * itself at stack-walk time.
     *
     * @param ChildSpawner&InProcessTransport $transport
     */
    public function setTransport(ChildSpawner $transport): void
    {
        if (!$transport instanceof InProcessTransport) {
            // HostSshdTransport or unknown transport — keepalive is
            // handled by sshd configuration; nothing to do here.
            return;
        }

        // Defer the actual keepalive byte to the pump loop timeout
        // path. Each time the loop times out (no I/O ready) the
        // transport invokes our callback; we track elapsed time and
        // only write a null byte when the interval has elapsed.
        $lastSent = \microtime(true);
        $transport->setKeepaliveCallback(function () use ($transport, &$lastSent): void {
            $now = \microtime(true);
            if ($now - $lastSent >= $this->intervalSeconds) {
                // Writing a null byte through the PTY master is safe
                // for shells and most line-oriented programs — it is
                // ignored at the application layer but travels over
                // the wire, keeping the connection alive.
                $transport->getPty()->write("\0");
                $lastSent = $now;
            }
        });
    }

    public function handle(Session $session, callable $next): void
    {
        // Keepalive is passive — it only acts via the transport's
        // pump loop callback registered in setTransport.
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
