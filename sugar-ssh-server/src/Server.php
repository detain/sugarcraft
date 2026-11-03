<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

use SugarCraft\SshServer\Middleware\Keepalive;
use SugarCraft\SshServer\Transport\InProcessTransport;

/**
 * SSH session entry point.
 *
 * **Architecture.** Both transports sugar-ssh-server ships still depend on
 * host sshd as the SSH wire-protocol front-end. The operator
 * configures `sshd` to invoke a SugarSshServer-aware PHP script via
 * `ForceCommand` (in `sshd_config`) or `command="..."` (in an
 * `authorized_keys` line). Each connection spawns a fresh PHP
 * process; this class is what that process instantiates.
 *
 * What's pluggable is the {@see Transport} — the strategy that
 * decides HOW the middleware stack runs against the session:
 *
 *   - {@see Transport\InProcessTransport} (default) — PTY supervisor:
 *     allocates a `sugar-pty`, spawns the user's cmd as a subprocess
 *     with full controlling-terminal semantics, pumps bytes between
 *     supervisor stdio and the PTY master.
 *   - {@see Transport\HostSshdTransport} (legacy, opt-in) — runs the
 *     middleware chain inline against sshd's PTY directly. Mirrors
 *     pre-PTY-upgrade behaviour for existing deployments.
 *
 * **Lifecycle.**
 *
 *   1. `Server::new()` constructs an empty stack with the default
 *      InProcess transport.
 *   2. `->withTransport($t)` overrides the transport (optional).
 *   3. `->use($middleware)` appends middleware in registration order.
 *   4. `->serve()` builds a {@see Session} from the SSH env vars
 *      (or accepts an injected Session for tests), then asks the
 *      transport to run the stack.
 *
 * **Example sshd snippet** (`/etc/ssh/sshd_config.d/wish.conf`):
 *
 * ```
 * Match User wishuser
 *     ForceCommand /usr/bin/php /opt/wish/server.php
 *     AllowTcpForwarding no
 *     PermitTTY yes
 * ```
 *
 * **Example `server.php`** (in-process default):
 *
 * ```php
 * Server::new()
 *     ->use(new Logger())
 *     ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
 *     ->serve();
 * ```
 *
 * (Prefix the final call with `exit(...)` — see {@see self::serve()} —
 * so the SSH client receives the child's exit status.)
 *
 * **Same example, host-sshd legacy:**
 *
 * ```php
 * Server::new()
 *     ->withTransport(new HostSshdTransport())
 *     ->use(new Logger())
 *     ->use(new BubbleTea(fn () => new MyApp()))
 *     ->serve();
 * ```
 */
final class Server
{
    /**
     * Status a ForceCommand process returns when the session was
     * refused rather than served.
     *
     * 128 + 1, the shell's own convention for 'this died to a signal',
     * picked because it cannot collide with anything a served program
     * exits with (0-127 is the useful range, 128+ is the shell's) and
     * because `1` would be indistinguishable from a script that simply
     * failed. Audit MAJOR 9: an refused login used to answer `0`, so
     * `ssh host backup.sh` looked like a successful backup to the cron
     * job and the CI step that ran it.
     */
    public const EXIT_DENIED = 129;

    /** @var list<Middleware> */
    private array $stack = [];

    private Transport $transport;

    public function __construct()
    {
        $this->transport = new InProcessTransport();
    }

    public static function new(): self
    {
        return new self();
    }

    public function use(Middleware $m): self
    {
        $this->stack[] = $m;
        return $this;
    }

    /**
     * Override the active transport. Defaults to
     * {@see InProcessTransport} when not called.
     *
     * Immutable: returns a new Server, leaving this one untouched
     * (house with*-law; `use()` keeps upstream Go-wish mutation
     * semantics deliberately).
     */
    public function withTransport(Transport $t): self
    {
        $new = clone $this;
        $new->transport = $t;
        return $new;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * Add keepalive middleware to the stack.
     *
     * Under {@see \SugarCraft\SshServer\Transport\InProcessTransport} the
     * middleware writes a `\0` byte through the PTY master whenever the
     * pump loop idles past the interval — the byte travels the wire
     * only where the tty echoes it (raw-mode TUIs suppress echo, so
     * those sessions stay silent). It is NOT an SSH_MSG_IGNORE packet:
     * the ForceCommand process never touches the SSH binary protocol.
     * Under HostSshdTransport it is a deliberate no-op — configure
     * sshd's ClientAliveInterval/ServerAliveInterval instead.
     *
     * @param int $intervalSeconds Interval between keepalive writes (default 60)
     */
    public function withKeepalive(int $intervalSeconds = 60): self
    {
        $new = clone $this;
        $new->stack[] = new Keepalive($intervalSeconds);
        return $new;
    }

    /**
     * Build the {@see Session} from the current environment (or use
     * the injected one) and ask the active transport to run the
     * middleware chain. Returns when the chain finishes — for a
     * terminal subprocess (Spawn / BubbleTea) that's when the
     * inner process exits or the SSH client disconnects.
     *
     * **Note on async middleware.** Promise-returning middleware is
     * supported: if a middleware's `handle()` returns a
     * `\React\Promise\PromiseInterface`, the shared dispatcher
     * ({@see \SugarCraft\SshServer\Transport\DispatchesMiddlewareStack::dispatch()},
     * used by both transports) settles it through the single canonical
     * await point {@see \SugarCraft\SshServer\Transport\PromiseAwait::settle()}
     * — the timeout-wrapped loop drive pinned by the one-settle-call-site
     * census — before continuing the chain. Since the E730 restructure
     * there is no per-transport integration point and no `->wait()` on
     * the chain path.
     *
     * **Exit status.** Returns the session's exit status: the spawned
     * child's exit code when a child ran, {@see self::EXIT_DENIED}
     * (129) when a middleware refused the session or the context died
     * before one did, and `0` when the chain simply had nothing to run.
     * A ForceCommand server script must hand it to `exit()` so the SSH
     * client sees the real code (`ssh host cmd; echo $?`):
     *
     * ```php
     * exit($server->serve());
     * ```
     *
     * @param Context|null $ctx Root context for the run — pass a
     *                          cancellable context to make the M1
     *                          cancel-propagation machinery reachable
     *                          through the public API; null uses a
     *                          fresh {@see Context::background()}.
     */
    public function serve(?Session $session = null, ?Context $ctx = null): int
    {
        $session ??= Session::fromEnvironment();
        return $this->transport->run($ctx ?? Context::background(), $session, $this->stack);
    }

    /**
     * The one answer to 'what code does this session leave behind?'.
     *
     * Both transports read the stack's result through here, so a refusal
     * and a completion cannot drift apart by transport the way they did
     * before audit MAJOR 9. Precedence is deliberate: a child that
     * actually ran wins, because its number is the most specific truth
     * available.
     */
    public static function resolveStatus(Session $session): int
    {
        $status = $session->exitStatus();
        if ($status !== null) {
            return $status;
        }

        return $session->refused() ? self::EXIT_DENIED : 0;
    }
}
