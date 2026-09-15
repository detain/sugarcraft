<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

/**
 * Shared middleware-stack walk owning the library's single promise settle point.
 *
 * E730 (round 83) restructure: before this, every AsyncMiddleware promise was
 * awaited TWICE — once synchronously inside AsyncMiddleware::handle() and again
 * by the transport dispatch hop that received it (findings #43/#48). The
 * middleware chain is now pure async composition: a middleware hands back its
 * promise untouched, and this walk drives exactly one await per hop via
 * {@see PromiseAwait::settle()} — the only call site in `src/`, pinned by
 * PromiseDispatchTest's source census.
 *
 * Cooperative cancellation has two consult points, both deadline-free (no
 * polling timers are armed — the loop is never probed for context state):
 * the per-hop `done()` guard below refuses to START a middleware on a dead
 * context, and the settle entry guard refuses to AWAIT a promise whose
 * context died while its middleware was running (rejections and cancellations
 * alike surface at the dispatch frame, keeping the sync-caller contract that
 * PromiseDispatchTest pins: transport `run()` throws).
 */
trait DispatchesMiddlewareStack
{
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
            PromiseAwait::settle($result, ctx: $ctx);
        }
    }
}
