<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

/**
 * SSH session middleware contract.
 *
 * A middleware receives the active {@see Context} and {@see Session}
 * plus a `$next` continuation it can choose to invoke. Middleware
 * compose like Express / PSR-15 / charmbracelet/wish: each one can
 * inspect, log, short-circuit, mutate the world, and then either
 * call `$next($ctx, $session)` to delegate down the chain or return
 * without calling it to stop the request.
 *
 * The terminal middleware in a stack is usually
 * {@see Middleware\BubbleTea} (mount a SugarCraft Program) — it
 * never calls `$next`, it just runs the program until the user
 * disconnects.
 *
 * Middleware may also return a `\React\Promise\PromiseInterface`,
 * allowing async back-ends (LDAP, OAuth, database auth) to stall
 * the chain without blocking. Use {@see AsyncMiddleware} as a base
 * for promise-returning middleware.
 */
interface Middleware
{
    /**
     * May return void (synchronous) or a
     * \React\Promise\PromiseInterface (asynchronous). The transport
     * will wait for the promise to settle before continuing the
     * chain.
     *
     * The union is a docblock contract because it cannot be spelled
     * natively: PHP rejects `void` inside any return union outright
     * (`void|PromiseInterface` is a compile-time fatal). The honest
     * strict form is therefore two-sided - every implementation
     * declares the tightest native type it actually honours (`: void`
     * or `: PromiseInterface`), and the dispatch loop rejects any
     * other value a middleware hands back.
     *
     * @param \Closure(Context, Session): void $next Continuation down
     *     the chain (the dispatcher's own hop is declared `: void`,
     *     so invoking it yields nothing).
     * @return void|\React\Promise\PromiseInterface
     */
    public function handle(Context $ctx, Session $session, callable $next);
}
