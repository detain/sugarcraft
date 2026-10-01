<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

use SugarCraft\Wish\Transport\ChildSpawner;

/**
 * Contract for middleware that needs the active transport injected.
 *
 * {@see \SugarCraft\Wish\Transport\InProcessTransport} calls
 * {@see setTransport()} on every middleware in the stack at
 * stack-walk time, before the first `handle()`, so spawner-aware
 * middleware ({@see \SugarCraft\Wish\Middleware\Spawn},
 * {@see \SugarCraft\Wish\Middleware\Keepalive},
 * {@see \SugarCraft\Wish\Middleware\BubbleTea}) can reach the PTY
 * supervisor without the Server wiring it by hand.
 *
 * Declaring the interface is the typed replacement for the old
 * `method_exists($mw, 'setTransport')` duck-type: the transport
 * checks `instanceof TransportAware`, so a mis-typed `setTransport`
 * signature is a compile-time class error instead of a silent miss.
 * Production code never calls `setTransport()` directly — it is the
 * transport's seam (visible public only because PHP has no
 * friend-calls-only visibility).
 */
interface TransportAware
{
    /**
     * Receive the transport driving the current stack.
     *
     * Implementations must tolerate being called more than once
     * (a Server reused across sessions re-injects) and must not
     * retain state that assumes an active pump loop — the child
     * PTY is only reachable while a `runChild()` pump is live.
     */
    public function setTransport(ChildSpawner $transport): void;
}
