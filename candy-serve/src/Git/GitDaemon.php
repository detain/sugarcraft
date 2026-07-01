<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Git;

use SugarCraft\Async\Subscription;
use SugarCraft\Async\Subscriptions;
use SugarCraft\Serve\{AccessControl, Config, Repo, User};

/**
 * Real daemon-mode Git daemon that binds to a port and handles
 * multiple concurrent connections using the git-upload-pack
 * and git-receive-pack protocols.
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

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->subscriptions = new Subscriptions();
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
     * Request graceful shutdown.
     */
    public function shutdown(): void
    {
        $this->shutdownRequested = true;
    }

    // -------------------------------------------------------------------------
    // Main loop
    // -------------------------------------------------------------------------

    private function mainLoop(): void
    {
        while ($this->running && !$this->shutdownRequested) {
            $read = [$this->serverSocket];
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

        $this->clients[] = [
            'socket' => $client,
            'connected_at' => \time(),
            'last_activity' => \time(),
            'buffer' => '',
            'handled' => false,
        ];

        // Enforce max connections
        if (\count($this->clients) > $this->config->gitMaxConnections) {
            $oldest = $this->clients[0];
            \socket_close($oldest['socket']);
            \array_shift($this->clients);
        }
    }

    /**
     * Check for idle timeouts and close stale connections.
     */
    private function checkConnectionTimeouts(): void
    {
        $now = \time();
        $idleTimeout = $this->config->gitIdleTimeout;

        foreach ($this->clients as $idx => &$client) {
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

        // Read from socket
        $data = @\socket_read($client['socket'], 65536);
        if ($data === false || $data === '') {
            $this->closeClient($idx);
            return;
        }

        $client['buffer'] .= $data;
        $client['last_activity'] = \time();

        // Check if we have a complete request
        // Git daemon protocol: first line is "git-upload-pack /repo\n" or "git-receive-pack /repo\n"
        if (\preg_match('/^(git-upload-pack|git-receive-pack)\s+(\/[^\s\n]+)\s*\n/', $client['buffer'], $matches)) {
            $gitCmd = $matches[1];
            $repoPath = $matches[2];
            $repoName = \ltrim(\pathinfo($repoPath, \PATHINFO_BASENAME) ?: \basename($repoPath), '/');

            // Find repo
            $repo = $this->repos[$repoName] ?? $this->findRepoByPath($repoPath);

            if ($repo === null) {
                $this->writePacket($client['socket'], "err Repository not found: {$repoName}\n");
                $this->closeClient($idx);
                return;
            }

            $ac = AccessControl::getInstance();
            $user = null; // Anonymous for git protocol

            // Check access
            if ($gitCmd === 'git-upload-pack') {
                if (!$ac->canRead($user, $repo)) {
                    $this->writePacket($client['socket'], "err Access denied\n");
                    $this->closeClient($idx);
                    return;
                }

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

        if ($handler instanceof UploadPack) {
            $this->handleUploadPack($socket, $handler, $stdinFile);
        } elseif ($handler instanceof ReceivePack) {
            $this->handleReceivePack($socket, $handler, $stdinFile);
        }

        @\unlink($stdinFile);
    }

    /**
     * Handle git-upload-pack (clone/fetch) request.
     */
    private function handleUploadPack($socket, UploadPack $handler, string $stdinFile): void
    {
        $uploadPack = $handler;
        $ac = AccessControl::getInstance();

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
    private function sendRefAdvertisement($socket, Repo $repo): void
    {
        $branches = $repo->branches();
        $head = $branches !== [] ? $branches[0] : 'main';
        $headHash = $repo->refs("refs/heads/{$head}")["refs/heads/{$head}"] ?? '';

        // First ref (no capabilities for git-daemon protocol)
        $this->writePacket($socket, "{$headHash} refs/heads/{$head}");

        // Subsequent refs
        foreach ($repo->refs() as $ref => $hash) {
            if ($ref !== 'refs/heads/' . $head) {
                $this->writePacket($socket, "{$hash} {$ref}");
            }
        }

        // Flush
        $this->writePacket($socket, '');
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
        $proc = \proc_open($cmd, $desc, $pipes);
        if ($proc === false) return;

        // Write want revs to stdin (one per line, no ^ prefix)
        foreach ($wants as $hash) {
            \fwrite($pipes[0], $hash . "\n");
        }
        \fclose($pipes[0]);

        // Stream pack data to socket
        while (!\feof($pipes[1])) {
            $chunk = \fread($pipes[1], 65536);
            if ($chunk === false) break;
            @\socket_write($socket, $chunk);
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);
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

        // Process push
        $repoPath = \escapeshellarg($repo->path());
        foreach ($commands as $cmd) {
            $oldHash = $cmd['old'];
            $newHash = $cmd['new'];
            $ref = $cmd['ref'];
            // Validate ref name against git ref naming rules to prevent shell injection
            if (\preg_match('/^refs\/[a-zA-Z0-9._\/.-]+$/', $ref) !== 1) {
                $out = ["Invalid ref name: {$ref}"];
                $rc = 128;
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
                // Create: git update-ref <ref> <new> (no old arg)
                $escapedNew = \escapeshellarg($newHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            } else {
                // Update: git update-ref <ref> <new> <old>
                $escapedNew = \escapeshellarg($newHash);
                $escapedOld = \escapeshellarg($oldHash);
                $updateRefCmd = "git -C {$repoPath} update-ref {$escapedRef} {$escapedNew} {$escapedOld} 2>&1";
                \exec($updateRefCmd, $out, $rc);
            }

            if ($rc !== 0) {
                $this->writePacket($socket, "ng {$cmd['ref']}: pre-receive hook declined");
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
     * Write a pkt-line to socket.
     */
    private function writePacket($socket, string $data): void
    {
        if ($data === '') {
            @\socket_write($socket, \pack('N', 0));
            return;
        }

        $len = \strlen($data) + 4;
        $packet = \pack('N', $len) . $data . "\n";
        @\socket_write($socket, $packet);
    }

    /**
     * Close a client connection.
     */
    private function closeClient(int $idx): void
    {
        if (isset($this->clients[$idx])) {
            @\socket_close($this->clients[$idx]['socket']);
            \array_splice($this->clients, $idx, 1);
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

        $bindAddr = $this->config->gitListenAddr;
        $parts = \explode(':', $bindAddr);
        $host = $parts[0] ?: '0.0.0.0';
        $port = isset($parts[1]) ? (int) $parts[1] : 9418;

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
