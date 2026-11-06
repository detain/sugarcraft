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

    /** Whether daemon is running */
    private bool $running = false;

    /** Shutdown flag */
    private bool $shutdownRequested = false;

    /** Client connections */
    private array $clients = [];

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
            AccessControl::getInstance()->setAllowAnonymousRead(true);
        }
    }

    /** Inject a stats collector (default: the shared {@see Stats} instance). */
    public function setStats(Stats $stats): void
    {
        $this->stats = $stats;
    }

    private function stats(): Stats
    {
        return $this->stats ?? Stats::getInstance();
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

        // Create server socket
        $this->serverSocket = $this->createServerSocket();
        if ($this->serverSocket === false) {
            \fwrite(\STDERR, "Failed to create server socket on {$this->config->gitListenAddr}\n");
            return 1;
        }

        // Write PID file
        if ($this->pidFile !== '') {
            $this->writePidFile();
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
     * callback, so a long transfer still delays the other clients for
     * its duration (disclosed).
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

        [$host, $port] = $this->listenHostPort();
        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($stream === false) {
            $this->loop = null;
            throw new \RuntimeException(
                "Failed to create server socket on {$this->config->gitListenAddr}: {$errstr}"
            );
        }
        \stream_set_blocking($stream, false);

        $this->serverStream = $stream;
        $this->running = true;
        $this->asyncActive = true;

        if ($this->pidFile !== '') {
            $this->writePidFile();
        }

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

            if (\in_array($this->serverSocket, $read, true)) {
                $this->acceptConnection();
            }

            foreach ($this->clients as $idx => $client) {
                if (\in_array($client['socket'], $read, true)) {
                    // The client may close itself mid-dispatch (every error
                    // path); only feed sockets still registered.
                    if (isset($this->clients[$idx])) {
                        $this->handleClientData($idx);
                    }
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

        $this->clients[] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];
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

        $this->clients[] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];

        // The callback captures the stream (not the array index) because
        // closeClient() splices the clients list and reindexes it.
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
        $idx = $this->clientIndex($stream);
        if ($idx === null) {
            // Client already closed; drop the stale registration.
            $this->loop->removeReadStream($stream);

            return;
        }

        $data = @\fread($stream, 65536);
        if ($data === false || ($data === '' && \feof($stream))) {
            $this->closeClient($idx);

            return;
        }
        if ($data === '') {
            return;
        }

        $client = &$this->clients[$idx];
        $client['buffer'] .= $data;
        $client['last_activity'] = \time();
        unset($client);

        if (!$this->clients[$idx]['handled']) {
            // Same pre-auth caps as the blocking transport.
            if (\strlen($this->clients[$idx]['buffer']) >= self::MAX_REQUEST_BYTES
                || \time() - $this->clients[$idx]['connected_at'] > self::PREAUTH_DEADLINE_SECONDS
            ) {
                $this->closeClient($idx);

                return;
            }
            $this->dispatchClientRequest($idx);
        }
    }

    /**
     * Async housekeeping tick: turn a signal-set shutdown flag into a
     * teardown and sweep idle connections (the async analogue of the
     * sync loop's checkConnectionTimeouts()).
     */
    private function onAsyncTick(): void
    {
        if ($this->shutdownRequested) {
            $this->stopAsync();

            return;
        }

        $idleTimeout = $this->config->gitIdleTimeout;
        if ($idleTimeout <= 0) {
            return;
        }

        $now = \time();
        for ($idx = \count($this->clients) - 1; $idx >= 0; $idx--) {
            if (($now - $this->clients[$idx]['last_activity']) > $idleTimeout) {
                $this->closeClient($idx);
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

        // Newest-first so array_splice() reindexing cannot skip entries.
        for ($idx = \count($this->clients) - 1; $idx >= 0; $idx--) {
            $this->closeClient($idx);
        }

        if ($this->serverStream !== null) {
            $this->loop->removeReadStream($this->serverStream);
            @\fclose($this->serverStream);
            $this->serverStream = null;
        }

        // Graceful shutdown of background tasks — same Subscriptions
        // mechanism the sync cleanup() uses.
        $this->subscriptions->unsubscribe();

        $this->removePidFile();

        \fwrite(\STDERR, "Git daemon stopped\n");

        $deferred = $this->asyncDeferred;
        $this->asyncDeferred = null;
        $deferred?->resolve(0);
    }

    /**
     * Find the current index of a client by its stream/socket identity.
     *
     * @param resource|\Socket $socket
     */
    private function clientIndex($socket): ?int
    {
        foreach ($this->clients as $idx => $client) {
            if ($client['socket'] === $socket) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Check for idle timeouts and close stale connections.
     */
    private function checkConnectionTimeouts(): void
    {
        $now = \time();
        $idleTimeout = $this->config->gitIdleTimeout;

        // Newest-first index sweep: handleClientData() can closeClient()
        // and splice the list mid-sweep, which the previous by-reference
        // foreach iterated over a shifted array (same discipline the
        // async tick uses).
        for ($idx = \count($this->clients) - 1; $idx >= 0; $idx--) {
            if (!isset($this->clients[$idx])) {
                continue;
            }
            $client = $this->clients[$idx];
            if ($idleTimeout > 0 && ($now - $client['last_activity']) > $idleTimeout) {
                $this->closeClient($idx);
                continue;
            }

            // Handle buffered data
            if (!$client['handled'] && $client['buffer'] !== '') {
                $this->handleClientData($idx);
            }
        }
    }

    /**
     * Handle data from a client.
     */
    private function handleClientData(int $idx): void
    {
        $client = &$this->clients[$idx];

        // Pre-auth guardrails: an unhandled client gets a bounded request
        // line AND a bounded lifetime. Without these, anonymous sockets
        // could append unbounded bytes (memory) or trickle bytes slowly
        // while the idle clock reset on every read.
        if (!$client['handled']) {
            if (\strlen($client['buffer']) >= self::MAX_REQUEST_BYTES) {
                unset($client);
                $this->closeClient($idx);

                return;
            }
            if (\time() - $client['connected_at'] > self::PREAUTH_DEADLINE_SECONDS) {
                unset($client);
                $this->closeClient($idx);

                return;
            }
        }

        // Read from socket
        $data = @\socket_read($client['socket'], 65536);
        if ($data === false || $data === '') {
            $this->closeClient($idx);
            return;
        }

        $client['buffer'] .= $data;
        $client['last_activity'] = \time();
        unset($client);

        $this->dispatchClientRequest($idx);
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
    private function dispatchClientRequest(int $idx): void
    {
        $client = &$this->clients[$idx];

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
        $request = self::parseRequest($client['buffer']);
        if ($request !== null
            && \preg_match('/^(git-upload-pack|git-receive-pack)\s+(\/\S*)/', $request[0], $matches)
        ) {
            $gitCmd = $matches[1];
            $repoPath = $matches[2];
            $repoName = \ltrim(\pathinfo($repoPath, \PATHINFO_BASENAME) ?: \basename($repoPath), '/');

            // Containment: basename() of '/..' is '..', which the old charset
            // gate accepted — that addressed (and auto-init could CREATE)
            // repositories outside the repos jail over a socket with no
            // authentication whatsoever.
            if (!Repo::isJailSafeName($repoName)) {
                $this->writePacket($client['socket'], "err Invalid repository name\n");
                $this->closeClient($idx);
                return;
            }

            // The git daemon protocol carries no authentication, so serving
            // receive-pack here means UNAUTHENTICATED writes the moment a
            // repo is public with push allowed. The daemon is read-only by
            // construction; pushes go over an authenticated transport (HTTP
            // Basic / SSH). Refuse before any repo-existence oracle.
            if ($gitCmd === 'git-receive-pack') {
                $this->writePacket($client['socket'], "err git-receive-pack is not served over the anonymous daemon; push over authenticated HTTP or SSH\n");
                $this->closeClient($idx);
                return;
            }

            // Find repo
            $repo = $this->repos[$repoName] ?? $this->findRepoByPath($repoPath);

            if ($repo === null) {
                $this->writePacket($client['socket'], "err Repository not found: {$repoName}\n");
                $this->closeClient($idx);
                return;
            }

            $ac = AccessControl::getInstance();
            // The daemon's only identity is the config lever: with
            // git.allow_anonymous_read off, this anonymous transport
            // refuses EVERY request before serving a byte —
            // fail-closed: the same lever the HTTP surface consults,
            // armed once at construction).
            if (!$this->anonymousRead) {
                $this->writePacket($client['socket'], "err Access denied\n");
                $this->closeClient($idx);

                return;
            }

            // Only git-upload-pack can reach this point (receive-pack was
            // refused above), and the daemon's only identity is anonymous.
            if (!$ac->canRead(null, $repo)) {
                $this->writePacket($client['socket'], "err Access denied\n");
                $this->closeClient($idx);
                return;
            }

            $this->stats()->recordPackDownload();
            $this->handleUploadPack($client['socket'], $repo, \substr($client['buffer'], $request[1]));

            $client['handled'] = true;
            $this->closeClient($idx);
        }
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

    /**
     * Close a client connection (either transport). Async clients also
     * get their read stream deregistered from the loop.
     */
    private function closeClient(int $idx): void
    {
        if (!isset($this->clients[$idx])) {
            return;
        }

        $socket = $this->clients[$idx]['socket'];
        if ($socket instanceof \Socket) {
            @\socket_close($socket);
        } else {
            $this->loop?->removeReadStream($socket);
            @\fclose($socket);
        }
        \array_splice($this->clients, $idx, 1);
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
        $host = $parts[0] ?: '0.0.0.0';
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

        // gitDirFor() resolves the layout the same way the marker writer
        // does — a true bare directory (HEAD at root) keeps its marker at
        // its own root, so a hardcoded '/.git' probe would miss the
        // publication an operator persisted via registerRepo.
        $gitDir = Repo::gitDirFor($fullPath);
        if (\is_dir($gitDir)) {
            // Marker-honest fallback: the on-disk visibility marker decides
            // publication — a directory that merely exists is fail-closed
            // PRIVATE for the daemon transport, never public-by-presence.
            return Repo::new($name, $fullPath)
                ->withVisibility(Repo::markerVisibility($gitDir));
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Signal handling
    // -------------------------------------------------------------------------

    private function registerSignalHandlers(): void
    {
        if (\function_exists('pcntl_signal') && \function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGTERM, fn() => $this->handleSignal());
            \pcntl_signal(\SIGINT, fn() => $this->handleSignal());
            \pcntl_signal(\SIGHUP, fn() => $this->handleSignal());
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

    private function writePidFile(): void
    {
        $dir = \dirname($this->pidFile);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }
        \file_put_contents($this->pidFile, (string) \getmypid());
    }

    private function removePidFile(): void
    {
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
            @\socket_close($client['socket']);
        }
        $this->clients = [];

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
