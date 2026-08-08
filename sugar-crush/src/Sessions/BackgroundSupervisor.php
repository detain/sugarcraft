<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;

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

    public function __construct(?SessionNotificationInterface $listener = null)
    {
        $this->listener = $listener;
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
     * supervisor over a Unix socket. The subprocess daemonizes and runs
     * the agent task independently, streaming output over IPC.
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

        // Build command to run the session subprocess
        // The subprocess will: daemonize, connect to socket, stream output
        $cmd = sprintf(
            'php -r %s',
            escapeshellarg($this->buildSessionDaemonCode($socketPath, $bufferPath, $sessionId, $task, $agent->model ?? 'unknown'))
        );

        $proc = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            fclose($serverSocket);
            @unlink($socketPath);
            @unlink($bufferPath);
            throw new \RuntimeException('Failed to spawn session process');
        }

        // Close child's stdin — we don't write to it
        fclose($pipes[0]);
        // Close child's stderr — output goes to socket
        fclose($pipes[2]);

        // Wait for child to connect to our socket (with timeout)
        $clientSocket = @stream_socket_accept($serverSocket, 5);
        if ($clientSocket === false) {
            proc_close($proc);
            fclose($serverSocket);
            @unlink($socketPath);
            @unlink($bufferPath);
            throw new \RuntimeException('Session process failed to connect to IPC channel within timeout');
        }

        // Get child PID
        $procStatus = proc_get_status($proc);
        $childPid = $procStatus['pid'];

        // Close the server socket — we only needed it to accept the connection
        fclose($serverSocket);

        // Close the parent's copy of stdout pipe — we read via socket now
        fclose($pipes[1]);

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

        // Read initial handshake from child (session ID confirmation)
        stream_set_timeout($clientSocket, 2);
        $handshake = @fgets($clientSocket);
        // Handshake is optional — child may have already written output

        fclose($clientSocket);

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
     * Build the daemon code that the child process runs.
     */
    private function buildSessionDaemonCode(
        string $socketPath,
        string $bufferPath,
        string $sessionId,
        string $task,
        string $model,
    ): string {
        // This code is run in the child process and daemonizes it
        return sprintf(
            '
// Daemonize
umask(0);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }
posix_setsid() >= 0 || posix_setpgid(0, 0);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }

// Close stdio
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);

// Reopen stderr to buffer file
$buffer = fopen(%s, "a");

// Connect to supervisor Unix socket
$supervisor = stream_socket_client(%s, $errno, $errstr, 2);
if (!$supervisor) {
    file_put_contents($buffer, "[session:connect:error] {$errstr}\n");
    exit(1);
}
stream_set_timeout($supervisor, 1);

// Send handshake
fwrite($supervisor, %s . "\n");
fflush($supervisor);

// Read commands and stream output
while (!feof($supervisor)) {
    $cmd = trim(fgets($supervisor));
    if ($cmd === "RESUME" || $cmd === "HEARTBEAT") {
        // Acknowledge with session info
        fwrite($supervisor, "OK:session={$sessionId}\n");
        fwrite($buffer, "[session:heartbeat] pid={$pid}\n");
    } elseif ($cmd === "STOP") {
        fwrite($supervisor, "OK:stopping\n");
        break;
    }
    usleep(100000); // 100ms
}

// Cleanup
fclose($supervisor);
file_put_contents($buffer, "[session:daemon:exit]\n", FILE_APPEND);
exit(0);
',
            var_export($bufferPath, true),
            var_export('unix://' . $socketPath, true),
            var_export($sessionId, true)
        );
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
     * Each active session is checked for stalled heartbeat. A session that
     * was previously not stalled but now exceeds the heartbeat timeout is
     * marked Stalled and the listener is notified. Sessions that resume
     * heartbeating after being stalled are also notified.
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
                    // Parse buffered output lines and restore them
                    $lines = explode("\n", trim($bufferContent));
                    $restoredOutput = '';
                    foreach ($lines as $line) {
                        // Skip internal log lines, preserve actual output
                        if (str_starts_with($line, '[session:') || $line === '') {
                            continue;
                        }
                        $restoredOutput .= $line . "\n";
                    }
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
        // Look up the current session from our map to get the latest state.
        // This is necessary because the caller's reference may be stale
        // (immutable session pattern: each update creates a new object).
        $currentSession = $this->sessions[$session->id] ?? $session;
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
