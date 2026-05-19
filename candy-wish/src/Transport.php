<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

/**
 * Pluggable strategy that decides HOW the middleware stack runs
 * for a connected session.
 *
 * Two implementations ship with candy-wish:
 *
 *   - {@see Transport\InProcessTransport} (default) — allocates a
 *     pseudo-terminal via `candy-pty`, spawns the user's cmd as a
 *     subprocess with `controllingTerminal: true`, and pumps bytes
 *     between the supervisor's STDIN/STDOUT and the PTY master.
 *     Lets middleware mount arbitrary shells, editors, and signal-
 *     aware TUIs that need a real ctty.
 *
 *   - {@see Transport\HostSshdTransport} (legacy, opt-in) — runs
 *     the middleware chain directly in the supervisor process, with
 *     STDIN/STDOUT being the slave side of sshd's PTY. Mirrors
 *     candy-wish's pre-PTY-upgrade architecture so existing
 *     ForceCommand deployments keep working untouched.
 *
 * Both transports still depend on host sshd as the SSH wire-protocol
 * front-end. A full PHP SSH server is a separate effort tracked
 * under `plans/x-xpty.md`'s deferred Option A.
 *
 * @see plans/plan-candy-wish-pty.md for the full design rationale.
 */
interface Transport
{
    /**
     * Run the middleware `$stack` against `$session`. Implementations
     * choose whether to walk the stack inline (HostSshd) or set up
     * extra plumbing (InProcess) before delegating to terminal
     * middleware that drive the actual user-visible behaviour.
     *
     * @param list<Middleware> $stack Registered middleware in
     *        registration order; the transport is responsible for
     *        invoking them (`->handle($ctx, $session, $next)` style).
     */
    public function run(Context $ctx, Session $session, array $stack): void;
}
