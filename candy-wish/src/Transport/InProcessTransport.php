<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport;

/**
 * Default transport — allocates a `candy-pty` master/slave pair,
 * spawns the user's cmd as a subprocess with
 * `controllingTerminal: true`, and pumps bytes between the
 * supervisor's STDIN/STDOUT and the PTY master.
 *
 * **PR1 stub.** The full implementation (PTY allocation, child
 * spawn, bytes-pump loop, SIGWINCH forwarding) lands in PR2-PR4.
 * Until then `run()` walks the middleware stack like
 * {@see HostSshdTransport} does, so non-spawning middleware (Logger,
 * RateLimit, Auth, ad-hoc inline middleware) work today against
 * either transport. Only spawning middleware (PR3's `Spawn`) needs
 * the new transport seam.
 *
 * @see plans/plan-candy-wish-pty.md
 */
final class InProcessTransport implements Transport
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
