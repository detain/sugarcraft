<?php

declare(strict_types=1);

namespace SugarCraft\SshServer;

use SugarCraft\Core\I18n\T;
use SugarCraft\SshServer\Middleware\Keepalive;
use SugarCraft\SshServer\Transport\InProcessTransport;
use SugarCraft\SshServer\Transport\KeepaliveArming;

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
 *   4. `->withKeepalive($seconds)` arms an idle keepalive beat
 *      (optional; a value on the server, handed to the transport's
 *      arming seam at `serve()` entry — not a stack middleware).
 *   5. `->use($middleware)` appends middleware in registration order.
 *   6. `->serve()` builds a {@see Session} from the SSH env vars
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

    /** Keepalive value armed by {@see withKeepalive()}; applied at `serve()` entry. */
    private ?Keepalive $keepalive = null;

    public function __construct()
    {
        $this->transport = new InProcessTransport();
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * Append a middleware to the stack.
     *
     * Immutable (audit sugar-ssh-server-008, gate G1): the clone
     * carries the appended stack, the receiver stays exactly as it
     * was — one polarity (`use()`, {@see withMiddleware()}, the
     * `with*` builders) for every door on this class. Chaining reads
     * the same as before (`Server::new()->use($a)->use($b)->serve()`),
     * and branching is now safe: `$b = $a->use(X)` arms `$b` alone, so
     * a shared base server can be branched per route. The one call
     * shape that changes: `$server->use($m);` on its own (return
     * value discarded) now registers nothing — take the returned
     * server, as every example and doc here does.
     *
     * `use()` keeps the upstream Go library's option-list name;
     * {@see withMiddleware()} is the varargs sibling of the same door.
     */
    public function use(Middleware $m): self
    {
        $new = clone $this;
        $new->stack[] = $m;
        return $new;
    }

    /**
     * Append several middlewares at once, in order, clone-and-return
     * — the varargs spelling of {@see use()}, mirroring the Go
     * option-list form.
     */
    public function withMiddleware(Middleware ...$ms): self
    {
        $new = clone $this;
        foreach ($ms as $m) {
            $new->stack[] = $m;
        }
        return $new;
    }

    /**
     * Override the active transport. Defaults to
     * {@see InProcessTransport} when not called.
     *
     * Immutable: returns a new Server, leaving this one untouched
     * (house with*-law — since audit sugar-ssh-server-008 the whole
     * builder surface shares this polarity, `use()` included).
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
     * Arm an idle keepalive beat for sessions this server serves.
     *
     * Immutable and stack-free (audit sugar-ssh-server-079): the
     * interval is stored on the server as a {@see Keepalive} value,
     * not appended to the middleware stack. At `serve()` entry the
     * beat is handed to the transport through its
     * {@see \SugarCraft\SshServer\Transport\KeepaliveArming} seam —
     * the door that registers lives where the transport does.
     *
     * Under {@see \SugarCraft\SshServer\Transport\InProcessTransport}
     * the transport writes a `\0` byte through the PTY master whenever
     * the pump loop idles past the interval — the byte travels the
     * wire only where the tty echoes it (raw-mode TUIs suppress echo,
     * so those sessions stay silent). It is NOT an SSH_MSG_IGNORE
     * packet: the ForceCommand process never touches the SSH binary
     * protocol. Under HostSshdTransport — which does not implement the
     * seam — the arm is a silent no-op: configure sshd's
     * ClientAliveInterval/ServerAliveInterval instead.
     *
     * Pin: calling this on a server leaves the receiver untouched;
     * only the returned clone is armed. Branch a shared base with
     * `$s2 = $s->withKeepalive(30)` and `$s` still serves without a
     * beat.
     *
     * @param int $intervalSeconds Seconds of pump idle between keepalive bytes (default 60)
     */
    public function withKeepalive(int $intervalSeconds = 60): self
    {
        $new = clone $this;
        $new->keepalive = new Keepalive($intervalSeconds);
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
        if ($this->keepalive !== null && $this->transport instanceof KeepaliveArming) {
            $this->transport->armKeepalive($this->keepalive->interval());
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
