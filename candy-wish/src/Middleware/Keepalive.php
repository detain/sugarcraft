<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;
use SugarCraft\Wish\Transport\InProcessTransport;
use SugarCraft\Wish\TransportAware;

/**
 * Middleware that writes an idle keepalive byte through the PTY
 * master at a configurable interval.
 *
 * Under InProcessTransport the callback fires on pump-loop idle and
 * writes a `\0` byte into the child's input side. The byte reaches
 * the remote wire only where the tty echoes input — line-oriented
 * shells echo it back, while raw-mode TUIs suppress echo and those
 * sessions see nothing. It is NOT an SSH_MSG_IGNORE packet: the
 * ForceCommand process never touches the SSH binary protocol. Where
 * the echo path exists, the periodic traffic keeps NAT gateways and
 * firewalls from timing out idle SSH connections.
 *
 * Note: For HostSshdTransport, the keepalive relies on sshd
 * configuration (ClientAliveInterval/ServerAliveInterval).
 *
 * Example:
 * ```php
 * Server::new()
 *     ->use(new Keepalive(30))  // Idle byte every 30 seconds
 *     ->use(new Spawn(...))
 *     ->serve();
 * ```
 */
final class Keepalive implements Middleware, TransportAware
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
                // ignored at the application layer, and wherever the
                // tty echoes it back the periodic traffic keeps NAT
                // warm. Raw-mode TUIs never echo: silent by design.
                $transport->pty()->write("\0");
                $lastSent = $now;
            }
        });
    }

    public function handle(Context $ctx, Session $session, callable $next)
    {
        // Keepalive is passive — it only acts via the transport's
        // pump loop callback registered in setTransport.
        $next($ctx, $session);
    }

    /**
     * Get the keepalive interval in seconds.
     */
    public function interval(): int
    {
        return $this->intervalSeconds;
    }
}
