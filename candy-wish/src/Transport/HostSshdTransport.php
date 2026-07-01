<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Context;
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
    public function run(Context $ctx, Session $session, array $stack): void
    {
        // Normalize transport injection across both transport implementations.
        // InProcessTransport calls setTransport on all middleware in its run()
        // before dispatching; HostSshdTransport was missing this, causing
        // middleware that relies on setTransport to behave inconsistently.
        foreach ($stack as $mw) {
            if (\method_exists($mw, 'setTransport')) {
                $mw->setTransport($this);
            }
        }

        $this->dispatch($ctx, $session, $stack, 0);
    }

    /**
     * @param list<Middleware> $stack
     */
    private function dispatch(Context $ctx, Session $session, array $stack, int $idx): void
    {
        if ($idx >= \count($stack)) {
            return;
        }
        if ($ctx->done()) {
            return;
        }
        $next = function (Context $c, Session $s) use ($stack, $idx): void {
            $this->dispatch($c, $s, $stack, $idx + 1);
        };
        $result = $stack[$idx]->handle($ctx, $session, $next);
        if ($result instanceof \React\Promise\PromiseInterface) {
            PromiseAwait::settle($result);
        }
    }
}
