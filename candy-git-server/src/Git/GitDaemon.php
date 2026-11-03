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
 * Real daemon-mode Git daemon that binds to a port and handles
 * multiple concurrent connections using the git-upload-pack
 * and git-receive-pack protocols.
 *
 * Dual-mode: {@see serve()} runs the original blocking
 * `socket_select()` loop (the default); {@see serveAsync()} is the
 * opt-in ReactPHP path that accepts and reads connections through the
 * event loop so a host application can multiplex the daemon with
 * timers, other sockets, and loop work (plan item 6.1,
 * findings/plan_candy-git-server.md). Both modes share the same protocol
 * code — only the transport/readiness layer differs.
 *
 * CHILD-PROCESS LIFETIME (E721). Every git child this daemon starts is
 * REQUEST-scoped: `proc_open` in sendPack()/unpackObjects() drains its
 * output and terminates-if-undrained + `proc_close()` in a `finally`;
 * the `update-ref` writes run through `exec()`, which waits for the
 * child by definition. Nothing returns, stores, or escapes a handle, so
 * no git child can outlive the handler that spawned it — and because
 * both transports dispatch a request synchronously on the daemon
 * thread, stop paths (shutdown()/cleanup()/stopAsync()) only ever run
 * BETWEEN handlers, which is why the daemon needs no standing child
 * registry or reap ladder. Enforced by
 * tests/ChildProcessLifetimeCensusTest (zero-survivor census around
 * real and unwinding requests).
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

    /** Snapshot of git.allow_anonymous_read taken at construction (C-13). */
    private bool $anonymousRead = false;

    /** Upper bound for the pre-auth request line, in bytes. */
    public const MAX_REQUEST_BYTES = 65536;

    /** Absolute pre-auth lifetime: bytes alone never extend this (C-08). */
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
        $this->repos[$repo->name] = $repo;
    }

    public function registerRepos(iterable $repos): void
    {
        foreach ($repos as $repo) {
            $this->repos[$repo->name] = $repo;
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
        // to serve (M-14 — serveAsync already resets it, serve() did not).
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
     * per-request protocol work (ref advertisement, pack generation via
     * `git pack-objects`, `git update-ref`) is the same synchronous code
     * the blocking mode runs — it executes inside the readiness callback.
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
            // read (C-01: accept-only, no fetch protocol). Idle sweeps
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

        $this->stats()->recordConnection();

        // Capacity refuses the NEWCOMER (git-daemon's own behavior); the
        // old code killed the OLDEST established connection instead, so
        // any number of probing sockets could serially evict every real
        // transfer in flight (C-08: oldest-kick DoS).
        if (\count($this->clients) >= $this->config->gitMaxConnections) {
            \socket_clear_error($client);
            \socket_close($client);

            return;
        }

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

        $this->stats()->recordConnection();

        $this->clients[] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];

        // Capacity refuses the NEWCOMER, never an established client
        // (same C-08 policy as the blocking transport).
        if (\count($this->clients) >= $this->config->gitMaxConnections) {
            \fclose($client);

            return;
        }

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
            // Same pre-auth caps as the blocking transport (C-08).
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

        // Collect stale indices first to avoid array modification during iteration
        $staleIndices = [];
        foreach ($this->clients as $idx => &$client) {
            if ($idleTimeout > 0 && ($now - $client['last_activity']) > $idleTimeout) {
                $staleIndices[] = $idx;
                continue;
            }

            // Handle buffered data
            if (!$client['handled'] && $client['buffer'] !== '') {
                $this->handleClientData($idx);
            }
        }

        // Close stale connections in reverse order to preserve indices
        foreach (\array_reverse($staleIndices) as $idx) {
            $this->closeClient($idx);
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
        // while the idle clock reset on every read (C-08).
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
     * "cmd /path\n" line. Returns the payload with NULs normalized to
     * spaces so the command/path regex sees one clean line, or null while
     * the request is still incomplete.
     */
    public static function extractRequestLine(string $buffer): ?string
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
                    return $first;
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

        return $line;
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
        // so no genuine client ever matched this dispatch (M-08). Accept
        // the pkt-framed wire and tolerate the bare line for fixtures.
        $requestLine = self::extractRequestLine($client['buffer']);
        if ($requestLine !== null
            && \preg_match('/^(git-upload-pack|git-receive-pack)\s+(\/\S*)/', $requestLine, $matches)
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
            // refuses EVERY request before serving a byte (C-13,
            // fail-closed — the same lever the HTTP surface consults,
            // armed once at construction).
            if (!$this->anonymousRead) {
                $this->writePacket($client['socket'], "err Access denied\n");
                $this->closeClient($idx);

                return;
            }

            $user = $this->anonymousRead ? null : User::new('__daemon_anonymous_read_disabled__');

            // Check access
            if ($gitCmd === 'git-upload-pack') {
                if (!$ac->canRead($user, $repo)) {
                    $this->writePacket($client['socket'], "err Access denied\n");
                    $this->closeClient($idx);
                    return;
                }

                $this->stats()->recordPackDownload();
                $handler = new UploadPack($repo, $user);
            } else {
                if (!$ac->canWrite($user, $repo)) {
                    $this->writePacket($client['socket'], "err Access denied\n");
                    $this->closeClient($idx);
                    return;
                }

                // Auto-init repo if needed
                if (!$repo->exists()) {
                    $repo->init();
                }

                $this->stats()->recordPackUpload();
                $handler = new ReceivePack($repo, $user);
            }

            // Handle the request using stdio redirection
            $this->handleGitRequest($client['socket'], $handler, $idx);

            $client['handled'] = true;
            $this->closeClient($idx);
        }
    }

    /**
     * Handle a git protocol request.
     *
     * @param resource $socket  Client socket
     */
    private function handleGitRequest($socket, object $handler, int $clientIdx): void
    {
        $buffer = $this->clients[$clientIdx]['buffer'] ?? '';

        // Write client buffer to temp file for processing
        $tmpDir = $this->config->dataPath . '/tmp';
        if (!\is_dir($tmpDir)) {
            \mkdir($tmpDir, 0700, true);
        }

        $stdinFile = \tempnam($tmpDir, 'git-stdin-');
        if ($stdinFile === false) return;
        \chmod($stdinFile, 0600);
        \file_put_contents($stdinFile, $buffer);

        try {
            if ($handler instanceof UploadPack) {
                $this->handleUploadPack($socket, $handler, $stdinFile);
            } elseif ($handler instanceof ReceivePack) {
                $this->handleReceivePack($socket, $handler, $stdinFile);
            }
        } finally {
            @\unlink($stdinFile);
        }
    }

    /**
     * Handle git-upload-pack (clone/fetch) request.
     */
    private function handleUploadPack($socket, UploadPack $handler, string $stdinFile): void
    {
        $uploadPack = $handler;
        $ac = AccessControl::getInstance();

        // C-13 fail-closed: with git.allow_anonymous_read off, the daemon —
        // an anonymous transport by definition — refuses every request
        // before serving a byte. With the lever on, publication still
        // rides canRead's per-repo visibility exactly as before.
        if (!$this->anonymousRead) {
            $this->writePacket($socket, "err Access denied\n");
            return;
        }

        if (!$ac->canRead(null, $uploadPack->repo())) {
            $this->writePacket($socket, "err Access denied\n");
            return;
        }

        // Send refs advertisement (each ref as separate pkt-line)
        $this->sendRefAdvertisement($socket, $uploadPack->repo());

        // Read wants from client
        $wants = $this->readWantsFromFile($stdinFile);
        if ($wants === []) {
            return;
        }

        // Send pack data
        $this->sendPack($socket, $uploadPack->repo(), $wants);
    }

    /**
     * Build and send refs advertisement.
     */
    /** Upper bound on refs advertised to one anonymous daemon client. */
    public const MAX_ADVERTISED_REFS = 4096;

    private function sendRefAdvertisement($socket, Repo $repo): void
    {
        $branches = $repo->branches();
        $head = $branches !== [] ? $branches[0] : 'main';
        $headHash = $repo->refs("refs/heads/{$head}")["refs/heads/{$head}"] ?? '';

        // git upload-pack parses the capability list with a whitespace
        // split — every space-separated token after the first ref becomes
        // a candidate capability. A pipe-delimited list (the old spelling)
        // registered exactly ONE capability ("report-status|report-status-v2")
        // and poisoned the rest as nonsense caps. One spaced line, real
        // caps only, on the advertised first ref; the daemon serves the
        // read path, so no-oversize-advert (the client's job to respect)
        // and the upload-side caps are what actually apply.
        $caps = ' report-status side-band-64k ofs-delta shallow agent=git/candy-git-server';

        $first = true;
        $served = 0;
        foreach ($repo->refs() as $ref => $hash) {
            if ($served >= self::MAX_ADVERTISED_REFS) {
                break; // bounded advertisement (C-09 pre-auth amplification)
            }
            $line = "{$hash} {$ref}";
            if ($first) {
                $line .= $caps;
                $first = false;
            }
            $this->writePacket($socket, $line);
            $served++;
        }
        if ($first) {
            // No refs at all: advertise the symbolic head line with caps
            // so the client still parses a well-formed (empty) advert.
            $this->writePacket($socket, "{$headHash} refs/heads/{$head}{$caps}");
        }

        // Flush. \x30\x30\x30\x30 is the ASCII text "0000" — writing it
        // appended a 4-byte garbage line after every advertisement, which
        // clients fold into their negotiation stream (C-09).
        $this->writeRaw($socket, PktLine::FLUSH);
    }

    /**
     * Read want lines from stdin file.
     *
     * @return list<string>
     */
    private function readWantsFromFile(string $stdinFile): array
    {
        $wants = [];
        $lines = \file($stdinFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if (\str_starts_with($line, 'want ')) {
                $hash = \substr($line, 5);
                if (\strlen($hash) === 40 && \ctype_xdigit($hash)) {
                    $wants[] = $hash;
                }
            }
            if ($line === 'done') {
                break;
            }
        }

        return $wants;
    }

    /**
     * Send pack data to socket.
     *
     * @param resource $socket
     * @param list<string> $wants
     */
    private function sendPack($socket, Repo $repo, array $wants): void
    {
        if ($wants === []) return;

        $repoPath = \escapeshellarg($repo->path());
        $cmd = "git -C {$repoPath} pack-objects --stdout --revs 2>/dev/null";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var resource|false $proc */
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) return;

        // E721: the pack child is REQUEST-scoped. The finally makes that
        // structural instead of control-flow-coincidental: on every exit —
        // including an unwind from writeRaw's socket layer — the child that
        // did not finish streaming is terminated so proc_close cannot wait on
        // it forever, and every pipe and the handle are released. A git child
        // can then never outlive the request that spawned it, which is what
        // lets the daemon stop between handlers without a child registry.
        $childDrained = false;
        try {
            // Write want revs to stdin (one per line, no ^ prefix)
            foreach ($wants as $hash) {
                \fwrite($pipes[0], $hash . "\n");
            }
            \fclose($pipes[0]);

            // Stream pack data to socket
            while (!\feof($pipes[1])) {
                $chunk = \fread($pipes[1], 65536);
                if ($chunk === false) break;
                $this->writeRaw($socket, $chunk);
            }
            $childDrained = \feof($pipes[1]);
        } finally {
            if (!$childDrained) {
                @\proc_terminate($proc);
            }
            $this->closeIfResource($pipes[0]);
            $this->closeIfResource($pipes[1]);
            $this->closeIfResource($pipes[2]);
            \proc_close($proc);
        }
    }

    /**
     * Close a stream only while it is still open — the E721 reapers close
     * pipes in the finally that a normal drain already closed.
     *
     * @param resource|null $pipe
     */
    private function closeIfResource($pipe): void
    {
        if (\is_resource($pipe)) {
            \fclose($pipe);
        }
    }

    /**
     * Handle git-receive-pack (push) request.
     */
    private function handleReceivePack($socket, ReceivePack $handler, string $stdinFile): void
    {
        $receivePack = $handler;
        $repo = $receivePack->repo();

        // Send refs advertisement
        $refs = $repo->refs();
        $caps = 'report-status|report-status-v2 delete-refs side-band-64k';
        $firstRef = \key($refs) ?? 'refs/heads/main';
        $firstHash = \current($refs) ?: \str_repeat('0', 40);

        $this->writePacket($socket, "{$firstHash} {$firstRef}\x00 {$caps}");
        foreach ($refs as $ref => $hash) {
            if ($ref === $firstRef) continue;
            $this->writePacket($socket, "{$hash} {$ref}");
        }
        $this->writePacket($socket, '');

        // Read commands from client
        $commands = $this->readCommandsFromFile($stdinFile);
        if ($commands === []) {
            return;
        }

        // A push carries its objects in a trailing packfile. Those objects
        // MUST be written into the repo BEFORE any ref is moved to point at
        // them — otherwise a ref references objects the repo does not have
        // (corruption / data loss; this is the bug this path had). The HTTP
        // path avoids it by delegating the whole exchange to
        // `git receive-pack`, which index-packs then updates refs; here we
        // do the same in two explicit steps. Deletes carry no pack.
        $needsPack = false;
        foreach ($commands as $cmd) {
            if ($cmd['new'] !== \str_repeat('0', 40)) {
                $needsPack = true;
                break;
            }
        }

        if ($needsPack) {
            $packData = $this->extractPackData((string) \file_get_contents($stdinFile));
            if ($packData === '') {
                $this->writePacket($socket, 'unpack no packfile received');
                $this->writePacket($socket, '');
                return;
            }

            [$unpacked, $unpackErr] = $this->unpackObjects($repo->path(), $packData);
            if (!$unpacked) {
                $reason = \trim((string) \preg_replace('/[\x00-\x1f\s]+/', ' ', $unpackErr));
                $this->writePacket($socket, 'unpack ' . ($reason === '' ? 'index-pack failed' : $reason));
                $this->writePacket($socket, '');
                return;
            }
        }

        $this->writePacket($socket, 'unpack ok');

        // Process push
        $repoPath = \escapeshellarg($repo->path());
        foreach ($commands as $cmd) {
            $oldHash = $cmd['old'];
            $newHash = $cmd['new'];
            $ref = $cmd['ref'];
            // Grammar-gate the client-controlled refname before handing it to
            // git: the old charset regex accepted '..' components (file
            // traversal inside the refs store) and said nothing about a
            // leading '-' being an option TO git update-ref itself.
            if (!RefGrammar::isValidRefName($ref)) {
                // Grammar violation: report 'ng' for this ref and keep
                // processing the batch. The old branch set $out/$rc then
                // `continue`d — the failure was never reported, so the
                // client saw an all-ok flush for a ref that was not applied.
                $safeRef = \trim((string) \preg_replace('/[\x00-\x1f\s]+/', ' ', $ref));
                $this->writePacket($socket, "ng {$safeRef}: refusing invalid ref name");
                continue;
            }
            $escapedRef = \escapeshellarg($ref);

            $out = [];
            $rc = 0;

            if ($newHash === \str_repeat('0', 40)) {
                // Delete: git update-ref -d <ref> <old>
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref -d {$escapedRef} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            } elseif ($oldHash === \str_repeat('0', 40)) {
                // Create: pass the zeros old-value so git enforces
                // compare-and-swap ("ref must not exist") — without it a race
                // silently clobbers a ref another push just created.
                $escapedNew = \escapeshellarg($newHash);
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            } else {
                // Update: git update-ref <ref> <new> <old>
                $escapedNew = \escapeshellarg($newHash);
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            }

            if ($rc !== 0) {
                // Surface git's own diagnostic (mirrors ReceivePack::processPush)
                // so the client isn't left with only a generic decline. pkt-lines
                // are single-line, so collapse whitespace/control chars.
                $reason = \trim((string) \preg_replace('/[\x00-\x1f\s]+/', ' ', \implode(' ', $out)));
                $suffix = $reason === '' ? 'pre-receive hook declined' : "pre-receive hook declined: {$reason}";
                $this->writePacket($socket, "ng {$cmd['ref']}: {$suffix}");
                return;
            }

            $this->writePacket($socket, "ok {$cmd['ref']}");
        }

        $this->writePacket($socket, '');
    }

    /**
     * Read push commands from file.
     *
     * @return list<array{old: string, new: string, ref: string}>
     */
    private function readCommandsFromFile(string $stdinFile): array
    {
        $commands = [];
        $lines = \file($stdinFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if ($line === '') break;

            $parts = \preg_split('/\s+/', $line);
            if (\count($parts) < 3) continue;

            [$oldHash, $newHash, $ref] = $parts;

            // Keep all commands: create (old=0), update, and delete (new=0)
            if (\strlen($oldHash) === 40 && \strlen($newHash) === 40) {
                $commands[] = ['old' => $oldHash, 'new' => $newHash, 'ref' => $ref];
            }
        }

        return $commands;
    }

    /**
     * Locate the packfile inside a raw receive-pack request buffer.
     *
     * The pushed pack follows the ref-update command list; it begins with
     * the `PACK` signature and a 4-byte version (2 or 3). Matching the
     * signature+version (not a bare "PACK") avoids a false positive on a
     * ref literally named `.../PACK`.
     *
     * @return string The packfile bytes, or '' when no pack is present.
     */
    private function extractPackData(string $raw): string
    {
        foreach (["PACK\x00\x00\x00\x02", "PACK\x00\x00\x00\x03"] as $sig) {
            $pos = \strpos($raw, $sig);
            if ($pos !== false) {
                return \substr($raw, $pos);
            }
        }
        return '';
    }

    /**
     * Write the pushed objects into the repo via `git index-pack`, so they
     * exist before any ref update references them. `--fix-thin` completes
     * thin packs (deltas against objects already in the repo), matching what
     * `git receive-pack` does for real pushes.
     *
     * @return array{0: bool, 1: string} [success, error message]
     */
    private function unpackObjects(string $repoPath, string $packData): array
    {
        $escaped = \escapeshellarg($repoPath);
        $cmd = "git -C {$escaped} index-pack --stdin --fix-thin 2>&1";

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var resource|false $proc */
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) {
            return [false, 'failed to start index-pack'];
        }

        // E721: same request-scoped reap as sendPack() — the drain below reads
        // to EOF, so the child is done by the time the finally terminates
        // nothing; on any unwind (or a half-written pack) the child is stopped
        // first and proc_close() cannot wait on it. $rc keeps its meaning:
        // index-pack's real exit status, read in the finally before the
        // verdict below.
        $childDrained = false;
        $rc = -1;
        try {
            \fwrite($pipes[0], $packData);
            \fclose($pipes[0]);
            $out = (string) \stream_get_contents($pipes[1]);
            $err = (string) \stream_get_contents($pipes[2]);
            $childDrained = true;
        } finally {
            if (!$childDrained) {
                @\proc_terminate($proc);
            }
            $this->closeIfResource($pipes[0]);
            $this->closeIfResource($pipes[1]);
            $this->closeIfResource($pipes[2]);
            $rc = \proc_close($proc);
        }

        if ($rc !== 0) {
            $msg = \trim($err !== '' ? $err : $out);
            return [false, $msg === '' ? 'index-pack failed' : $msg];
        }
        return [true, ''];
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
            // clones died on truncated advertisements (M-09). Drain with
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
     * Find a repo by filesystem path.
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

        if (\is_dir($fullPath . '/.git')) {
            return Repo::new($name, $fullPath);
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
