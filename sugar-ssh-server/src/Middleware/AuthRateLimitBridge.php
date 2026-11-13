<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use React\Promise\PromiseInterface;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;

/**
 * Turns an auth-gate refusal into a token-bucket penalty.
 *
 * `RateLimit::penalize()` advertises itself as the auth-failure hook, but
 * the gates never call it: without wiring, a client can try unlimited
 * wrong keys while the bucket only pays for CONNECTS. This class closes
 * that gap by composition — it wraps a gate, and when the gate refuses
 * (never invokes `$next`), it charges the session's buckets an extra
 * `$penalty` tokens before the chain stays stopped:
 *
 * ```php
 * $server
 *     ->use(new RateLimit('/var/run/ssh-buckets.json', burst: 5))
 *     ->use(new AuthRateLimitBridge(
 *         gate: Auth::allowingUsers(['deploy']),
 *         rateLimit: $rateLimit,
 *     ));
 * ```
 *
 * **Detection is by non-continuation, not by message.** A gate refuses by
 * returning without calling `$next` — there is no protocol-level "auth
 * failed" event to subscribe to — so the bridge passes the chain through
 * and checks whether its own continuation ran. That makes it gate-agnostic
 * (`Auth`, `PasswordAuth`, `KeyboardInteractive`, or any `Middleware`
 * that keeps the same stop-the-chain contract) and behaviour-identical
 * for everything downstream: on admit, `$next` is called exactly once
 * with the same `Context` and `Session`; on refuse, the chain stops
 * exactly as it did unwrapped, plus the bucket charge.
 *
 * **Synchronous gates only.** An async gate ({@see AsyncMiddleware}
 * subclasses) hands back a `PromiseInterface` and decides LATER; a
 * not-yet-continued chain proves nothing at that moment, and charging
 * then would penalise in-flight auth attempts. This bridge therefore
 * throws a `LogicException` if the wrapped gate returns a promise —
 * wire `penalize()` into that gate's own reject path instead.
 *
 * The default `$penalty` is 3.0 tokens against the typical `burst: 5`:
 * two bad-credential tries plus one connect then empty a bucket, so
 * credential guessing costs roughly triple what port-scanning costs,
 * while one honest mistake still leaves room to act.
 */
final class AuthRateLimitBridge implements Middleware
{
    public function __construct(
        private readonly Middleware $gate,
        private readonly RateLimit $rateLimit,
        private readonly float $penalty = 3.0,
    ) {
        if ($penalty <= 0.0) {
            throw new \LogicException('AuthRateLimitBridge penalty must be > 0; a zero penalty would bridge nothing. Use the gate directly if no penalty is wanted.');
        }
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $admitted = false;
        $result = $this->gate->handle($ctx, $session, function (Context $inner, Session $innerSession) use (&$admitted, $next): void {
            $admitted = true;
            $next($inner, $innerSession);
        });
        if ($result instanceof PromiseInterface) {
            // Fail loud rather than penalise an undecided attempt.
            throw new \LogicException('AuthRateLimitBridge wraps synchronous gates only; call RateLimit::penalize() from the async gate\'s own reject path.');
        }
        if (!$admitted) {
            $this->rateLimit->penalize($session, $this->penalty);
        }
    }
}
