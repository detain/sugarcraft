<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Middleware;

use React\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware as MiddlewareContract;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\PromiseAwait;

/**
 * Abstract base for middleware that needs to perform async I/O before
 * delegating to the next handler.
 *
 * Subclasses override {@see handleAsync()} to perform async work
 * (LDAP lookups, OAuth token exchange, database auth, etc.) and
 * return a Promise. When the Promise resolves the chain continues
 * to `$next`. If the Promise rejects, the rejection propagates up
 * and the chain short-circuits.
 *
 * Usage:
 *
 * ```php
 * final class LdapAuth extends AsyncMiddleware
 * {
 *     protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
 *     {
 *         return $this->ldap->verify($session->user)->then(
 *             fn () => $next($ctx, $session),
 *             fn (\Throwable $e) => throw new AuthFailedException($e->getMessage())
 *         );
 *     }
 * }
 * ```
 */
abstract class AsyncMiddleware implements MiddlewareContract
{
    public function handle(Context $ctx, Session $session, callable $next): PromiseInterface
    {
        $wrappedNext = function (Context $c, Session $s) use ($next): void {
            $next($c, $s);
        };

        $result = $this->handleAsync($ctx, $session, $wrappedNext);
        // Return the promise directly so the event loop is not blocked.
        // The caller is responsible for settling the promise.
        if ($result instanceof PromiseInterface) {
            return $result;
        }
        return \React\Promise\resolve(null);
    }

    /**
     * Perform async work and continue the middleware chain.
     *
     * @param callable(Context, Session): void $next Continue the chain
     * @return Promise\PromiseInterface Resolves when async work is done;
     *                                   the chain proceeds to `$next`.
     *                                   Rejects to short-circuit the chain.
     */
    abstract protected function handleAsync(Context $ctx, Session $session, callable $next): Promise\PromiseInterface;
}
