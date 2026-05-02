<?php

declare(strict_types=1);

namespace CandyCore\Wish;

/**
 * SSH session middleware contract.
 *
 * A middleware is a callable that receives the active {@see Session}
 * plus a `$next` continuation it can choose to invoke. Middleware
 * compose like Express / PSR-15 / charmbracelet/wish: each one can
 * inspect, log, short-circuit, mutate the world, and then either
 * call `$next($session)` to delegate down the chain or return
 * without calling it to stop the request.
 *
 * The terminal middleware in a stack is usually
 * {@see Middleware\BubbleTea} (mount a CandyCore Program) — it
 * never calls `$next`, it just runs the program until the user
 * disconnects.
 */
interface Middleware
{
    public function handle(Session $session, callable $next): void;
}
