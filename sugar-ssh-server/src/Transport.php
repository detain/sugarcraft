<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

/**
 * Pluggable strategy that decides HOW the middleware stack runs
 * for a connected session.
 *
 * Two implementations ship with sugar-ssh-server:
 *
 *   - {@see Transport\InProcessTransport} (default) — allocates a
 *     pseudo-terminal via `sugar-pty`, spawns the user's cmd as a
 *     subprocess with `controllingTerminal: true`, and pumps bytes
 *     between the supervisor's STDIN/STDOUT and the PTY master.
 *     Lets middleware mount arbitrary shells, editors, and signal-
 *     aware TUIs that need a real ctty.
 *
 *   - {@see Transport\HostSshdTransport} (legacy, opt-in) — runs
 *     the middleware chain directly in the supervisor process, with
 *     STDIN/STDOUT being the slave side of sshd's PTY. Mirrors
 *     sugar-ssh-server's pre-PTY-upgrade architecture so existing
 *     ForceCommand deployments keep working untouched.
 *
 * Both transports still depend on host sshd as the SSH wire-protocol
 * front-end. A full PHP SSH server is a separate effort tracked
 * deliberately out of scope here (it would own a transport layer, not a
 * middleware seam).
 *
 * Both transports share one middleware walk, so the status rules above
 * hold identically on either; what differs is only what happens after the
 * chain reaches its terminal.
 */
interface Transport
{
    /**
     * Run the middleware `$stack` against `$session`. Implementations
     * choose whether to walk the stack inline (HostSshd) or set up
     * extra plumbing (InProcess) before delegating to terminal
     * middleware that drive the actual user-visible behaviour.
     *
     * The `$ctx` bounds the middleware phases this call walks — between
     * hops, at await entry, and at the pump and challenge-loop boundaries
     * a transport threads it into (audit sugar-ssh-server-017). It is not
     * a leash on the user's child process: once a ForceCommand child is
     * running under sshd, the kernel hangs it up at session end, not a PHP
     * timer.
     *
     * @param list<Middleware> $stack Registered middleware in
     *        registration order; the transport is responsible for
     *        invoking them (`->handle($ctx, $session, $next)` style).
     *
     * @return int The session's exit status — the child's exit code
     *         under a spawner transport (Spawn / DefaultChannelHandler
     *         exec/shell), {@see \SugarCraft\SshServer\Server::EXIT_DENIED}
     *         when a middleware refused the session or the context died
     *         first, and `0` when the chain completed with nothing to
     *         run. The server script is expected to surface it via
     *         `exit()` so `ssh host cmd; echo $?` reports the real
     *         result. Failures of the machinery itself raise
     *         (PtyException etc.) rather than encoding a status.
     */
    public function run(Context $ctx, Session $session, array $stack): int;
}
