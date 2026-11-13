<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

/**
 * Shared middleware-stack walk owning the library's single promise settle point.
 *
 * The single-settle restructure: before this, every AsyncMiddleware promise was
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
 *
 * The walk also ANSWERS: since audit MAJOR 9 it returns the status the
 * session ended with, read through {@see Server::resolveStatus()} — the
 * spawned child's code when a spawner ran one, `Server::EXIT_DENIED`
 * when a middleware refused the session or the context died, and `0`
 * when the stack simply had nothing to run. A middleware that stops the
 * chain is either the terminal (a spawner, which records its child's
 * code) or a refusal (which says so with
 * {@see Session::markRefused()}); the walk cannot tell those apart on
 * its own, so it never pretends to.
 */
trait DispatchesMiddlewareStack
{
    /**
     * @param list<Middleware> $stack
     */
    private function dispatch(Context $ctx, Session $session, array $stack, int $idx): int
    {
        if ($idx >= \count($stack)) {
            return Server::resolveStatus($session);
        }
        if ($ctx->done()) {
            // Nothing was served and nobody was asked: that is a refusal
            // as far as the caller reading `$?` is concerned.
            $session->markRefused();
            return Server::EXIT_DENIED;
        }
        $next = function (Context $c, Session $s) use ($stack, $idx): void {
            $this->dispatch($c, $s, $stack, $idx + 1);
        };
        $result = $stack[$idx]->handle($ctx, $session, $next);
        if ($result instanceof \React\Promise\PromiseInterface) {
            PromiseAwait::settle($result, ctx: $ctx);
        } elseif ($result !== null) {
            // Fail-loud at the one place the contract can be enforced:
            // the interface cannot express void|Promise natively (PHP
            // rejects void inside union returns), so a middleware that
            // is not natively typed and hands back a stray value - the
            // accidental `return $next(...)` class of slip - dies here
            // with a named culprit instead of being silently dropped.
            throw new \TypeError(\sprintf(
                '%s::handle() returned %s; a middleware must return nothing or a PromiseInterface.',
                \get_class($stack[$idx]),
                \get_debug_type($result),
            ));
        }

        return Server::resolveStatus($session);
    }
}
