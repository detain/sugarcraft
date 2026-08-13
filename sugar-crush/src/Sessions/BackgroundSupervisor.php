<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Tui\StallDetector;
use SugarCraft\Crush\Tui\StallWarning;

/**
 * Manages all background sessions as a supervisor process.
 *
 * The supervisor monitors session health via heartbeats, marks stalled
 * sessions, and routes messages between the TUI and each session. Health
 * monitoring reuses the same heartbeat contract as Phase 1's ProcessExecutor:
 * each session reports a heartbeat on a fixed interval, and the supervisor
 * marks it stalled if heartbeats stop arriving.
 *
 * Mirrors charmbracelet/charmcrush background session supervisor design.
 */
final class BackgroundSupervisor implements SessionNotificationInterface
{
    /**
     * Heartbeat timeout in seconds — matching Phase 1's ProcessExecutor.
     */
    public const HEARTBEAT_TIMEOUT_SECS = 15;

    /** @var array<string, BackgroundSession> Sessions indexed by session ID */
    private array $sessions = [];

    /** @var SessionNotificationInterface|null Optional listener for session events */
    private ?SessionNotificationInterface $listener = null;

    /** @var bool Whether IPC reconnect has been performed for this supervisor cycle */
    private bool $reconnected = false;

    /**
     * IPC state per session: socket path, buffer file path, child PID.
     *
     * @var array<string, array{socketPath: string, bufferPath: string, pid: int}>
     */
    private array $sessionIpc = [];

    /** Tracks per-session token output rates to detect stalls. */
    private StallDetector $stallDetector;

    /**
     * Last observed buffer-file mtime per session — the daemon's liveness
     * signal. See {@see self::tick()}.
     *
     * @var array<string, int>
     */
    private array $bufferMtimes = [];

    public function __construct(
        ?SessionNotificationInterface $listener = null,
        ?StallDetector $stallDetector = null,
    ) {
        $this->listener = $listener;
        $this->stallDetector = $stallDetector ?? new StallDetector();
    }

    // =========================================================================
    // Session Management
    // =========================================================================

    /**
     * Add a new background session to the supervisor.
     */
    public function addSession(BackgroundSession $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    /**
     * Remove a session from the supervisor by ID.
     */
    public function removeSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    /**
     * Get a session by ID, or null if not found.
     */
    public function getSession(string $sessionId): ?BackgroundSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    /**
     * Return all active (non-terminal) sessions.
     *
     * @return array<string, BackgroundSession>
     */
    public function getActiveSessions(): array
    {
        $active = [];
        foreach ($this->sessions as $id => $session) {
            if ($session->isActive()) {
                $active[$id] = $session;
            }
        }
        return $active;
    }

    /**
     * Return true when there is at least one active session.
     */
    public function hasActiveSessions(): bool
    {
        foreach ($this->sessions as $session) {
            if ($session->isActive()) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Child Process Spawning
    // =========================================================================

    /**
     * Spawn a new background session as a child process with IPC.
     *
     * Uses proc_open() to launch a subprocess that connects back to the
     * supervisor over a Unix socket. The subprocess daemonizes and then hands
     * control to {@see BackgroundSessionRunner}, which forks a worker running
     * the real agent turn for $task and appends its answer to the session
     * buffer file while the daemon keeps servicing HEARTBEAT/RESUME/STOP.
     *
     * @return BackgroundSession The newly spawned session
     * @throws \RuntimeException If child fails to connect within timeout
     */
    public function spawnSession(
        string $name,
        Agent $agent,
        string $task,
        string $workingDirectory,
        int $timeoutSeconds = 3600,
        ?array $tags = null,
    ): BackgroundSession {
        $sessionId = $this->generateSessionId();
        $socketPath = sys_get_temp_dir() . '/sugar_crush_' . $sessionId . '.sock';
        $bufferPath = sys_get_temp_dir() . '/sugar_crush_' . $sessionId . '.buffer';

        // Remove any stale socket/buffer from previous runs
        @unlink($socketPath);
        @file_put_contents($bufferPath, '');

        // Create Unix socket server for IPC
        $serverSocket = stream_socket_server(
            'unix://' . $socketPath,
            $errno,
            $errstr
        );
        if (!$serverSocket) {
            @unlink($socketPath);
            @unlink($bufferPath);
            throw new \RuntimeException("Failed to create IPC socket: {$errstr}");
        }
        stream_set_timeout($serverSocket, 5);

        // Build command to run the session subprocess.
        // The subprocess will: daemonize, connect to socket, run the task.
        $cmd = sprintf(
            '%s -r %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->buildSessionDaemonCode(
                $socketPath,
                $bufferPath,
                $sessionId,
                $task,
                $workingDirectory,
                $agent->provider,
                $agent->model,
                $timeoutSeconds,
            ))
        );

        // Daemon stdout/stderr go to a sidecar log rather than the session
        // buffer: the buffer is the curated transcript reconnect() restores
        // as session output, and a stray provider warning printed on stderr
        // must not end up quoted back to the user as model output.
        $logPath = $bufferPath . '.log';
        $proc = proc_open(
            $cmd,
            [['file', '/dev/null', 'r'], ['file', $logPath, 'a'], ['file', $logPath, 'a']],
            $pipes
        );

        if (!is_resource($proc)) {
            fclose($serverSocket);
            @unlink($socketPath);
            @unlink($bufferPath);
            throw new \RuntimeException('Failed to spawn session process');
        }

        // Wait for child to connect to our socket (with timeout)
        $clientSocket = @stream_socket_accept($serverSocket, 5);
        if ($clientSocket === false) {
            proc_close($proc);
            fclose($serverSocket);
            @unlink($socketPath);
            @unlink($bufferPath);
            throw new \RuntimeException('Session process failed to connect to IPC channel within timeout');
        }

        // Close the server socket — we only needed it to accept the connection
        fclose($serverSocket);

        // Create and register the session
        $session = new BackgroundSession(
            id: $sessionId,
            name: $name,
            agent: $agent,
            task: $task,
            workingDirectory: $workingDirectory,
            timeoutSeconds: $timeoutSeconds,
            tags: $tags,
        );

        // Read the handshake and take the DAEMON's pid from it. The pid
        // proc_get_status() reports belongs to the `php -r` launcher, which
        // exits during the daemon's double fork — tracking that one makes
        // isProcessRunning() false immediately and reconnect() would report
        // every freshly spawned session as already Completed.
        stream_set_timeout($clientSocket, 2);
        $handshake = @fgets($clientSocket);
        fclose($clientSocket);

        $childPid = self::parseHandshakePid(is_string($handshake) ? $handshake : '')
            ?? (proc_get_status($proc)['pid'] ?? 0);

        $session = $session->withStatus(BackgroundSessionStatus::Running);

        $this->sessions[$sessionId] = $session;
        $this->sessionIpc[$sessionId] = [
            'socketPath' => $socketPath,
            'bufferPath' => $bufferPath,
            'pid' => $childPid,
        ];

        return $session;
    }

    /**
     * Build the bootstrap code that the child process runs.
     *
     * It does exactly three things — daemonize, load the autoloader, hand off
     * to {@see BackgroundSessionRunner::main()} — because code embedded in a
     * `php -r` string cannot be unit-tested, static-analysed or read
     * comfortably. The agent loop itself therefore lives in a real class.
     */
    public function buildSessionDaemonCode(
        string $socketPath,
        string $bufferPath,
        string $sessionId,
        string $task,
        string $workingDirectory,
        string $provider,
        string $model,
        int $timeoutSeconds,
    ): string {
        $config = [
            'sessionId' => $sessionId,
            'socketPath' => $socketPath,
            'bufferPath' => $bufferPath,
            'task' => $task,
            'workingDirectory' => $workingDirectory,
            'provider' => $provider,
            'model' => $model,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        return sprintf(
            '
umask(0);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }
posix_setsid() >= 0 || posix_setpgid(0, 0);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }

$autoload = %s;
if ($autoload === null || !is_file($autoload)) {
    file_put_contents(%s, "[session:bootstrap:error] composer autoload not found\n", FILE_APPEND);
    exit(1);
}
require $autoload;

exit(\SugarCraft\Crush\Sessions\BackgroundSessionRunner::main(json_decode(%s, true)));
',
            var_export(self::autoloadPath(), true),
            var_export($bufferPath, true),
            var_export((string) json_encode($config), true)
        );
    }

    /**
     * Locate the composer autoloader the daemon must require.
     *
     * Asks the live ClassLoader first so the daemon loads the very same
     * autoloader this process is running under, whether sugar-crush is the
     * root package or installed under someone else's vendor/.
     */
    public static function autoloadPath(): ?string
    {
        foreach (spl_autoload_functions() ?: [] as $callable) {
            if (is_array($callable) && $callable[0] instanceof \Composer\Autoload\ClassLoader) {
                $file = (new \ReflectionClass($callable[0]))->getFileName();
                if (is_string($file)) {
                    $candidate = dirname($file, 2) . '/autoload.php';
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../autoload.php'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extract the daemon PID from a `HELLO:<sessionId>:<pid>` handshake.
     *
     * Returns null for anything else so a malformed or missing handshake
     * falls back to the launcher PID rather than tracking pid 0.
     */
    public static function parseHandshakePid(string $handshake): ?int
    {
        $handshake = trim($handshake);
        if (!str_starts_with($handshake, BackgroundSessionRunner::HANDSHAKE_PREFIX)) {
            return null;
        }

        $parts = explode(':', $handshake);
        $pid = end($parts);
        if (!is_string($pid) || !ctype_digit($pid) || (int) $pid <= 0) {
            return null;
        }

        return (int) $pid;
    }

    /**
     * Generate a unique session ID.
     */
    private function generateSessionId(): string
    {
        return sprintf(
            'sess_%s_%s',
            date('YmdHis'),
            bin2hex(random_bytes(4))
        );
    }

    // =========================================================================
    // Health Monitoring (Heartbeat Ticker)
    // =========================================================================

    /**
     * Tick the supervisor — call this periodically to check session health.
     *
     * A spawned session whose daemon has exited is settled first (see
     * {@see self::reapFinishedDaemon()}); the rest are checked for stalled
     * heartbeat. A session that was previously not stalled but now exceeds the
     * heartbeat timeout is marked Stalled and the listener is notified.
     * Sessions that resume heartbeating after being stalled are also notified.
     *
     * @param int|null $now Unix timestamp for testing injection; defaults to time()
     */
    public function tick(?int $now = null): void
    {
        $now = $now ?? time();

        foreach ($this->sessions as $id => $session) {
            if (!$session->isActive()) {
                continue;
            }

            if ($this->reapFinishedDaemon($id, $session)) {
                continue;
            }

            $this->absorbDaemonHeartbeat($id, $session);

            $wasStalled = $session->status === BackgroundSessionStatus::Stalled;
            $isStalled = $session->isStalled(self::HEARTBEAT_TIMEOUT_SECS);

            if ($isStalled && !$wasStalled) {
                // Transition running → stalled
                $newSession = $session->withStatus(BackgroundSessionStatus::Stalled);
                $this->sessions[$id] = $newSession;
                $this->onSessionStalled($newSession);
            } elseif (!$isStalled && $wasStalled) {
                // Transition stalled → running (resumed)
                $newSession = $session->withStatus(BackgroundSessionStatus::Running);
                $this->sessions[$id] = $newSession;
                $this->onSessionResumed($newSession);
            }
        }
    }

    /**
     * Settle a spawned session whose daemon has exited, and report its answer.
     *
     * The daemon exits the instant its task settles (see
     * {@see BackgroundSessionRunner::supervise()}), so a dead pid IS the
     * completion signal — and it is the only one, since the spawn connection
     * is long gone by then. Without reaping here a session that finished
     * SUCCESSFULLY merely stops touching its buffer file, which
     * {@see self::absorbDaemonHeartbeat()} cannot tell apart from a wedged
     * daemon: the user was told a finished session had "stalled", never saw
     * the answer the worker wrote into the buffer, and the session stayed
     * active forever, holding the TUI's background poll open for the rest of
     * the process.
     *
     * @return bool true when the session was settled and needs no stall check
     */
    private function reapFinishedDaemon(string $id, BackgroundSession $session): bool
    {
        $ipc = $this->sessionIpc[$id] ?? null;
        // pid 0 means BOTH the handshake and proc_get_status() failed, so this
        // daemon's liveness is unknown — fall through to the stall check
        // rather than declaring a session finished that we cannot observe.
        if ($ipc === null || $ipc['pid'] <= 0 || $this->isProcessRunning($ipc['pid'])) {
            return false;
        }

        $buffer = (string) @file_get_contents($ipc['bufferPath']);
        $output = self::restoreOutput($buffer);
        if ($output !== '') {
            $session = $session->withOutput($output);
        }

        $failed = self::bufferReportsFailure($buffer);
        $session = $session->withStatus(
            $failed ? BackgroundSessionStatus::Failed : BackgroundSessionStatus::Completed
        );

        $this->sessions[$id] = $session;
        unset($this->bufferMtimes[$id]);

        if ($failed) {
            $this->onSessionFailed($session);
        } else {
            $this->onSessionCompleted($session);
        }

        return true;
    }

    /**
     * Pull the model's answer out of a session buffer file.
     *
     * `[session:` lines are the daemon's own bookkeeping — heartbeats, task
     * lifecycle, bootstrap errors — and must never be quoted back to the user
     * as model output.
     */
    private static function restoreOutput(string $buffer): string
    {
        $restored = '';
        foreach (explode("\n", trim($buffer)) as $line) {
            if ($line === '' || str_starts_with($line, '[session:')) {
                continue;
            }
            $restored .= $line . "\n";
        }

        return $restored;
    }

    /**
     * Decide a settled session's outcome from the last `[session:task:...]`
     * record its daemon wrote.
     *
     * Anything other than a completion record — failed, timeout, stopped, a
     * lone `start` from a daemon that died mid-turn, or no record at all —
     * counts as a failure. Reporting those as Completed would be the same
     * class of lie as the old "Backgrounded as <id>" for work that never ran.
     */
    private static function bufferReportsFailure(string $buffer): bool
    {
        $outcome = null;
        foreach (explode("\n", $buffer) as $line) {
            if (!str_starts_with($line, '[session:task:')) {
                continue;
            }
            $rest = substr($line, strlen('[session:task:'));
            $end = strpos($rest, ']');
            $outcome = $end === false ? $rest : substr($rest, 0, $end);
        }

        return !in_array($outcome, ['complete', 'completed'], true);
    }

    /**
     * Record a heartbeat for a spawned session whose daemon is still writing.
     *
     * {@see BackgroundSessionRunner} stamps a heartbeat record into the
     * session buffer every few seconds, so an advancing buffer mtime is proof
     * the daemon is alive. Without this every genuinely running spawned
     * session went Stalled after HEARTBEAT_TIMEOUT_SECS, because nothing ever
     * called recordHeartbeat() after construction. Reading an mtime keeps
     * tick() non-blocking — a wedged daemon stops touching the file and is
     * still correctly reported as stalled.
     */
    private function absorbDaemonHeartbeat(string $id, BackgroundSession $session): void
    {
        $bufferPath = $this->sessionIpc[$id]['bufferPath'] ?? null;
        if ($bufferPath === null) {
            return;
        }

        clearstatcache(true, $bufferPath);
        $mtime = @filemtime($bufferPath);
        if ($mtime === false) {
            return;
        }

        if (($this->bufferMtimes[$id] ?? 0) !== $mtime) {
            $this->bufferMtimes[$id] = $mtime;
            $session->recordHeartbeat();
        }
    }

    // =========================================================================
    // IPC Reconnect (TUI Reopen)
    // =========================================================================

    /**
     * Reconnect to existing sessions over IPC when the TUI reopens.
     *
     * This restores state for all sessions that were running when the TUI
     * closed, allowing the user to see partial output and continue interacting.
     * Sessions must have been spawned via spawnSession() and have IPC data stored.
     *
     * @return array<string, BackgroundSession> Sessions that were reconnected
     */
    public function reconnect(): array
    {
        if ($this->reconnected) {
            return [];
        }

        $reconnected = [];

        foreach ($this->sessions as $id => $session) {
            if (!$session->isActive()) {
                continue;
            }

            $ipc = $this->sessionIpc[$id] ?? null;

            // Restore partial output from buffer file
            if ($ipc !== null && file_exists($ipc['bufferPath'])) {
                $bufferContent = file_get_contents($ipc['bufferPath']);
                if ($bufferContent !== '' && $session->output === '') {
                    // Buffer has content and session output is empty (first reconnect)
                    $restoredOutput = self::restoreOutput((string) $bufferContent);
                    if ($restoredOutput !== '') {
                        $session = $session->withOutput($restoredOutput);
                        $this->sessions[$id] = $session;
                    }
                }
            }

            // If session has IPC data, check if child is still running and connect
            if ($ipc !== null) {
                $childRunning = $this->isProcessRunning($ipc['pid']);

                if ($childRunning && file_exists($ipc['socketPath'])) {
                    // Child is still running — connect and send RESUME over IPC
                    $supervisor = @stream_socket_client(
                        'unix://' . $ipc['socketPath'],
                        $errno,
                        $errstr,
                        1 // 1 second timeout
                    );

                    if ($supervisor !== false) {
                        stream_set_timeout($supervisor, 1);
                        fwrite($supervisor, "RESUME\n");
                        fflush($supervisor);

                        // Read responses (non-blocking)
                        while (!feof($supervisor)) {
                            $line = @fgets($supervisor);
                            if ($line === false) {
                                break;
                            }
                            $line = trim($line);
                            if ($line === '' || str_starts_with($line, 'OK:')) {
                                continue;
                            }
                            // This is a response line — could be output or status
                            // For now, treat as confirmation
                        }

                        fclose($supervisor);
                    }

                    // Re-establish IPC entry in case session state changed
                    $reconnected[$id] = $this->sessions[$id];
                } else {
                    // Child has exited — session is complete
                    if ($session->isActive()) {
                        $session = $session->withStatus(BackgroundSessionStatus::Completed);
                        $this->sessions[$id] = $session;
                    }
                    $reconnected[$id] = $session;
                }
            } else {
                // Session without IPC data — just mark as reconnected
                $reconnected[$id] = $session;
            }
        }

        $this->reconnected = true;
        return $reconnected;
    }

    /**
     * Check if a process is running by PID.
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        // Send signal 0 — checks if process exists without sending any signal
        return posix_kill($pid, 0);
    }

    /**
     * Reset the reconnect flag — useful when supervisor restarts fresh.
     */
    public function resetReconnected(): void
    {
        $this->reconnected = false;
    }

    // =========================================================================
    // Stall Detection
    // =========================================================================

    /**
     * Return all agents currently flagged as stalled via their token throughput.
     *
     * @return array<string, StallWarning>
     */
    public function getStallWarnings(): array
    {
        return $this->stallDetector->getStallWarnings();
    }

    // =========================================================================
    // SessionNotificationInterface Implementation
    // =========================================================================

    public function onSessionCompleted(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionCompleted($session);
        }
    }

    public function onSessionFailed(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionFailed($session);
        }
    }

    public function onSessionStalled(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionStalled($session);
        }
    }

    public function onSessionResumed(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionResumed($session);
        }
    }

    public function onSessionStreaming(BackgroundSession $session, string $chunk): void
    {
        // Track tokens for stall detection. The provider increments
        // $session->tokensUsed before calling this callback, so we read
        // it from the stored session reference (same object instance).
        $currentSession = $this->sessions[$session->id] ?? $session;
        $this->stallDetector->track($session->id, $currentSession->tokensUsed);

        // Look up the current session from our map to get the latest state.
        // This is necessary because the caller's reference may be stale
        // (immutable session pattern: each update creates a new object).
        $newSession = $currentSession->withOutput($currentSession->output . $chunk);
        $this->sessions[$session->id] = $newSession;

        if ($this->listener !== null) {
            $this->listener->onSessionStreaming($newSession, $chunk);
        }
    }

    // =========================================================================
    // Mutation (Immutable + Fluent Pattern)
    // =========================================================================

    /**
     * Create a new supervisor with a different listener.
     */
    public function withListener(?SessionNotificationInterface $listener): self
    {
        $clone = clone $this;
        $clone->listener = $listener;
        return $clone;
    }
}
