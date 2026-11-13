<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\Subscription;
use SugarCraft\Async\Subscriptions;
use SugarCraft\GitServer\{AccessControl, Config, Repo, Stats, User};

/**
 * Real daemon-mode Git daemon: binds to a port and answers the
 * git-upload-pack advertisement over the anonymous git:// transport.
 * Each request delegates to a one-shot REAL `git upload-pack
 * --advertise-refs` child, so the advertisement is byte-exact git
 * output; completing the negotiation past the advertisement is a
 * disclosed follow-up (a stateless-rpc relay per connection), and
 * clients that send wants are closed after the flush — visibly,
 * never as a corrupted half-transfer. The daemon is READ-ONLY by
 * construction — the git protocol carries no authentication, so
 * receive-pack (push) is refused outright and lives on the
 * authenticated transports.
 *
 * Dual-mode: {@see serve()} runs the original blocking
 * `socket_select()` loop (the default); {@see serveAsync()} is the
 * opt-in ReactPHP path that accepts and reads connections through the
 * event loop so a host application can multiplex the daemon with
 * timers, other sockets, and loop work. Both modes share the same protocol
 * code — only the transport/readiness layer differs.
 *
 * CHILD-PROCESS LIFETIME. Every protocol request is delegated
 * to the real `git upload-pack --advertise-refs` binary through the
 * bounded stdio pump {@see StdioPipe::run()}: the pump carries its own reap ladder
 * (terminate, bounded poll, kill, bounded poll, close) on every exit
 * path, so no git child can outlive the request that spawned it.
 * Because both transports dispatch a request synchronously on the
 * daemon thread, stop paths (shutdown()/cleanup()/stopAsync()) only
 * ever run BETWEEN handlers, which is why the daemon needs no standing
 * child registry. Enforced by tests/ChildProcessLifetimeCensusTest
 * (zero-survivor census around real and unwinding requests).
 *
 * Port of charmbracelet/soft-serve GitDaemon.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class GitDaemon
{
    private Config $config;

    /** @var array<string, Repo> repo name => Repo */
    private array $repos = [];

    /** @var array<string, User> username => User */
    private array $users = [];

    /** Bound server socket */
    private $serverSocket = null;

    /** PID file path */
    private string $pidFile = '';

    /** @var resource|null Live flock handle on the pidfile (090 lock role). */
    private $pidLockHandle = null;

    /** Whether daemon is running */
    private bool $running = false;

    /** Shutdown flag */
    private bool $shutdownRequested = false;

    /**
     * Client connections, keyed by transport-stable id
     * ({@see clientId()}) — the HttpListener already maps seats by
     * resource id; a spliced list made every callback pay a linear
     * `clientIndex()` scan and every close a reindex.
     *
     * @var array<string, array{socket: \Socket|resource, connected_at: int, last_activity: int, buffer: string, handled: bool}>
     */
    private array $clients = [];

    /**
     * Disk-fallback misses, name => unix second of the miss. Re-probing
     * is_dir + marker for a name that was not there a moment ago is the
     * cheapest path for a flood, so a miss is remembered briefly; the
     * TTL keeps `repo create` between requests reachable.
     */
    private array $diskMisses = [];

    /** Short negative-cache window for {@see findRepoByPath()} misses. */
    private const DISK_MISS_TTL_SECONDS = 5;

    /**
     * Signal dispositions captured at install time, restored on stop
     * (process-global handlers and the async-signals flag must not
     * outlive the daemon in an embedded host).
     *
     * @var array<int, mixed>|null
     */
    private ?array $priorSignalHandlers = null;

    private ?bool $priorAsyncSignals = null;

    /** Subscriptions for graceful shutdown */
    private Subscriptions $subscriptions;

    /** Event loop driving the opt-in async mode (null in sync mode). */
    private ?LoopInterface $loop = null;

    /**
     * Listening stream for async mode. Async uses `stream_socket_server`
     * (not ext-sockets) because React loops multiplex stream resources.
     *
     * @var resource|null
     */
    private $serverStream = null;

    /** Housekeeping timer (idle timeouts + signal-flag polling) in async mode. */
    private ?TimerInterface $asyncTimer = null;

    /** Resolves the serveAsync() promise when the daemon stops. */
    private ?Deferred $asyncDeferred = null;

    /** Whether the async transport is currently registered with a loop. */
    private bool $asyncActive = false;

    /** Snapshot of git.allow_anonymous_read taken at construction. */
    private bool $anonymousRead = false;


    /** Upper bound for the pre-auth request line, in bytes. */
    public const MAX_REQUEST_BYTES = 65536;

    /** Absolute pre-auth lifetime: bytes alone never extend this. */
    public const PREAUTH_DEADLINE_SECONDS = 10;

    /** Signals the daemon borrows from the process (captured + restored). */
    private const SIGNALS = [\SIGTERM, \SIGINT, \SIGHUP];

    /** Stats collector override (null = shared instance). */
    private ?Stats $stats = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->subscriptions = new Subscriptions();

        // The daemon's read posture is decided ONCE at construction from
        // git.allow_anonymous_read — serve entry has no runtime re-read
        // seam by design: flipping the lever on a live daemon must never
        // silently unmask the anonymous transport.
        $this->anonymousRead = $config->allowAnonymousRead;

        // AccessControl is a process-global singleton shared with the
        // HTTP surface; the daemon RAISES it when its config says
        // anonymous reads are allowed and NEVER lowers a decision another
        // component already honoured. canRead() consults the singleton,
        // so without this arm a freshly exec'd daemon process refused
        // its own published repos (the anonymous-read lever simply not reaching
        // the transport that parsed it).
        if ($config->allowAnonymousRead) {
            AccessControl::instance()->setAllowAnonymousRead(true);
        }
    }

    /** Inject a stats collector (default: the shared {@see Stats} instance). */
    public function setStats(Stats $stats): void
    {
        $this->stats = $stats;
    }

    private function stats(): Stats
    {
        return $this->stats ?? Stats::instance();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function registerRepo(Repo $repo): void
    {
        // Registration is state the operator means to survive the process:
        // a PUBLIC registration persists its visibility marker (idempotent
        // write), so the repo stays published for the next boot's scan
        // instead of silently reverting to fail-closed private (
        // the same visibility marker the CLI's `repo create --public` writes).
        // Private/collaborator-only registrations must NOT write a marker
        // (fail-closed reads need no on-disk declaration). The exists()
        // gate keeps in-process registrations at placeholder paths
        // (fixtures, not-yet-created repos) from throwing on an
        // unwritable directory.
        if ($repo->exists() && $repo->isVisiblePublic()) {
            $repo->writeVisibilityMarker();
        }

        $this->repos[$repo->name] = $repo;
    }

    public function registerRepos(iterable $repos): void
    {
        foreach ($repos as $repo) {
            $this->registerRepo($repo);
        }
    }

    public function registerUser(User $user): void
    {
        $this->users[$user->username] = $user;
    }

    /** @param iterable<User> $users */
    public function setUsers(iterable $users): void
    {
        $this->users = [];
        foreach ($users as $user) {
            $this->users[$user->username] = $user;
        }
    }

    /**
     * Register a subscription for cleanup on graceful shutdown.
     *
     * @param Subscription $subscription
     */
    public function addSubscription(Subscription $subscription): void
    {
        $this->subscriptions->add($subscription);
    }

    /**
     * Unregister all subscriptions (used after shutdown).
     */
    public function clearSubscriptions(): void
    {
        $this->subscriptions->unsubscribe();
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start the daemon in blocking mode.
     *
     * @param string $pidFile  Optional PID file path for process tracking
     * @return int  Exit code (0 = graceful, 1 = error)
     */
    public function serve(string $pidFile = ''): int
    {
        $this->pidFile = $pidFile;
        $this->running = true;
        // A prior shutdown() latched the flag on this instance; the loop
        // would exit at once and a restarted daemon would silently refuse
        // to serve (serveAsync already reset it; serve() had not).
        $this->shutdownRequested = false;

        // Register signal handlers
        $this->registerSignalHandlers();

        // Write (and claim) the PID file BEFORE binding: a refusal must
        // not leave a listening socket behind on the fail path.
        if ($this->pidFile !== '') {
            try {
                $this->writePidFile();
            } catch (\RuntimeException $e) {
                $this->running = false;
                $this->restoreSignalHandlers();
                \fwrite(\STDERR, $e->getMessage() . "\n");

                return 1;
            }
        }

        // Create server socket
        $this->serverSocket = $this->createServerSocket();
        if ($this->serverSocket === false) {
            $this->running = false;
            $this->restoreSignalHandlers();
            \fwrite(\STDERR, "Failed to create server socket on {$this->config->gitListenAddr}\n");
            $this->removePidFile();

            return 1;
        }

        \fwrite(\STDERR, "Git daemon listening on {$this->config->gitListenAddr}\n");

        // Main event loop
        $this->mainLoop();

        // Cleanup
        $this->cleanup();
        return 0;
    }

    /**
     * Start the daemon in async (event-loop) mode — the opt-in
     * counterpart to the blocking {@see serve()}. Connections are
     * accepted via `Loop::addReadStream()` on the listening socket and
     * each client stream is registered with the loop, so request
     * handling never blocks the loop waiting for readability. The
     * per-request protocol work is the same synchronous delegation the
     * blocking mode runs — a `git upload-pack` child driven through the
     * bounded {@see StdioPipe} pump — executing inside the readiness
     * callback, so a long transfer still delays the other clients on
     * THIS transport for its duration. That is the git:// daemon's own
     * serial contract, disclosed and standing (it is the shape git's
     * reference daemon shipped too); the smart-HTTP front door took a
     * different path with the owner-8b worker pool — each HTTP
     * connection is served by its own forked worker
     * ({@see \SugarCraft\GitServer\HttpSmartProtocol\HttpListener} and
     * {@see \SugarCraft\GitServer\Support\WorkerFork}) — and the git://
     * and SSH transports remain on the shared-loop design described
     * here.
     *
     * Naming/shape mirrors sugar-pty's ReactPump: lazily resolves the
     * global loop, returns a promise that settles on stop, and funnels
     * every exit path through one teardown so no read stream or timer
     * can leak into the loop.
     *
     * @param LoopInterface|null $loop    Event loop (defaults to the global loop)
     * @param string             $pidFile Optional PID file path for process tracking
     * @return PromiseInterface<int> resolves with exit code 0 on graceful stop;
     *                               cancelling the promise behaves like shutdown().
     * @throws \RuntimeException if already running or the socket cannot be bound
     */
    public function serveAsync(?LoopInterface $loop = null, string $pidFile = ''): PromiseInterface
    {
        if ($this->running) {
            throw new \RuntimeException('GitDaemon is already running; call shutdown() first.');
        }

        $this->loop = $loop ?? Loop::get();
        $this->pidFile = $pidFile;
        $this->shutdownRequested = false;

        // Claim the PID file BEFORE binding (fail-fast parity with
        // serve(); a throw here must not leak a loop or a socket).
        if ($this->pidFile !== '') {
            try {
                $this->writePidFile();
            } catch (\RuntimeException $e) {
                $this->loop = null;

                throw $e;
            }
        }

        [$host, $port] = $this->listenHostPort();
        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($stream === false) {
            $this->loop = null;
            $this->removePidFile(); // release the claim taken before the bind
            throw new \RuntimeException(
                "Failed to create server socket on {$this->config->gitListenAddr}: {$errstr}"
            );
        }
        \stream_set_blocking($stream, false);

        $this->serverStream = $stream;
        $this->running = true;
        $this->asyncActive = true;

        $this->registerSignalHandlers();

        $this->loop->addReadStream($stream, fn () => $this->acceptAsyncConnection());

        // Housekeeping at the sync loop's socket_select timeout cadence:
        // idle-timeout sweep + shutdown-flag poll (signal handlers only
        // set the flag; the tick turns it into a teardown).
        $this->asyncTimer = $this->loop->addPeriodicTimer(1.0, fn () => $this->onAsyncTick());

        \fwrite(\STDERR, "Git daemon (async) listening on {$this->listenAddress()}\n");

        $this->asyncDeferred = new Deferred(function (): void {
            // Promise cancellation == graceful stop, same as shutdown().
            $this->stopAsync();
        });

        return $this->asyncDeferred->promise();
    }

    /**
     * Request graceful shutdown. In sync mode the main loop observes the
     * flag on its next select timeout; in async mode teardown is
     * scheduled on the loop immediately.
     */
    public function shutdown(): void
    {
        $this->shutdownRequested = true;

        if ($this->asyncActive && $this->loop !== null) {
            $this->loop->futureTick(fn () => $this->stopAsync());
        }
    }

    /**
     * Actual bound address in async mode (useful when the configured
     * port is 0 = ephemeral). Null when not listening asynchronously.
     */
    public function listenAddress(): ?string
    {
        if ($this->serverStream === null) {
            return null;
        }
        $name = @\stream_socket_get_name($this->serverStream, false);

        return $name === false ? null : $name;
    }

    // -------------------------------------------------------------------------
    // Main loop
    // -------------------------------------------------------------------------

    private function mainLoop(): void
    {
        while ($this->running && !$this->shutdownRequested) {
            $read = [$this->serverSocket];
            // THE loop that accepts must also READ its clients: the old
            // version selected on the listener only, so client bytes sat
            // in the kernel buffer forever — checkConnectionTimeouts()
            // then found every buffer empty and never issued the first
            // read (accept-only, no fetch protocol). Idle sweeps
            // still run each second via the select timeout.
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }
            $write = null;
            $except = null;

            $ready = @\socket_select($read, $write, $except, 1);
            if ($ready === false) {
                if ($this->shutdownRequested) break;
                continue;
            }

            // socket_select() returns $read trimmed to the ready handles,
            // so one pass over it serves accept AND clients — the old
            // shape in_array()'d every client against the ready list per
            // tick (O(n²) per iteration, same bookkeeping law as 102).
            foreach ($read as $handle) {
                if ($handle === $this->serverSocket) {
                    $this->acceptConnection();

                    continue;
                }
                $id = self::clientId($handle);
                // The client may close itself mid-dispatch (every error
                // path); only feed seats still registered.
                if (isset($this->clients[$id]) && $this->clients[$id]['socket'] === $handle) {
                    $this->handleClientData($id);
                }
            }

            // Check timeouts on existing connections
            $this->checkConnectionTimeouts();
        }
    }

    /**
     * Accept a new connection.
     */
    private function acceptConnection(): void
    {
        $client = @\socket_accept($this->serverSocket);
        if ($client === false) {
            return;
        }

        // Set socket to non-blocking for timeout handling
        \socket_set_nonblock($client);
        // Seat opens clean: an unrelated prior error must never be
        // mistaken for this socket's read verdict in handleClientData.
        \socket_clear_error($client);

        // Capacity refuses the NEWCOMER (git-daemon's own behavior); the
        // old code killed the OLDEST established connection instead, so
        // any number of probing sockets could serially evict every real
        // transfer in flight (evicting the oldest was a DoS magnet).
        if (\count($this->clients) >= $this->config->gitMaxConnections) {
            \socket_clear_error($client);
            \socket_close($client);

            return;
        }

        // Recorded only once the socket is SEATED: a newcomer refused at
        // capacity was never served, and counting it lets an
        // over-capacity probe inflate the handled-connections figure.
        $this->stats()->recordConnection();

        $this->clients[self::clientId($client)] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];
    }

    /**
     * Transport-stable seat id: sockets are keyed by object identity,
     * stream resources by their int id (the same scheme HttpListener's
     * seat map uses). Every read path re-checks the stored socket BE the
     * handle the callback holds, so a recycled id cannot act for a
     * stranger's seat (finding 143 law).
     *
     * @param \Socket|resource $handle
     */
    private static function clientId($handle): string
    {
        return $handle instanceof \Socket
            ? 'sock-' . \spl_object_id($handle)
            : 'res-' . (int) $handle;
    }

    // -------------------------------------------------------------------------
    // Async (event-loop) mode
    // -------------------------------------------------------------------------

    /**
     * Accept a new connection in async mode and register its stream
     * with the event loop for non-blocking reads.
     */
    private function acceptAsyncConnection(): void
    {
        $client = @\stream_socket_accept($this->serverStream, 0);
        if ($client === false) {
            return;
        }
        \stream_set_blocking($client, false);

        // Capacity refuses the NEWCOMER, never an established client
        // (same capacity policy as the blocking transport). The check must
        // run BEFORE both the stats record and the list append: a row
        // recorded for the socket it then closed let every over-capacity
        // probe inflate the handled-connections figure.
        if (\count($this->clients) >= $this->config->gitMaxConnections) {
            @\fclose($client);

            return;
        }

        $this->stats()->recordConnection();

        $this->clients[self::clientId($client)] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];

        // The callback captures the stream; the seat guard below re-checks
        // both the id AND the stored handle so a recycled resource id can
        // never act for a stranger's connection.
        $this->loop->addReadStream($client, fn () => $this->onAsyncClientReadable($client));
    }

    /**
     * Read available bytes from an async client and dispatch once a
     * complete request line has been buffered.
     *
     * @param resource $stream
     */
    private function onAsyncClientReadable($stream): void
    {
        $id = self::clientId($stream);
        if (!isset($this->clients[$id]) || $this->clients[$id]['socket'] !== $stream) {
            // Seat gone (or the id was recycled by a stranger); drop this
            // stale registration and let the real owner keep serving.
            $this->loop?->removeReadStream($stream);

            return;
        }

        $data = @\fread($stream, 65536);
        if ($data === false || ($data === '' && \feof($stream))) {
            $this->closeClient($id);

            return;
        }
        if ($data === '') {
            return;
        }

        $this->clients[$id]['buffer'] .= $data;
        $this->clients[$id]['last_activity'] = \time();

        if (!$this->clients[$id]['handled']) {
            // Same pre-auth caps as the blocking transport.
            if (\strlen($this->clients[$id]['buffer']) >= self::MAX_REQUEST_BYTES
                || \time() - $this->clients[$id]['connected_at'] > self::PREAUTH_DEADLINE_SECONDS
            ) {
                $this->closeClient($id);

                return;
            }
            $this->dispatchClientRequest($id);
        }
    }

    /**
     * Async housekeeping tick: turn a signal-set shutdown flag into a
     * teardown and sweep idle connections (the sync analogue of the
     * loop's checkConnectionTimeouts()).
     */
    private function onAsyncTick(): void
    {
        if ($this->shutdownRequested) {
            $this->stopAsync();

            return;
        }

        $idleTimeout = $this->config->gitIdleTimeout;
        $now = \time();
        foreach (\array_keys($this->clients) as $id) {
            $client = $this->clients[$id];
            $idleExpired = $idleTimeout > 0 && ($now - $client['last_activity']) > $idleTimeout;
            // The absolute pre-auth deadline is policed HERE (and in the
            // sync sweep), independent of git.idle_timeout: with the idle
            // knob off, a silent zero-byte seat used to be unexpirable —
            // anonymous slot lockout was one idle connection away (029).
            $preauthExpired = !$client['handled']
                && ($now - $client['connected_at']) > self::PREAUTH_DEADLINE_SECONDS;
            if ($idleExpired || $preauthExpired) {
                $this->closeClient($id);
            }
        }
    }

    /**
     * Tear down the async transport: deregister every loop resource
     * (server read stream, per-client read streams, housekeeping timer),
     * unsubscribe graceful-shutdown subscriptions, and resolve the
     * serveAsync() promise. Idempotent — all async exit paths funnel
     * here so a stopped daemon can never leak a stream or timer.
     */
    private function stopAsync(): void
    {
        if (!$this->asyncActive) {
            return;
        }
        $this->asyncActive = false;
        $this->running = false;

        if ($this->asyncTimer !== null) {
            $this->loop->cancelTimer($this->asyncTimer);
            $this->asyncTimer = null;
        }

        // Close every seat; the map loses entries by unset, so iteration
        // order carries no reindex hazard.
        foreach (\array_keys($this->clients) as $id) {
            $this->closeClient($id);
        }

        if ($this->serverStream !== null) {
            $this->loop->removeReadStream($this->serverStream);
            @\fclose($this->serverStream);
            $this->serverStream = null;
        }

        // Graceful shutdown of background tasks — same Subscriptions
        // mechanism the sync cleanup() uses.
        $this->subscriptions->unsubscribe();

        // Give the host back the signal dispositions we borrowed.
        $this->restoreSignalHandlers();

        $this->removePidFile();

        \fwrite(\STDERR, "Git daemon stopped\n");

        $deferred = $this->asyncDeferred;
        $this->asyncDeferred = null;
        $deferred?->resolve(0);
    }

    /**
     * Close a client connection (either transport). Async clients also
     * get their read stream deregistered from the loop.
     */
    private function closeClient(string $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $socket = $this->clients[$id]['socket'];
        unset($this->clients[$id]);
        if ($socket instanceof \Socket) {
            @\socket_close($socket);
        } else {
            $this->loop?->removeReadStream($socket);
            @\fclose($socket);
        }
    }

    /**
     * Check for idle timeouts, pre-auth expiries, and stale buffered
     * data on existing connections.
     */
    private function checkConnectionTimeouts(): void
    {
        $now = \time();
        $idleTimeout = $this->config->gitIdleTimeout;

        // Key-snapshot sweep: handleClientData() can closeClient() mid
        // sweep (an unset cannot invalidate the remaining ids, only the
        // seats themselves are touched).
        foreach (\array_keys($this->clients) as $id) {
            if (!isset($this->clients[$id])) {
                continue;
            }
            $client = $this->clients[$id];
            $idleExpired = $idleTimeout > 0 && ($now - $client['last_activity']) > $idleTimeout;
            // 029: with git.idle_timeout <= 0 the sweep used to police
            // nothing, and a zero-byte seat never reaches the read path
            // where the pre-auth deadline lives — so the deadline is
            // enforced here unconditionally for unhandled connections.
            $preauthExpired = !$client['handled']
                && ($now - $client['connected_at']) > self::PREAUTH_DEADLINE_SECONDS;
            if ($idleExpired || $preauthExpired) {
                $this->closeClient($id);
                continue;
            }

            // Handle buffered data
            if (!$client['handled'] && $client['buffer'] !== '') {
                $this->handleClientData($id);
            }
        }
    }

    /**
     * Handle data from a client.
     */
    private function handleClientData(string $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = &$this->clients[$id];

        // Pre-auth guardrails: an unhandled client gets a bounded request
        // line AND a bounded lifetime. Without these, anonymous sockets
        // could append unbounded bytes (memory) or trickle bytes slowly
        // while the idle clock reset on every read.
        if (!$client['handled']) {
            if (\strlen($client['buffer']) >= self::MAX_REQUEST_BYTES) {
                unset($client);
                $this->closeClient($id);

                return;
            }
            if (\time() - $client['connected_at'] > self::PREAUTH_DEADLINE_SECONDS) {
                unset($client);
                $this->closeClient($id);

                return;
            }
        }

        // Read from socket. A non-blocking read that finds nothing is
        // EWOULDBLOCK, not a hangup: the old shape closed the client on
        // every empty read, so a request straddling two reads was dropped
        // on the next tick (034). EOF reads as '' with no error.
        $data = @\socket_read($client['socket'], 65536);
        if ($data === false || $data === '') {
            $errno = \socket_last_error($client['socket']);
            \socket_clear_error($client['socket']);
            if ($errno === \SOCKET_EWOULDBLOCK) {
                unset($client);

                return; // live peer, nothing arrived yet
            }
            $this->closeClient($id);

            return;
        }

        $client['buffer'] .= $data;
        $client['last_activity'] = \time();
        unset($client);

        $this->dispatchClientRequest($id);
    }

    /**
     * Pull the daemon request line off the leading bytes: either a
     * pkt-line-framed payload ("NNNNcmd /path\0host=…\0", length counts
     * the 4 prefix chars, payload is NUL-terminated segments) or the bare
     * "cmd /path\n" line. Returns the request line plus the number of
     * buffer bytes it consumed — the remainder is the client's already
     * coalesced follow-up (want lines) and must reach the upload-pack
     * child untouched, not be re-parsed by this daemon. Null while the
     * request line is still arriving.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseRequest(string $buffer): ?array
    {
        if (\strlen($buffer) >= 4 && \preg_match('/^[0-9a-fA-F]{4}/', $buffer)) {
            $length = \hexdec(\substr($buffer, 0, 4));
            if ($length >= 4 && $length <= self::MAX_REQUEST_BYTES) {
                if (\strlen($buffer) < $length) {
                    return null; // still arriving
                }
                $payload = \substr($buffer, 4, $length - 4);
                $first = \explode("\0", $payload)[0];
                if ($first !== '') {
                    return [$first, $length];
                }
            }
        }

        $nl = \strpos($buffer, "\n");
        if ($nl === false) {
            return null;
        }
        $line = \rtrim(\substr($buffer, 0, $nl), "\r");
        // A NUL inside a supposedly bare line is a wire desync (pkt
        // framing with a swallowed or malformed length prefix). The bare
        // branch is a fixture convenience for exactly one well-formed
        // shape; anything NUL-tainted dispatches through the pkt branch
        // (or not at all) instead of silently consuming a client's
        // remaining stream.
        if (\str_contains($line, "\0")) {
            return null;
        }

        return [$line, $nl + 1];
    }

    /**
     * Dispatch a buffered client request once the request line is
     * complete. Shared by the sync (socket_select) and async (event
     * loop) transports — this is the single copy of the protocol
     * front-end.
     */
    private function dispatchClientRequest(string $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $socket = $this->clients[$id]['socket'];
        $buffer = $this->clients[$id]['buffer'];

        // Check if we have a complete request. Git daemon protocol: the
        // first line is "git-upload-pack /repo\0host=...\0" PKT-LINE framed
        // (a 4-hex length prefix and NUL-separated args) — real `git clone
        // git://…` NEVER emits the bare-line form the old regex required,
        // so no genuine client ever matched this dispatch. Accept
        // the pkt-framed wire and tolerate the bare line for fixtures.
        // parseRequest also reports how many bytes the request line
        // consumed: whatever the client coalesced behind it (want
        // packets) is passed through to the pump verbatim — the daemon
        // never pretends to parse the rest of the stream. The
        // one-shot advertisement child does not read them; a later
        // stateless-rpc relay is the disclosed upgrade path.
        $request = self::parseRequest($buffer);
        if ($request === null) {
            return;
        }
        if (!\preg_match('/^(git-upload-pack|git-receive-pack)\s+(\/\S*)/', $request[0], $matches)) {
            return;
        }
        $gitCmd = $matches[1];
        $repoPath = $matches[2];
        $repoName = \ltrim(\pathinfo($repoPath, \PATHINFO_BASENAME) ?: \basename($repoPath), '/');

        // Containment: basename() of '/..' is '..', which the old charset
        // gate accepted — that addressed (and auto-init could CREATE)
        // repositories outside the repos jail over a socket with no
        // authentication whatsoever.
        if (!Repo::isJailSafeName($repoName)) {
            $this->writePacket($socket, "err Invalid repository name\n");
            $this->closeClient($id);

            return;
        }

        // The git daemon protocol carries no authentication, so serving
        // receive-pack here means UNAUTHENTICATED writes the moment a
        // repo is public with push allowed. The daemon is read-only by
        // construction; pushes go over an authenticated transport (HTTP
        // Basic / SSH). Refuse before any repo-existence oracle.
        if ($gitCmd === 'git-receive-pack') {
            $this->writePacket($socket, "err git-receive-pack is not served over the anonymous daemon; push over authenticated HTTP or SSH\n");
            $this->closeClient($id);

            return;
        }

        // 098: the lever decides BEFORE any repo-existence oracle. The
        // old order answered "Repository not found: <name>" to anonymous
        // probers while git.allow_anonymous_read was OFF — a free repo
        // census (and the daemon named the HTTP instance as an accepted
        // disclosure shape). With the lever off every request now gets
        // the same uniform "Access denied", existent or not; wording is
        // uniform with the HTTP surface. README admission sentence rides
        // S45 as a rider note.
        if (!$this->anonymousRead) {
            $this->writePacket($socket, "err Access denied\n");
            $this->closeClient($id);

            return;
        }

        // Find repo (registered instances first, then the memoized disk
        // fallback below).
        $repo = $this->repos[$repoName] ?? $this->findRepoByPath($repoPath);

        if ($repo === null) {
            $this->writePacket($socket, "err Repository not found: {$repoName}\n");
            $this->closeClient($id);

            return;
        }

        $ac = AccessControl::instance();
        // Only git-upload-pack can reach this point (receive-pack was
        // refused above), and the daemon's only identity is anonymous
        // with the lever on: the per-repo posture still gates private
        // jails, fail-closed.
        if (!$ac->canRead(null, $repo)) {
            $this->writePacket($socket, "err Access denied\n");
            $this->closeClient($id);

            return;
        }

        $this->stats()->recordPackDownload();
        $this->handleUploadPack($socket, $repo, \substr($buffer, $request[1]));

        $this->clients[$id]['handled'] = true;
        $this->closeClient($id);
    }

    /**
     * Serve one upload request by delegating its advertisement,
     * byte-for-byte, to a one-shot REAL `git upload-pack
     * --advertise-refs` child through the bounded stdio pump.
     *
     * Delegation over emulation: framing and capability grammar belong
     * to git itself, so they cannot drift out of spec here (the
     * hand-rolled advertisement, bare-line want reader, shell-string
     * pack-objects and the entire dead-by-dispatch receive scaffold are
     * gone). The daemon keeps everything it was actually for:
     * containment, the read-only posture, access control, and stats.
     * $seed carries the request bytes the client coalesced behind the
     * request line; the one-shot child ignores them and the client is
     * closed after the flush (see class docblock for the honest scope).
     *
     * @param resource|\Socket $socket Client socket (both directions)
     */
    private function handleUploadPack($socket, Repo $repo, string $seed): void
    {
        // `git -C <repo> upload-pack --advertise-refs <repo>`: unlike the
        // smart-HTTP stateless form, the plain service does not resolve the
        // special "."-means-cwd rule, so the repository path is passed
        // explicitly (a bare repo's own directory is the git dir — pointing
        // at "." from inside it made git look for <dir>/.git and fail with
        // rc 128 'not a git repository').
        $repoPath = $repo->path();
        $rc = StdioPipe::run(
            $socket,
            $socket,
            ['git', '-C', $repoPath, 'upload-pack', '--advertise-refs', $repoPath],
            $repoPath,
            $seed
        );
        if ($rc !== 0) {
            // The pump logged the child's failure to STDERR; the client
            // gets a visible protocol error instead of a bare hang-up,
            // so a broken repository never masquerades as an empty one.
            $this->writePacket($socket, 'err upload-pack failed');
        }
    }

    /**
     * Write a pkt-line to socket through the shared codec.
     *
     * @param \Socket|resource $socket
     */
    private function writePacket($socket, string $data): void
    {
        $this->writeRaw($socket, PktLine::encode($data));
    }

    /**
     * Transport-agnostic raw write: sync clients are ext-sockets
     * `\Socket` objects, async clients are stream resources.
     *
     * @param \Socket|resource $socket
     */
    private function writeRaw($socket, string $bytes): void
    {
        if ($socket instanceof \Socket) {
            // Non-blocking by construction (accept sets it): one
            // socket_write hands the kernel AT MOST one send window and
            // the rest of the packet silently vanishes on close — real
            // clones died on truncated advertisements. Drain with
            // POLLOUT and a wall-clock stall bound.
            $stream = @\socket_export_stream($socket);
            if ($stream === false) {
                @\socket_write($socket, $bytes);

                return;
            }
            \stream_set_blocking($stream, false);
            self::drainWrite($stream, $bytes);

            return;
        }

        \stream_set_blocking($socket, false);
        self::drainWrite($socket, $bytes);
    }

    /**
     * Write ALL bytes to a non-blocking stream, waiting for buffer space
     * (100ms ticks, bounded) — the same discipline HttpListener::writeAll
     * applies on the HTTP side.
     *
     * @param resource $stream
     */
    private static function drainWrite($stream, string $bytes): void
    {
        $deadline = \hrtime(true) + 10_000_000_000; // 10s absolute
        $offset = 0;
        $length = \strlen($bytes);
        while ($offset < $length) {
            $chunk = @\fwrite($stream, \substr($bytes, $offset, 65536));
            if ($chunk === false) {
                return; // broken pipe: the reader is gone
            }
            $offset += $chunk;
            if ($offset >= $length) {
                return;
            }
            while (\hrtime(true) < $deadline) {
                $wf = [$stream];
                $rf = $ef = null;
                $ready = @\stream_select($rf, $wf, $ef, 0, 100_000);
                if ($ready === false || $ready === 0) {
                    continue;
                }
                if ($wf !== []) {
                    break;
                }
            }
            if (\hrtime(true) >= $deadline) {
                return; // stalled reader; drop the rest
            }
        }
    }

    // -------------------------------------------------------------------------
    // Server socket setup
    // -------------------------------------------------------------------------

    /**
     * Create and bind the server socket.
     *
     * @return resource|false
     */
    private function createServerSocket()
    {
        $socket = @\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        if ($socket === false) {
            return false;
        }

        // Allow port reuse
        \socket_set_option($socket, \SOL_SOCKET, \SO_REUSEADDR, 1);

        [$host, $port] = $this->listenHostPort();

        if (!@\socket_bind($socket, $host, $port)) {
            \socket_close($socket);
            return false;
        }

        if (!@\socket_listen($socket, $this->config->gitMaxConnections)) {
            \socket_close($socket);
            return false;
        }

        // Set timeout for blocking accept
        \socket_set_option($socket, \SOL_SOCKET, \SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

        return $socket;
    }

    /**
     * Parse `git.listen_addr` (":9418" / "127.0.0.1:9418") into host + port.
     *
     * @return array{string, int}
     */
    private function listenHostPort(): array
    {
        $parts = \explode(':', $this->config->gitListenAddr);
        // Security floor (037): an omitted host binds LOOPBACK, mirroring
        // the stats and HTTP transports (StatsServer::parseListenAddr) —
        // the old ':9418' default silently bound the wildcard address
        // while the docs claimed the loopback law. An operator wanting
        // the world writes 0.0.0.0 out loud.
        $host = $parts[0] !== '' ? $parts[0] : '127.0.0.1';
        $port = isset($parts[1]) ? (int) $parts[1] : 9418;

        return [$host, $port];
    }

    /**
     * Find a repo by filesystem path: registered instances first, then a
     * disk fallback that carries the on-disk visibility marker — a
     * directory without a public declaration resolves fail-closed PRIVATE,
     * never public-by-presence.
     */
    private function findRepoByPath(string $path): ?Repo
    {
        // Try exact match in registered repos
        foreach ($this->repos as $repo) {
            if ($repo->path() === $path) {
                return $repo;
            }
        }

        // Try to find by name in repos dir
        $name = \basename($path);
        $fullPath = $this->config->reposPath() . '/' . $name;
        $now = \time();

        // gitDirFor() resolves the layout the same way the marker writer
        // does — a true bare directory (HEAD at root) keeps its marker at
        // its own root, so a hardcoded '/.git' probe would miss the
        // publication an operator persisted via registerRepo.
        //
        // 103: the same lookup SSHServer memoizes — on the transport that
        // is cheapest to flood, an unmatched path used to re-probe
        // is_dir + marker + new Repo on EVERY request. Positives memo
        // into the repo map (visibility stays marker-honest at lookup);
        // misses are held briefly by DISK_MISS_TTL_SECONDS so a repo
        // created between requests still arrives within a tick or two.
        if (isset($this->diskMisses[$name])) {
            if ($now - $this->diskMisses[$name] <= self::DISK_MISS_TTL_SECONDS) {
                return null;
            }
            unset($this->diskMisses[$name]);
        }

        $gitDir = Repo::gitDirFor($fullPath);
        if (\is_dir($gitDir)) {
            // Marker-honest fallback: the on-disk visibility marker decides
            // publication — a directory that merely exists is fail-closed
            // PRIVATE for the daemon transport, never public-by-presence.
            $repo = Repo::new($name, $fullPath)
                ->withVisibility(Repo::markerVisibility($gitDir));
            $this->repos[$name] = $repo;

            return $repo;
        }

        $this->diskMisses[$name] = $now;

        return null;
    }

    // -------------------------------------------------------------------------
    // Signal handling
    // -------------------------------------------------------------------------

    private function registerSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }
        if ($this->priorSignalHandlers !== null) {
            return; // already installed on this instance; capture once
        }

        // 110: these handlers are PROCESS-GLOBAL and the async-signals
        // flag is a process switch — an embedded host that starts and
        // stops daemons loses default signal behavior permanently unless
        // the dispositions are captured first and restored on stop.
        $this->priorAsyncSignals = \pcntl_async_signals(true);
        foreach (self::SIGNALS as $signal) {
            $this->priorSignalHandlers[$signal] = \pcntl_signal_get_handler($signal);
            \pcntl_signal($signal, fn () => $this->handleSignal());
        }
    }

    /**
     * Hand the process back the signal dispositions the daemon borrowed
     * at start. Idempotent: a daemon that never registered has nothing
     * to restore.
     */
    private function restoreSignalHandlers(): void
    {
        if ($this->priorSignalHandlers === null) {
            return;
        }
        $handlers = $this->priorSignalHandlers;
        $async = $this->priorAsyncSignals;
        $this->priorSignalHandlers = null;
        $this->priorAsyncSignals = null;

        if (!\function_exists('pcntl_signal')) {
            return;
        }
        foreach ($handlers as $signal => $handler) {
            // get_handler reports null when a userland handler was set
            // without a recoverable callable (post-fork shape); the only
            // honest restoration for that is the C default.
            \pcntl_signal($signal, $handler ?? \SIG_DFL);
        }
        if ($async !== null && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals($async);
        }
    }

    private function handleSignal(): void
    {
        \fwrite(\STDERR, "\nReceived signal, shutting down...\n");
        $this->running = false;
        $this->shutdownRequested = true;
    }

    // -------------------------------------------------------------------------
    // PID file
    // -------------------------------------------------------------------------

    /**
     * Write — and TAKE — the pidfile (090). The file keeps a live
     * flock(LOCK_EX) for the whole daemon lifetime, which turns it into
     * a truth-bearing single-instance marker: a second daemon cannot
     * claim it, and a crash releases the lock with the process, so a
     * leftover file is always STALE and the next start overwrites it.
     * The old shape ignored mkdir/file_put_contents results (a daemon
     * "succeeded" with an empty or missing pidfile) and had neither
     * staleness check nor lock role.
     *
     * Deliberately NOT routed through Support\AtomicWriter (042): the
     * lock's authority lives on the target INODE — a rename would
     * publish fresh bytes but hand the next opener an unlocked inode,
     * trading the pidfile's whole 090 purpose for a neatness it never
     * needed. In-place truncate+write with checked results and fsync is
     * the correct shape for a lock-bearing file.
     *
     * @throws \RuntimeException When the directory cannot be created,
     *                           the file cannot be opened, a live
     *                           daemon holds the lock, or the bytes
     *                           cannot be committed.
     */
    private function writePidFile(): void
    {
        $dir = \dirname($this->pidFile);
        // 038 discipline for the plane we own: a pidfile directory must
        // not be world-writable-by-roll; content is public-safe (0644).
        if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
            throw new \RuntimeException("pidfile: cannot create directory {$dir}");
        }
        $handle = @\fopen($this->pidFile, 'c');
        if ($handle === false) {
            throw new \RuntimeException("pidfile: cannot open {$this->pidFile}");
        }
        if (@\flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            $holder = \trim((string) @\file_get_contents($this->pidFile));
            \fclose($handle);
            throw new \RuntimeException(
                "pidfile: {$this->pidFile} is locked by a running daemon"
                . ($holder !== '' ? " (pid {$holder})" : '')
            );
        }
        if (@\ftruncate($handle, 0) === false
            || @\fwrite($handle, (string) \getmypid()) === false
            || !@\fflush($handle)
            || !@\fsync($handle)
        ) {
            @\flock($handle, \LOCK_UN);
            \fclose($handle);
            throw new \RuntimeException("pidfile: cannot write {$this->pidFile}");
        }
        @\chmod($this->pidFile, 0644);
        $this->pidLockHandle = $handle; // the lock lives for the process lifetime
    }

    private function removePidFile(): void
    {
        // Release the lock before the unlink: a racing next-starter
        // must never flock a stale inode that still carries the old
        // daemon's claim.
        if ($this->pidLockHandle !== null) {
            @\flock($this->pidLockHandle, \LOCK_UN);
            @\fclose($this->pidLockHandle);
            $this->pidLockHandle = null;
        }
        if ($this->pidFile !== '' && \file_exists($this->pidFile)) {
            @\unlink($this->pidFile);
        }
    }

    // -------------------------------------------------------------------------
    // Cleanup
    // -------------------------------------------------------------------------

    private function cleanup(): void
    {
        // Unsubscribe all subscriptions (graceful shutdown of background tasks)
        $this->subscriptions->unsubscribe();

        // Close all client connections
        foreach ($this->clients as $client) {
            if ($client['socket'] instanceof \Socket) {
                @\socket_close($client['socket']);
            } else {
                @\fclose($client['socket']);
            }
        }
        $this->clients = [];

        // Hand the borrowed signal dispositions back (110).
        $this->restoreSignalHandlers();

        // Close server socket
        if ($this->serverSocket !== null) {
            @\socket_close($this->serverSocket);
        }

        // Remove PID file
        $this->removePidFile();

        \fwrite(\STDERR, "Git daemon stopped\n");
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    /** @return array<string, Repo> */
    public function repos(): array
    {
        return $this->repos;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function activeConnections(): int
    {
        return \count($this->clients);
    }

    public function subscriptions(): Subscriptions
    {
        return $this->subscriptions;
    }
}
