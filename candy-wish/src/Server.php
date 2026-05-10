<?php

declare(strict_types=1);

namespace SugarCraft\Wish;

use SugarCraft\Wish\Middleware\Keepalive;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * SSH session entry point.
 *
 * **Architecture.** Both transports candy-wish ships still depend on
 * host sshd as the SSH wire-protocol front-end. The operator
 * configures `sshd` to invoke a CandyWish-aware PHP script via
 * `ForceCommand` (in `sshd_config`) or `command="..."` (in an
 * `authorized_keys` line). Each connection spawns a fresh PHP
 * process; this class is what that process instantiates.
 *
 * What's pluggable is the {@see Transport} — the strategy that
 * decides HOW the middleware stack runs against the session:
 *
 *   - {@see Transport\InProcessTransport} (default) — PTY supervisor:
 *     allocates a `candy-pty`, spawns the user's cmd as a subprocess
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
     */
    public function withTransport(Transport $t): self
    {
        $this->transport = $t;
        return $this;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * Add SSH keepalive middleware to detect dead connections.
     *
     * Sends periodic SSH_MSG_IGNORE packets at the specified interval
     * to keep connections alive through NAT gateways and firewalls.
     * Also helps detect if the remote client has disconnected.
     *
     * @param int $intervalSeconds Interval between keepalive messages (default 60)
     */
    public function withKeepalive(int $intervalSeconds = 60): self
    {
        $this->stack[] = new Keepalive($intervalSeconds);
        return $this;
    }

    /**
     * Build the {@see Session} from the current environment (or use
     * the injected one) and ask the active transport to run the
     * middleware chain. Returns when the chain finishes — for a
     * terminal subprocess (Spawn / BubbleTea) that's when the
     * inner process exits or the SSH client disconnects.
     */
    public function serve(?Session $session = null): void
    {
        $session ??= Session::fromEnvironment();
        $this->transport->run($session, $this->stack);
    }
}
