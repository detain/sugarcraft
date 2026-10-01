<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Crush\Support\ToolIpcFiles;
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
 *
 * ## IPC trust model (audit M5)
 *
 * Every background session's socket, buffer, secret token and sidecar log live
 * inside ONE per-process directory under `sys_get_temp_dir()` created 0700, and
 * every file inside it is created 0600. That alone takes a foreign uid out of
 * reach on Linux — a unix-socket `connect()` and an append to the buffer both
 * need directory traversal — but the directory is not the only line:
 *
 *  - The first handshake used to be trusted by arrival order (`HELLO:<id>:<pid>`
 *    parsed whoever spoke first) and the session id is a timestamp plus four
 *    random bytes, not a credential. A co-tenant on a shared `/tmp` could race
 *    the real daemon, register under a spoofed pid, and later receive the
 *    RESUME traffic — or drive `STOP` against a pid the supervisor never
 *    spawned (local DoS once that pid was recycled).
 *  - So the handshake now carries a per-spawn secret: `spawnSession()` mints 32
 *    hex bytes with `random_bytes()`, writes them 0600 to a token file, and
 *    hands the DAEMON ONLY THE PATH in its argv config — the token bytes never
 *    travel in argv, which every local user can read through `/proc`. The
 *    connection is accepted only when the fourth handshake field matches the
 *    minted token under `hash_equals()`, and the recorded pid is kept together
 *    with that process's `/proc` start time so `isProcessRunning()` refuses a
 *    recycled pid wearing the dead daemon's number.
 *  - Fail-safe in both directions: a missing or unreadable token file is a
 *    refusal, never a trust; the runner's command loop authenticates every
 *    connection the same way before honouring `STOP`.
 */
final class BackgroundSupervisor implements SessionNotificationInterface
{
    /**
     * Heartbeat timeout in seconds — matching Phase 1's ProcessExecutor.
     */
    public const HEARTBEAT_TIMEOUT_SECS = 15;

    /**
     * How long {@see spawnSession()} waits, total, for an AUTHENTICATED
     * handshake from the daemon it just launched. Connections that fail the
     * token check are refused inside this window and the accept loop keeps
     * going, so a hostile local connect cannot consume the budget by itself.
     */
    private const SPAWN_AUTH_TIMEOUT_SECS = 5.0;

    /** @var array<string, BackgroundSession> Sessions indexed by session ID */
    private array $sessions = [];

    /** @var SessionNotificationInterface|null Optional listener for session events */
    private ?SessionNotificationInterface $listener = null;

    /** @var bool Whether IPC reconnect has been performed for this supervisor cycle */
    private bool $reconnected = false;

    /**
     * IPC state per session: socket path, buffer file path, secret-token file
     * path, child PID, and the daemon's `/proc` start time — the pid identity
     * fingerprint that lets a recycled pid be refused (audit M5).
     *
     * `tokenPath`/`startTime` are optional only for entries a test injects in
     * the pre-M5 three-key shape; {@see spawnSession()} always writes all five.
     *
     * @var array<string, array{socketPath: string, bufferPath: string, pid: int, tokenPath?: string, startTime?: int|null}>
     */
    private array $sessionIpc = [];

    /**
     * Per-process private directory every session's IPC files live in.
     * Created 0700 on first use by {@see ensurePrivateIpcDir()}.
     */
    private string $ipcDir = '';

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
     * @throws \RuntimeException If the private IPC directory or files cannot be
     *         created, or the child fails to authenticate within the handshake
     *         window
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
        // All four files live inside a 0700 per-process directory rather than
        // loose in a world-writable /tmp (audit M5): directory permissions are
        // what make the socket unconnectable and the buffer un-appendable by a
        // foreign uid, and the token below is what makes the session
        // un-hijackable even by a same-uid process that somehow reads them.
        $dir = $this->ensurePrivateIpcDir();
        $socketPath = $dir . '/' . $sessionId . '.sock';
        $bufferPath = $dir . '/' . $sessionId . '.buffer';
        $tokenPath = $dir . '/' . $sessionId . '.token';
        // Daemon stdout/stderr go to a sidecar log rather than the session
        // buffer: the buffer is the curated transcript reconnect() restores
        // as session output, and a stray provider warning printed on stderr
        // must not end up quoted back to the user as model output.
        $logPath = $bufferPath . '.log';

        // Per-spawn shared secret. The DAEMON learns only the token PATH (in
        // its argv config): `php -r` argv is world-readable through /proc for
        // the launcher's whole life, so the bytes themselves must never ride
        // argv. Created 0600 via the house umask-narrowed write so the secret
        // is owner-only from its first byte.
        $token = bin2hex(random_bytes(16));
        if (!ToolIpcFiles::write($tokenPath, $token)
            || !ToolIpcFiles::write($bufferPath, '')
            || !ToolIpcFiles::write($logPath, '')
        ) {
            throw new \RuntimeException("Failed to create private IPC files under {$dir}");
        }

        // Build the argv for the session subprocess, which will daemonize,
        // connect to the socket and run the task.
        //
        // AN ARGV, NOT A SHELL STRING, and the change is what makes the comment
        // below the handshake TRUE. This was
        // `sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($code))`,
        // and a string hands `proc_open()` to `/bin/sh -c`. `/bin/sh` on this
        // host is dash, which does NOT apply the `-c` exec optimisation —
        // MEASURED, with this exact descriptor spec, the direct child's `comm`
        // is `(sh)` and the `php -r` launcher is a GRANDCHILD. So
        // `proc_get_status($proc)['pid']` reported the SHELL, not the launcher
        // the comment named, and `proc_terminate()` on the error path below
        // killed dash and left the launcher running. Nothing here was ever a
        // shell fragment — it is a program and two arguments — so the shell
        // bought nothing and cost the process tree.
        $cmd = [
            PHP_BINARY,
            '-r',
            $this->buildSessionDaemonCode(
                $socketPath,
                $bufferPath,
                $tokenPath,
                $sessionId,
                $task,
                $workingDirectory,
                $agent->provider,
                $agent->model,
                $timeoutSeconds,
            ),
        ];

        $proc = proc_open(
            $cmd,
            [['file', '/dev/null', 'r'], ['file', $logPath, 'a'], ['file', $logPath, 'a']],
            $pipes
        );

        if (!is_resource($proc)) {
            $this->discardIpcFiles($socketPath, $bufferPath, $tokenPath, $logPath);
            throw new \RuntimeException('Failed to spawn session process');
        }

        // Create the Unix socket server AFTER the fork, deliberately (audit
        // M5 follow-up, measured): a listener bound BEFORE proc_open is
        // inherited by the launcher and survives every fclose on this side —
        // MEASURED, `ss -x` showed the daemon, its worker, and even the
        // `sh`/`sleep` grandchildren holding the LISTEN fd open. The zombie
        // listener keeps completing connect() handshakes into an accept queue
        // nobody ever drains, so bytes written to it vanish without ever
        // reaching serveClient — an orphan half-channel the daemon's re-bind
        // cannot clear until it unlinks the path. Binding after the spawn means
        // no child ever sees the fd; the daemon's initial connect then retries
        // ({@see BackgroundSessionRunner::CONNECT_WAIT_SECONDS}) against the
        // small window where the child outruns the bind.
        $serverSocket = stream_socket_server(
            'unix://' . $socketPath,
            $errno,
            $errstr
        );
        if (!$serverSocket) {
            ProcessReaper::terminateAndClose($proc);
            $this->discardIpcFiles($socketPath, $bufferPath, $tokenPath, $logPath);
            throw new \RuntimeException("Failed to create IPC socket: {$errstr}");
        }

        // Wait for the child to connect AND prove it is the child: accept in a
        // bounded loop, authenticate every handshake, refuse the ones that do
        // not carry the token this spawn minted. The pre-M5 code trusted the
        // FIRST connection's word for its own pid; arrival order is not an
        // identity, and on a shared /tmp it was not even a race the honest
        // daemon reliably won.
        $childPid = 0;
        $authDeadline = microtime(true) + self::SPAWN_AUTH_TIMEOUT_SECS;
        while (true) {
            $remaining = $authDeadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $clientSocket = @stream_socket_accept($serverSocket, $remaining);
            if ($clientSocket === false) {
                // The accept itself timed out — no connection is coming.
                break;
            }

            stream_set_timeout($clientSocket, 2);
            $handshake = @fgets($clientSocket);
            fclose($clientSocket);

            $parsed = self::parseHandshake(is_string($handshake) ? $handshake : '');
            if ($parsed === null
                || $parsed['sessionId'] !== $sessionId
                || !hash_equals($token, $parsed['token'])
            ) {
                // Refused, and logged for the operator's forensics — with a
                // fixed string, never the attacker's bytes, and into the
                // 0600 sidecar rather than any emitter channel. Then keep
                // listening: one poisoned connect must not evict the real
                // daemon that is still starting up.
                @file_put_contents($logPath, "[session:auth:refused] spawn-handshake\n", FILE_APPEND);
                continue;
            }

            $childPid = $parsed['pid'];
            break;
        }

        if ($childPid === 0) {
            // BOUNDED, because this is the path where the launcher is by
            // definition misbehaving: it did not authenticate within five
            // seconds, so assuming it is about to exit is assuming away the
            // failure. A bare `proc_close()` here WAITS — MEASURED on this
            // host, against a child that ignores SIGTERM, `proc_terminate()` +
            // `proc_close()` returns only after the child's whole remaining
            // lifetime (7.77s for an 8s child), and with no signal at all it
            // waits indefinitely. A wedged launcher would therefore hang the
            // TUI thread that asked for a background session.
            ProcessReaper::terminateAndClose($proc);
            fclose($serverSocket);
            $this->discardIpcFiles($socketPath, $bufferPath, $tokenPath, $logPath);
            throw new \RuntimeException('Session process failed to authenticate on the IPC channel within timeout');
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

        // The authenticated handshake carries the DAEMON's pid.
        //
        // WHAT THIS COMMENT SAID: that the pid `proc_get_status()` reports
        // belongs to the `php -r` launcher, which exits during the daemon's
        // double fork. WHAT WAS TRUE: while the command above was a shell
        // STRING, it reported the `sh` wrapper instead — MEASURED, `comm` was
        // `(sh)` — because dash does not exec through `-c`. WHY IT STILL EARNS
        // ITS PLACE: the reasoning was right and the spawn is now an argv, so
        // the sentence describes the tree it always claimed to. The launcher IS
        // the direct child, it DOES exit during the double fork, and tracking it
        // would still make isProcessRunning() false immediately and have
        // reconnect() report every freshly spawned session as already Completed.
        // Hence the handshake pid — which since audit M5 is ALSO the whole
        // reason a handshake parse failure can no longer fall back to the
        // launcher pid: an unauthenticated number off the wire is exactly the
        // spoof the token check exists to refuse, so a connection that fails it
        // is dropped, not believed with less confidence.
        //
        // The pid then gets a fingerprint: the daemon's `/proc` start time,
        // read while the handshake's freshness guarantees it is that process.
        // {@see isProcessRunning()} compares it before ever answering "alive",
        // which is what makes a recycled pid read as DEAD rather than as the
        // session's own daemon.
        $startTime = self::procStartTime($childPid);

        // REAP THE LAUNCHER, EXPLICITLY — and never signal it.
        //
        // THE DOUBLE FORK IS INTENTIONAL AND IS NOT BEING REMOVED. A background
        // session must outlive the TUI that started it: that is the whole
        // feature. {@see buildSessionDaemonCode()} forks, `posix_setsid()`s and
        // forks again precisely so the daemon has no controlling terminal and is
        // reparented to init, and {@see reconnect()} exists to find those daemons
        // again in a LATER process. Signalling through this handle would
        // therefore be wrong twice over: it cannot reach the daemon (a
        // great-grandchild in another session), and if it landed early enough it
        // would kill the launcher mid-fork.
        //
        // What it CAN do is not leave the launcher unwaited. The happy path used
        // to call nothing at all here and relied, silently, on `$proc` leaving
        // scope: MEASURED on this host, the `proc_open()` resource destructor
        // reaps an already-exited child instantly (state `Z` -> `GONE`) and a
        // held handle shows the launcher sitting in `Z` until it does. That
        // worked, and it worked for a reason no reader of this method could see.
        // {@see \SugarCraft\Crush\Support\ProcessReaper::reapIfExited()} says it
        // out loud: wait briefly WITHOUT signalling, close if it exited, and
        // otherwise leave the handle to the destructor.
        ProcessReaper::reapIfExited($proc);

        $session = $session->withStatus(BackgroundSessionStatus::Running);

        $this->sessions[$sessionId] = $session;
        $this->sessionIpc[$sessionId] = [
            'socketPath' => $socketPath,
            'bufferPath' => $bufferPath,
            'tokenPath' => $tokenPath,
            'pid' => $childPid,
            'startTime' => $startTime,
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
     *
     * The config carries the token PATH, never the token: this string is argv
     * and argv is world-readable. And the generated umask is 0o077, not the
     * pre-M5 0 — the daemon re-binds the socket path as its own server and
     * keeps appending to the buffer, and every file it creates from here on
     * should be owner-only for its whole life, not merely inside a 0700
     * directory that a wider mode would keep leaning on.
     */
    public function buildSessionDaemonCode(
        string $socketPath,
        string $bufferPath,
        string $tokenPath,
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
            'tokenPath' => $tokenPath,
            'task' => $task,
            'workingDirectory' => $workingDirectory,
            'provider' => $provider,
            'model' => $model,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        return sprintf(
            '
umask(0o077);
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
     * Parse a `HELLO:<sessionId>:<pid>:<token>` handshake into its three
     * payload fields, or null when the line is anything else.
     *
     * Parse-don't-validate at the trust boundary: the caller receives either a
     * fully-shaped credential triple it can verify against the minted token, or
     * nothing. The pre-M5 three-field form (`HELLO:<sessionId>:<pid>`) parses to
     * null HERE, and is therefore unauthenticated — arrival of an un-trusted
     * credential is a refusal, never a lesser trust.
     *
     * @return array{sessionId: string, pid: int, token: string}|null
     */
    public static function parseHandshake(string $handshake): ?array
    {
        $handshake = trim($handshake);
        if (!str_starts_with($handshake, BackgroundSessionRunner::HANDSHAKE_PREFIX)) {
            return null;
        }

        // Exactly four colon-separated parts: neither sessionId (date + hex)
        // nor the pid nor a hex token can contain a colon, so a fifth part is
        // hostile shape, not data to be lenient about.
        $parts = explode(':', $handshake);
        if (count($parts) !== 4) {
            return null;
        }

        [, $sessionId, $pid, $token] = $parts;
        if ($sessionId === ''
            || !ctype_digit($pid)
            || (int) $pid <= 0
            || $token === ''
            || !ctype_xdigit($token)
        ) {
            return null;
        }

        return ['sessionId' => $sessionId, 'pid' => (int) $pid, 'token' => $token];
    }

    /**
     * The kernel start time (clock ticks since boot, `/proc/<pid>/stat` field
     * 22) of $pid, or null when procfs cannot answer.
     *
     * Together with the pid this is a process IDENTITY: a number the pid
     * allocator recycles does not come back with its predecessor's start time,
     * which is the spoof {@see isProcessRunning()} refuses. Field indexing
     * follows the {@see BackgroundSessionRunner} test precedent — fields 1-2
     * (pid, comm) sit before the state field, comm may contain spaces, so the
     * split starts after the LAST ')' and starttime is then rest[22-3].
     */
    public static function procStartTime(int $pid): ?int
    {
        if ($pid <= 0) {
            return null;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return null;
        }

        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }

        $rest = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        // state(3) .. starttime(22) means the slice needs at least 20 entries.
        if ($rest === false || count($rest) < 20 || !ctype_digit($rest[19])) {
            return null;
        }

        return (int) $rest[19];
    }

    /**
     * Create — once per supervisor process — the private 0700 directory every
     * session's IPC files live in, and refuse to continue if it is anything
     * other than what was asked for.
     *
     * The mode is produced by narrowing the umask around `mkdir` (the house
     * pattern: the directory is 0700 for its whole life, never 0777-then-
     * chmod'ed) and then VERIFIED off `lstat`, because this method's promise is
     * exactly the thing audit M5 was about — trusting a mode that was merely
     * requested. `lstat` rather than `stat`: a pre-planted symlink at the
     * random path must read as what it is and be refused, not followed.
     *
     * @throws \RuntimeException When the directory cannot be created or is not private.
     */
    private function ensurePrivateIpcDir(): string
    {
        if ($this->ipcDir !== '') {
            return $this->ipcDir;
        }

        $uid = function_exists('posix_getuid') ? posix_getuid() : (int) getmypid();
        $dir = sys_get_temp_dir() . '/sugar_crush_bg_' . $uid . '_' . bin2hex(random_bytes(8));

        $previous = umask(0o077);
        try {
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create private IPC directory {$dir}");
            }
        } finally {
            umask($previous);
        }

        clearstatcache(true, $dir);
        $stat = @lstat($dir);
        $isPrivateDir = $stat !== false
            && ($stat['mode'] & 0o170000) === 0o040000 // S_ISDIR on the lstat'd inode itself
            && ($stat['uid'] === $uid || !function_exists('posix_getuid'))
            && ($stat['mode'] & 0o777) === 0700;
        if (!$isPrivateDir) {
            throw new \RuntimeException("Private IPC directory {$dir} is not owner-private (0700) — refusing to spawn");
        }

        $this->ipcDir = $dir;

        return $dir;
    }

    /**
     * Drop ONE session's IPC files after a failed spawn.
     *
     * Deliberately does NOT remove the directory: it is per-process, shared by
     * every session this supervisor spawns, and a failed spawn has no
     * authority over its siblings — an rmdir here would race live sessions and
     * force re-creation for later ones.
     *
     * @param string ...$paths socketPath, bufferPath, tokenPath, logPath
     */
    private function discardIpcFiles(string ...$paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
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
        if ($ipc === null || $ipc['pid'] <= 0 || $this->isProcessRunning($ipc['pid'], $ipc['startTime'] ?? null)) {
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
                $childRunning = $this->isProcessRunning($ipc['pid'], $ipc['startTime'] ?? null);

                if ($childRunning && file_exists($ipc['socketPath'])) {
                    // Child is still running — authenticate, then send RESUME
                    // over IPC. The daemon's command loop refuses every
                    // connection that does not open with the token line
                    // ({@see BackgroundSessionRunner::serveClient()}), so an
                    // unauthenticated reconnect simply gets nothing back —
                    // the same shape as a dead socket, and the right shape for
                    // a stranger. The token lives in a 0600 file only this
                    // (owner-verified) process can read.
                    $token = isset($ipc['tokenPath'])
                        ? trim((string) @file_get_contents($ipc['tokenPath']))
                        : '';

                    $supervisor = @stream_socket_client(
                        'unix://' . $ipc['socketPath'],
                        $errno,
                        $errstr,
                        1 // 1 second timeout
                    );

                    if ($supervisor !== false) {
                        stream_set_timeout($supervisor, 1);
                        if ($token !== '') {
                            fwrite($supervisor, BackgroundSessionRunner::AUTH_PREFIX . $token . "\n");
                        }
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
     * Check if the recorded daemon is still the process its pid named at spawn.
     *
     * A bare `posix_kill($pid, 0)` answers "does SOMETHING hold this number",
     * and the kernel recycles numbers — audit M5's forged-status path. When a
     * start-time fingerprint was captured at the authenticated handshake, the
     * identity must match before liveness counts at all.
     *
     * If procfs cannot answer, this falls through to signal 0 rather than
     * declaring death: a false "running" only delays reaping (the stall ticker
     * still bounds it), while a false "dead" would settle a live session as
     * Completed and stop its user from ever seeing the answer. On Linux with
     * /proc mounted — the only shape this suite's platform tripwire says CI
     * runs — the fingerprint path is the one that executes.
     */
    private function isProcessRunning(int $pid, ?int $startTime = null): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if ($startTime !== null) {
            $observed = self::procStartTime($pid);
            if ($observed !== null) {
                return $observed === $startTime;
            }
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
