<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

use SugarCraft\Core\I18n\T;
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
 *   3. `->withLocale($code)` arms a message language for the run
 *      (optional; nothing selects a locale implicitly, and the peer
 *      never does).
 *   4. `->use($middleware)` appends middleware in registration order.
 *   5. `->serve()` builds a {@see Session} from the SSH env vars
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
 * exit(Server::new()
 *     ->use(Logger::auto())
 *     ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
 *     ->serve());
 * ```
 *
 * The `exit(...)` wrapper is not decoration ({@see self::serve()} explains
 * the three answers): a script that calls `serve()` and falls off the end of
 * the file reports success to whichever cron entry or CI step asked, whether
 * the session was served, refused, or never ran at all.
 *
 * **Same example, host-sshd legacy:**
 *
 * ```php
 * exit(Server::new()
 *     ->withTransport(new HostSshdTransport())
 *     ->use(Logger::auto())
 *     ->use(new BubbleTea(fn () => new MyApp()))
 *     ->serve());
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
     * failed. Audit MAJOR 9: a refused login used to answer `0`, so
     * `ssh host backup.sh` looked like a successful backup to the cron
     * job and the CI step that ran it.
     */
    public const EXIT_DENIED = 129;

    /** @var list<Middleware> */
    private array $stack = [];

    private Transport $transport;

    /** Locale armed by {@see withLocale()}; null means "never touch the global". */
    private ?string $locale = null;

    public function __construct()
    {
        $this->transport = new InProcessTransport();
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Append a middleware to the stack, in place.
     *
     * This is the one mutating call on the class, and it is deliberate:
     * the upstream Go library this is a port of builds its option list by
     * mutation, and every README example chains `->use(...)` off a single
     * receiver. It does NOT clone, so the receiver and every alias of it
     * share one stack - `$b = $a->use(X)` gives `$a` an X too. Build one
     * server per process and add to it once, at boot; if you need two
     * different stacks, construct two servers rather than branching off a
     * shared one. {@see withTransport()} and {@see withKeepalive()} are the
     * immutable half of the API (audit MINOR 7).
     */
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
     * Arm a catalogue locale for the notices this server renders.
     *
     * Immutable: clone-and-return, the {@see withTransport()} polarity —
     * the arm APPENDS nothing to the middleware stack. `serve()` calls
     * `T::setLocale($code)` at its entry when, and only when, this was
     * called; an unarmed server never touches the global locale, so the
     * invisible-by-default defect (audit sugar-ssh-server-013) is fixed
     * by an opt-in door rather than an implicit pick.
     *
     * **Process-wide side effect.** `T::setLocale()` writes a global:
     * every `serve()` on an armed server re-stamps the whole process.
     * Embeddings hosting several differently-armed servers in one
     * process must keep their runs serialised — each entry overwrites
     * the previous arm.
     *
     * **The peer never selects.** `Session::$lang` (sshd's `LANG`, when
     * `AcceptEnv` lets it through at all) stays input to logs, not a
     * setting, and `T::detect()` stays out of this library for the same
     * reason — see the README Language section. An unknown `$code` is
     * harmless: catalogue lookup falls back to English.
     *
     * @param string $code Catalogue code under `lang/` ('de', 'fr', ...; see LOCALES.md)
     */
    public function withLocale(string $code): self
    {
        $new = clone $this;
        $new->locale = $code;
        return $new;
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
     * @param int $intervalSeconds Seconds of pump idle between keepalive bytes (default 60)
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
     * census — before continuing the chain. Since the single-settle restructure
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
     * **Locale.** When {@see withLocale()} armed one, the process-wide
     * catalogue locale is set here first; an unarmed server leaves it
     * exactly as the entry script found it.
     *
     * @param Context|null $ctx Root context for the run — pass a
     *                          cancellable context to make the M1
     *                          cancel-propagation machinery reachable
     *                          through the public API; null uses a
     *                          fresh {@see Context::background()}.
     */
    public function serve(?Session $session = null, ?Context $ctx = null): int
    {
        if ($this->locale !== null) {
            T::setLocale($this->locale);
        }
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
