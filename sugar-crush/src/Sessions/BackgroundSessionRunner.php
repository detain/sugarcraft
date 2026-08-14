<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Message;

/**
 * The agent loop that a `/bg` background session actually runs.
 *
 * {@see BackgroundSupervisor::spawnSession()} double-forks a detached PHP
 * process; that process requires the composer autoloader and hands control
 * straight to {@see self::main()}. Everything the daemon does after
 * daemonizing lives here rather than inside the `php -r` bootstrap string so
 * it is ordinary, unit-testable repository code — the previous inline daemon
 * only answered HEARTBEAT/RESUME/STOP and never executed the task it was
 * spawned with, which made "Backgrounded as <id>" a message about work that
 * never happened (crush_feat.md section 5 E3).
 *
 * Process shape, and why:
 *
 *  - The daemon connects back to the supervisor's Unix socket and sends
 *    `HELLO:<sessionId>:<pid>`. The PID matters: `spawnSession()` otherwise
 *    only knows the PID of the short-lived `php -r` launcher, which exits
 *    during the double fork, so {@see BackgroundSupervisor::reconnect()}
 *    would see a dead process and declare every session Completed the
 *    instant it was spawned.
 *  - The task runs in a forked worker so a multi-minute agent turn cannot
 *    block heartbeat/RESUME/STOP servicing. The daemon keeps that command
 *    loop, re-binding the same socket path as a *server* once the spawn
 *    connection closes — the supervisor's `reconnect()` connects to exactly
 *    that path, so RESUME reaches a live daemon instead of a closed socket.
 *  - The daemon exits as soon as the task settles. That exit is the
 *    completion signal `reconnect()` already looks for (`isProcessRunning()`
 *    false → Completed), and by then the worker has appended the assistant's
 *    answer to the buffer file `reconnect()` restores output from.
 *
 * Buffer writes are single `FILE_APPEND` calls of whole lines: the worker and
 * the daemon append to the same file concurrently, and O_APPEND makes each
 * such write atomic, so a heartbeat record can never land inside a line of
 * model output. Lines prefixed `[session:` are internal and are skipped by
 * {@see BackgroundSupervisor::reconnect()}; everything else is the answer.
 */
final class BackgroundSessionRunner
{
    /** Handshake prefix the supervisor parses the daemon PID out of. */
    public const HANDSHAKE_PREFIX = 'HELLO:';

    /**
     * How often the daemon stamps a heartbeat record into the buffer file.
     * Must stay comfortably under
     * {@see BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS} or a healthy
     * session would be reported as stalled.
     */
    public const HEARTBEAT_INTERVAL_SECS = 5;

    /** Seconds a single accept() may block before the worker is re-checked. */
    private const ACCEPT_TIMEOUT_SECS = 0.5;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $socketPath,
        public readonly string $bufferPath,
        public readonly string $task,
        public readonly string $workingDirectory = '',
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly int $timeoutSeconds = 3600,
    ) {}

    /**
     * Build a runner from the JSON config the spawn bootstrap passes in.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            sessionId: (string) ($config['sessionId'] ?? ''),
            socketPath: (string) ($config['socketPath'] ?? ''),
            bufferPath: (string) ($config['bufferPath'] ?? ''),
            task: (string) ($config['task'] ?? ''),
            workingDirectory: (string) ($config['workingDirectory'] ?? ''),
            provider: (string) ($config['provider'] ?? ''),
            model: (string) ($config['model'] ?? ''),
            timeoutSeconds: (int) ($config['timeoutSeconds'] ?? 3600),
        );
    }

    /**
     * Daemon entry point — the one call the `php -r` bootstrap makes.
     *
     * @param array<string, mixed>|null $config decoded spawn config
     * @return int process exit code
     */
    public static function main(?array $config): int
    {
        if ($config === null || !isset($config['bufferPath'], $config['socketPath'])) {
            return 1;
        }

        return self::fromConfig($config)->run();
    }

    /**
     * Connect back to the supervisor, run the task, and supervise it.
     *
     * $backend is injectable so a test can drive the whole lifecycle without
     * an LLM; production passes null and the worker resolves the backend
     * itself via {@see self::backend()}.
     */
    public function run(?Backend $backend = null): int
    {
        $supervisor = @\stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 2);
        if ($supervisor === false) {
            $this->log('[session:connect:error] ' . $this->oneLine((string) $errstr));

            return 1;
        }

        \stream_set_timeout($supervisor, 1);
        \fwrite($supervisor, self::HANDSHAKE_PREFIX . $this->sessionId . ':' . \getmypid() . "\n");
        \fflush($supervisor);
        // The supervisor reads the handshake and drops this connection; the
        // daemon owns the socket path from here on (see class docblock).
        \fclose($supervisor);

        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            // Honest degradation rather than a session that reports success
            // and never runs: the task still executes, but commands are not
            // serviced while it does.
            $this->log('[session:daemon:degraded] pcntl unavailable - running task without command servicing');

            return $this->executeTask($backend);
        }

        $worker = \pcntl_fork();
        if ($worker === 0) {
            exit($this->executeTask($backend));
        }
        if ($worker < 0) {
            $this->log('[session:fork:error] could not fork task worker');

            return 1;
        }

        return $this->supervise($worker);
    }

    /**
     * Run one agent turn for this session's task, appending the answer to the
     * buffer file the supervisor restores output from.
     *
     * Streamed tokens are flushed a whole line at a time so a reconnecting
     * TUI sees partial output while the turn is still running.
     *
     * @return int 0 when the turn completed, 1 when it failed
     */
    public function executeTask(?Backend $backend = null): int
    {
        if ($this->workingDirectory !== '' && \is_dir($this->workingDirectory)) {
            @\chdir($this->workingDirectory);
        }

        $this->log('[session:task:start]');

        if ($backend === null) {
            try {
                $backend = $this->backend();
            } catch (\Throwable $e) {
                $this->log('[session:task:failed] ' . $this->oneLine($e->getMessage()));

                return 1;
            }
        }

        $pending = '';
        $streamed = '';
        $onToken = function (string $token) use (&$pending, &$streamed): void {
            $streamed .= $token;
            $pending .= $token;
            $lastNewline = \strrpos($pending, "\n");
            if ($lastNewline === false) {
                return;
            }
            $this->append(\substr($pending, 0, $lastNewline + 1));
            $pending = \substr($pending, $lastNewline + 1);
        };

        try {
            $message = $backend->complete([Message::user($this->task)], $onToken);
        } catch (\Throwable $e) {
            if ($pending !== '') {
                $this->append($pending . "\n");
            }
            $this->log('[session:task:failed] ' . $this->oneLine($e->getMessage()));

            return 1;
        }

        if ($streamed === '') {
            // Backend answered in one shot; nothing has been written yet.
            $this->append(\rtrim($message->content, "\n") . "\n");
        } elseif ($pending !== '') {
            $this->append($pending . "\n");
        }

        $this->log('[session:task:complete]');

        return 0;
    }

    /**
     * Resolve the backend this session's agent should run on.
     *
     * Falls back to {@see Bootstrap::backend()}'s own env-driven selection
     * when the agent names a provider that cannot be constructed, so an
     * unknown provider degrades to the offline engine rather than killing
     * the session.
     *
     * @throws PermissionConfigException when the launch's permission policy is
     *         present and unusable — the one failure the fallback cannot help
     *         with, reported by {@see self::executeTask()} as a task failure
     *         with the real reason rather than as a provider fallback
     */
    public function backend(): Backend
    {
        $root = $this->workingDirectory !== '' ? $this->workingDirectory : null;

        if ($this->model !== '' && $this->model !== 'unknown') {
            \putenv('SUGARCRUSH_MODEL=' . $this->model);
        }

        if ($this->provider !== '') {
            try {
                return Bootstrap::backendFor($this->provider, $root);
            } catch (PermissionConfigException $e) {
                // Same arm {@see \SugarCraft\Crush\Cli\NonInteractive::run()}
                // and {@see Bootstrap::backend()} carry: an unusable permission
                // policy is not this provider's fault and is not survivable by
                // degrading. Bootstrap::backend() below builds the very same
                // gate from the very same config, so the old catch-all logged
                // a misleading "[session:provider:fallback]" line naming the
                // permission error as a provider problem, and then hit the
                // identical exception one line later. That second throw was
                // never uncaught — {@see self::executeTask()} wraps this call
                // in its own `catch (\Throwable)` and reports
                // "[session:task:failed]" — so what this fixes is the
                // misleading line, not a killed session.
                throw $e;
            } catch (\Throwable $e) {
                $this->log('[session:provider:fallback] ' . $this->oneLine($e->getMessage()));
            }
        }

        return Bootstrap::backend($root);
    }

    /**
     * Answer one supervisor connection.
     *
     * Keeps the wire words the previous inline daemon used — HEARTBEAT,
     * RESUME and STOP — and adds a single `STATUS:` line so a RESUME can
     * tell a running turn from a settled one. {@see
     * BackgroundSupervisor::reconnect()} skips `OK:` lines and ignores the
     * rest, so the extra line is backward compatible.
     *
     * @param resource $client
     * @param string|null $result null while the task is still running
     * @return bool true when the supervisor asked the session to stop
     */
    public function serveClient($client, ?string $result): bool
    {
        \stream_set_timeout($client, 1);

        while (!\feof($client)) {
            $line = @\fgets($client);
            if ($line === false) {
                break;
            }
            $command = \trim($line);
            if ($command === '') {
                continue;
            }

            if ($command === 'HEARTBEAT' || $command === 'RESUME') {
                \fwrite($client, 'OK:session=' . $this->sessionId . "\n");
                if ($command === 'RESUME') {
                    \fwrite($client, 'STATUS:' . ($result ?? 'running') . "\n");
                }
                \fflush($client);
                $this->log('[session:heartbeat] pid=' . \getmypid());
            } elseif ($command === 'STOP') {
                \fwrite($client, "OK:stopping\n");
                \fflush($client);

                return true;
            }
        }

        return false;
    }

    /**
     * Append a raw chunk to the session buffer.
     *
     * A single FILE_APPEND write per record is deliberate: the worker and
     * the daemon both append here, and O_APPEND keeps each write atomic.
     */
    public function append(string $text): void
    {
        if ($text === '') {
            return;
        }

        @\file_put_contents($this->bufferPath, $text, \FILE_APPEND);
    }

    /** Append one internal `[session:...]` record. */
    public function log(string $line): void
    {
        $this->append($line . "\n");
    }

    /**
     * Serve supervisor commands until the worker settles, then exit.
     *
     * Exiting on completion is what tells the supervisor the session is
     * finished — it has no other completion channel once the spawn
     * connection is gone.
     */
    private function supervise(int $worker): int
    {
        @\unlink($this->socketPath);
        $server = @\stream_socket_server('unix://' . $this->socketPath, $errno, $errstr);

        $deadline = \time() + $this->timeoutSeconds;
        $lastHeartbeat = \time();
        $result = null;
        $stopped = false;

        while (true) {
            $status = 0;
            $reaped = \pcntl_waitpid($worker, $status, \WNOHANG);
            if ($reaped === $worker) {
                $exited = \pcntl_wifexited($status) && \pcntl_wexitstatus($status) === 0;
                $result = $exited ? 'completed' : 'failed';
                $this->log('[session:task:' . $result . ']');
                break;
            }

            if (\time() > $deadline) {
                @\posix_kill($worker, \SIGTERM);
                \pcntl_waitpid($worker, $status);
                $result = 'timeout';
                $this->log('[session:task:timeout]');
                break;
            }

            if ($server !== false) {
                $client = @\stream_socket_accept($server, self::ACCEPT_TIMEOUT_SECS);
                if ($client !== false) {
                    $stopped = $this->serveClient($client, $result);
                    \fclose($client);
                }
            } else {
                \usleep(200_000);
            }

            if ($stopped) {
                @\posix_kill($worker, \SIGTERM);
                \pcntl_waitpid($worker, $status);
                $result = 'stopped';
                $this->log('[session:task:stopped]');
                break;
            }

            if (\time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL_SECS) {
                $this->log('[session:heartbeat] pid=' . \getmypid());
                $lastHeartbeat = \time();
            }
        }

        if ($server !== false) {
            \fclose($server);
            @\unlink($this->socketPath);
        }

        $this->log('[session:daemon:exit]');

        return $result === 'completed' ? 0 : 1;
    }

    /** Collapse a message to one line so it cannot corrupt the buffer's line protocol. */
    private function oneLine(string $text): string
    {
        return \trim(\preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
