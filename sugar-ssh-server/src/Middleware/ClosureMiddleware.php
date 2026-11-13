<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;

/**
 * A middleware that IS a closure — the one-liner door on the stack.
 *
 * Audit 075: dressing a single session hook in the `Middleware`
 * interface's class tax (a file, a final class, a constructor) is what
 * pushed embedders to skip hooks entirely. {@see \SugarCraft\SshServer\Server::onSession()}
 * appends one of these; constructing it directly is equally fine.
 *
 * The closure receives `($ctx, $session, $next)` and its return value
 * passes through UNTOUCHED: return nothing to continue normally, call
 * `$next(...)` to walk the rest of the stack, return a
 * `\React\Promise\PromiseInterface` to be awaited at the shared settle
 * point. This is the only `handle()` in `src/` declared without a
 * native return type, and it must be: PHP rejects `void` inside a
 * return union, so no native type can name "whatever the closure
 * returned". Enforcement does not move here —
 * {@see \SugarCraft\SshServer\Transport\DispatchesMiddlewareStack}
 * remains the one place a stray (neither-null-nor-Promise) value dies
 * loudly, named; {@see \SugarCraft\SshServer\Tests\MiddlewareTypingContractTest}
 * pins this file as the sole exemption so a second untyped door cannot
 * creep in quietly.
 */
final class ClosureMiddleware implements Middleware
{
    /** @var \Closure(Context, Session, callable): mixed */
    private \Closure $fn;

    /**
     * @param callable(Context, Session, callable): mixed $fn
     */
    public function __construct(callable $fn)
    {
        $this->fn = \Closure::fromCallable($fn);
    }

    /**
     * MiddlewareTypingContract: pass-through exemption — see that guard's
     * const for why this one declaration carries no native return type.
     */
    public function handle(Context $ctx, Session $session, callable $next)
    {
        return ($this->fn)($ctx, $session, $next);
    }
}
