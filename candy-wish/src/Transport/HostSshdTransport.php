<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Middleware;
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
    public function run(Session $session, array $stack): void
    {
        $this->dispatch($session, $stack, 0);
    }

    /**
     * @param list<Middleware> $stack
     */
    private function dispatch(Session $session, array $stack, int $idx): void
    {
        if ($idx >= \count($stack)) {
            return;
        }
        $next = function (Session $s) use ($stack, $idx): void {
            $this->dispatch($s, $stack, $idx + 1);
        };
        $stack[$idx]->handle($session, $next);
    }
}
