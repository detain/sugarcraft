<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Events\ToolFinished;
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
     * Record prefix for a tool call this session was not allowed to make
     * (E241).
     *
     * `[session:` FIRST, and that is load-bearing rather than cosmetic:
     * {@see BackgroundSupervisor::restoreOutput()} treats every line with that
     * opening as the daemon's own bookkeeping and drops it, so a refusal
     * record cannot be quoted back to the user as if the MODEL had written it.
     * `tool:` AND NOT `task:`, and the honest version of why. WHAT IS TRUE:
     * {@see BackgroundSupervisor::bufferReportsFailure()} reads the LAST
     * `[session:task:` line in the buffer and settles the session on the word
     * it finds there, and `refused` is not one of the two completion words.
     * WHAT IS NOT TRUE, and was written here first: that naming this record
     * `[session:task:refused]` would therefore fail every such session.
     * MEASURED — the record renamed into the `task:` namespace leaves the
     * whole suite green, because {@see self::executeTask()} always writes its
     * outcome line AFTER any event the turn raised, so the outcome line is
     * still the last one. WHY THE SEPARATION STILL EARNS ITS PLACE: ordering
     * is the ONLY thing holding those two apart, and ordering is what a future
     * edit changes — a refusal delivered late (a replayed event, a second turn
     * in one session, an observer that fires on the way out) lands after the
     * outcome and silently converts a completed session into a failed one.
     * Keeping the record out of the namespace the outcome parser reads makes
     * that unreachable rather than merely unlikely, and
     * {@see \SugarCraft\Crush\Tests\Sessions\BackgroundSessionRunnerTest}
     * pins the separation directly rather than through an ordering that
     * happens to hide it.
     */
    public const REFUSAL_RECORD = '[session:tool:refused]';

    /**
     * How often the daemon stamps a heartbeat record into the buffer file.
     * Must stay comfortably under
     * {@see BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS} or a healthy
     * session would be reported as stalled.
     */
    public const HEARTBEAT_INTERVAL_SECS = 5;

    /** Seconds a single accept() may block before the worker is re-checked. */
    private const ACCEPT_TIMEOUT_SECS = 0.5;

    /**
     * How long {@see stopWorker()} gives SIGTERM before it sends signal 9.
     *
     * The point of asking first is to let the worker finish the buffer line it
     * is mid-write on: a SIGKILLed worker leaves the answer truncated, and a
     * `/stop` is a user deciding they have seen enough, not a user asking for
     * the transcript to be corrupted.
     *
     * Two seconds and not more, because in practice this grace is never spent:
     * the worker is {@see run()}'s `exit($this->executeTask($backend))` fork
     * and installs no SIGTERM handler at all, so it dies on the default
     * disposition immediately. The window is defensive — it covers a backend
     * or a tool that has installed one — and on the `/stop` path there is a
     * user watching, for whom every second of it is a stall.
     *
     * Public for the same reason {@see HEARTBEAT_INTERVAL_SECS} is: a test
     * asserts that the ordinary worker is reaped INSIDE this window rather
     * than paying it, and a literal on the test side would stop tracking the
     * constant the first time it moved.
     */
    public const TERMINATE_GRACE_SECONDS = 2.0;

    /** How long signal 9 gets before the daemon exits without having reaped. */
    private const KILL_GRACE_SECONDS = 2.0;

    /** How often {@see reapWithin()} re-asks with `WNOHANG`. */
    private const REAP_POLL_MICROSECONDS = 10_000;

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
            $this->exitWorker($this->executeTask($backend));
        }
        if ($worker < 0) {
            $this->log('[session:fork:error] could not fork task worker');

            return 1;
        }

        return $this->supervise($worker);
    }

    /**
     * Leave the forked worker with $code, and without republishing whatever
     * the parent had buffered (E229).
     *
     * WHY NOT {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()},
     * WHICH IS THIS CODEBASE'S ANSWER FOR EVERY OTHER FORKED CHILD. Because
     * the exit CODE is this fork's whole protocol and `exitNow()` throws it
     * away: it leaves through `posix_kill(getmypid(), SIGKILL)`, so the
     * worker is SIGNALLED rather than exited, `pcntl_wifexited()` in
     * {@see self::supervise()} is false, and `$result` becomes `failed`.
     *
     * MEASURED rather than reasoned about, PHP 8.3.6, driving the real
     * `run()` against a real unix socket server in a plain `php` subprocess
     * with an `ob_start()` open in the parent:
     *
     *  - plain `exit($code)` — the parent's buffered marker is printed TWICE,
     *    and `run()` returns 0 (the session settles Completed);
     *  - `ForkedChild::exitNow($code)` — the marker is printed ONCE, and
     *    `run()` returns 1. A turn that succeeded is reported as a failed
     *    session, on every background session there is.
     *
     * So the obvious conversion is not a fix here, it is a swap of a
     * harness-only defect for a user-visible one. What IS safe is to drop the
     * inherited buffers and keep the ordinary exit: the code survives, and the
     * one consequence of a plain exit that nothing else in this tree defuses
     * goes away. (The other two are defused already, and neither is reachable
     * from this particular fork anyway: candy-core's `PosixBackend::restore()`
     * is PID-aware, and the daemon parent has built no backend — hence no MCP
     * client, no loop watcher — at the moment it forks, because
     * {@see self::executeTask()} builds all of that in the CHILD.)
     *
     * A NO-OP IN PRODUCTION, deliberately. The daemon runs no output
     * buffering, so `ob_get_level()` is 0 and this is one function call. It
     * earns its place in-process: the moment anything drives `run()` past the
     * handshake inside PHPUnit — which
     * {@see \SugarCraft\Crush\Tests\Sessions\BackgroundSessionRunnerTest}
     * now does, in a subprocess — `TestCase::runBare()`'s open buffer is
     * inherited by this worker and flushed a second time at its shutdown.
     *
     * The loop breaks on a buffer that refuses to close rather than spinning
     * on it: an unremovable handler is a reason to leave anyway, not a reason
     * never to leave.
     */
    private function exitWorker(int $code): never
    {
        while (\ob_get_level() > 0) {
            if (!@\ob_end_clean()) {
                break;
            }
        }

        exit($code);
    }

    /**
     * Run one agent turn for this session's task, appending the answer to the
     * buffer file the supervisor restores output from.
     *
     * Streamed tokens are flushed a whole line at a time so a reconnecting
     * TUI sees partial output while the turn is still running.
     *
     * THE THIRD ARGUMENT IS THE ONE E241 EXISTS FOR. Without it a hook DENY
     * inside a background session reached the operator on no channel at all —
     * the same gap E219 closed for `-p`, on the surface where it is worst.
     * {@see self::noticeRefusal()} carries the measurement of where the line
     * had to go.
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
            $message = $backend->complete(
                [Message::user($this->task)],
                $onToken,
                function (object $event): void {
                    $this->noticeRefusal($event);
                },
            );
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
     * Record, in the session buffer, that a tool call was stopped (E241).
     *
     * THE GAP. {@see \SugarCraft\Crush\Cli\NonInteractive::noticeRefusal()}
     * closed this for the `-p` one-shot path in round 48. The daemon is the
     * OTHER headless caller and it was left out: `executeTask()` called
     * `complete()` with a token callback and no `$onEvent` at all, so a hook
     * DENY here produced the answer the model wrote around the missing tool
     * and nothing, anywhere, saying a call had been stopped. It is the worse
     * of the two surfaces, because a background session has no operator
     * watching it happen — the buffer file IS the whole record.
     *
     * WHY NOT `fwrite(\STDERR, …)`, WHICH IS THE SHAPE E219 USED AND WHICH
     * E241 EXPECTED TO WORK HERE. It would in fact land somewhere readable —
     * but somewhere ELSE, and that is the objection. MEASURED at
     * {@see BackgroundSupervisor::spawnSession()}: the daemon is opened with
     * `[['file', '/dev/null', 'r'], ['file', $logPath, 'a'],
     * ['file', $logPath, 'a']]` where `$logPath` is `$bufferPath . '.log'` — a
     * SIDECAR, deliberately not the buffer, so a stray provider warning is
     * never quoted back as model output. Descriptor 2 is therefore a second
     * file, and a refusal written there would sit in one place while every
     * other thing this class says about the turn — `[session:task:start]`,
     * `[session:provider:fallback]`, `[session:task:failed]` — sits in the
     * buffer. Two files to read to reconstruct one turn is the observability
     * gap re-opened at a different address.
     *
     * WHAT IS ON STDERR ANYWAY, said plainly so this reads as a choice rather
     * than an oversight: an ASK refused with no terminal already writes
     * "sugarcrush: refused <tool>." from
     * {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt::__invoke()},
     * into that sidecar. So the ASK case produces two records in two files and
     * the DENY case — which reaches no approver at all — produces exactly this
     * one. Suppressing either would need this class to know what some approver
     * built four frames away inside {@see Bootstrap::backend()} had already
     * announced, which is a coupling that does not exist.
     *
     * `oneLine()` IS NOT COSMETIC HERE. The buffer is a line protocol:
     * {@see BackgroundSupervisor::restoreOutput()} decides line by line, and
     * only a line that STARTS with `[session:` is dropped. A refusal reason
     * carrying a newline would therefore have its first line skipped and every
     * continuation line restored as model output — a hook author's error text
     * injected into the transcript. The same collapse is applied to every
     * other free text this class logs, for the same reason.
     *
     * THE CLASSIFIER IS DUPLICATED AND THE ROSTER IS NOT, which is the half
     * that matters. {@see Chat::DENIED_ERROR_PREFIXES} is read rather than
     * copied, so this daemon and the `-p` path and the TUI's struck-through
     * refusal state cannot disagree about what a refusal IS. The twelve lines
     * of `str_starts_with` around it are duplicated from
     * `NonInteractive::refusalFrom()`, which is private and in another file;
     * hoisting them to a shared owner is worth doing and is recorded, but a
     * shared classifier reading a shared roster and two classifiers reading
     * one shared roster fail in the same way — which is to say they do not.
     *
     * {@see Chat} IS TOUCHED LAZILY, on purpose and for the same reason the
     * `-p` path does it: a class constant is still a class load, and the guard
     * above is "an errored tool result", so a turn that errors nothing never
     * loads it.
     */
    private function noticeRefusal(object $event): void
    {
        if (!$event instanceof ToolFinished || !$event->result->isError()) {
            return;
        }

        $reason = $event->result->content();
        foreach (Chat::DENIED_ERROR_PREFIXES as $prefix) {
            if (!\str_starts_with($reason, $prefix)) {
                continue;
            }

            $this->log(
                self::REFUSAL_RECORD . ' ' . $this->oneLine($event->toolName)
                . ' was not run - ' . $this->oneLine($reason),
            );

            return;
        }
    }

    /**
     * Resolve the backend this session's agent should run on.
     *
     * Falls back to {@see Bootstrap::backend()}'s own env-driven selection
     * when the agent names a provider that cannot be constructed, so an
     * unknown provider degrades to the offline engine rather than killing
     * the session.
     *
     * BOTH ROUTES ASK FOR THE CONSOLE APPROVER
     * ({@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}), and the
     * reason is the opposite of the one it reads like. This is a daemon with
     * no user in front of it, so the point is not to PROMPT — it is that the
     * approver's no-terminal branch produces a refusal naming the tool, the
     * mode and the two things that change the outcome, written to the
     * session's sidecar log, where a no-approver refusal would say only
     * "permission required and no approver is attached to this run"
     * ({@see \SugarCraft\Crush\Runtime::settleAsk()}). Same verdict, a
     * reader who can act on it.
     *
     * IT CANNOT STEAL KEYSTROKES, and that is a property of the spawn rather
     * than of this class: {@see BackgroundSupervisor::spawnSession()} opens the
     * daemon with `['file', '/dev/null', 'r']` as descriptor 0, so fd 0 is
     * `/dev/null` BEFORE `buildSessionDaemonCode()`'s double fork ever runs
     * and `stream_isatty(STDIN)` in here is false (measured:
     * `php -r 'var_dump(stream_isatty(STDIN));' < /dev/null` => `bool(false)`).
     * The `posix_setsid()` in the daemon bootstrap is NOT what decides this —
     * detaching from a controlling terminal does not change the `isatty`
     * answer for an already-open descriptor — the redirection at the spawn
     * site is. Attach the same prompt to a run whose stdin IS a terminal and
     * it asks there, which is the behaviour that path wants anyway; the probe
     * is the single source of truth either way.
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
                return Bootstrap::backendFor($this->provider, $root, null, null, true);
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

        return Bootstrap::backend($root, null, null, true);
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
                $this->stopWorker($worker);
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
                $this->stopWorker($worker);
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

    /**
     * Ask the worker to stop, then MAKE it stop.
     *
     * Both callers used to be `posix_kill(SIGTERM)` followed by an unflagged
     * `pcntl_waitpid()`, and a worker that traps or ignores TERM parks the
     * supervisor in that wait for good. MEASURED on this host: a forked child
     * that installs an empty `SIGTERM` handler and then loops leaves
     * `pcntl_waitpid($pid, $status)` unreturned — `timeout 5 php` exited 124.
     *
     * The damage is not confined to the daemon. The supervisor's completion
     * signal is a DEAD PID
     * ({@see BackgroundSupervisor::reapFinishedDaemon()}), so a supervise()
     * that never returns means the session reports "running" forever, the unix
     * socket at {@see $socketPath} is never unlinked by the tail of that
     * method, and both processes leak for the life of the machine. A `/stop`
     * that leaves the session running is the whole feature failing at the one
     * thing the user asked it to do.
     *
     * Escalation, not force: SIGTERM, a bounded `WNOHANG` window, then signal
     * 9. SIGNAL 9 AS AN INTEGER LITERAL, never the `SIGKILL` constant — that
     * constant is ext-pcntl's, and while this method is only reached from
     * inside the `function_exists('pcntl_fork')` gate in {@see run()}, naming
     * the constant here would make the shape wrong to copy. Same literal, and
     * the same reason, as
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()} and
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::terminateAndReap()}.
     *
     * `\SIGTERM` IS NAMED PLAINLY, and that is the one story this file tells
     * about ext-pcntl rather than two. It was written `\defined('SIGTERM') ?
     * \SIGTERM : 15`, two lines above a `\pcntl_waitpid(..., \WNOHANG)` that is
     * not guarded at all — and the two cannot both be right. The gate at
     * {@see run()} requires `pcntl_fork` AND `pcntl_waitpid` before anything
     * here is reachable, and `SIGTERM` comes from the same extension as both,
     * so either the gate holds and the ternary is unreachable, or it does not
     * and `\WNOHANG` fatals first. The gate holds; the ternary was decoration
     * that made the file look as if it doubted its own precondition. What is
     * genuinely uncertain is ext-POSIX, which is separately compilable — and
     * that doubt is expressed once, where it is real, in
     * {@see signalWorker()}.
     *
     * If signal 9 is also unreaped — an uninterruptible kernel wait, or a
     * build with no ext-posix to signal with at all — the daemon EXITS ANYWAY
     * and records that it did. That leaves an orphan, which is worse than a
     * reap and much better than the alternative: the supervisor sees the dead
     * daemon, settles the session, and unlinks nothing it did not create.
     */
    private function stopWorker(int $worker): void
    {
        $this->signalWorker($worker, \SIGTERM);

        if ($this->reapWithin($worker, self::TERMINATE_GRACE_SECONDS)) {
            return;
        }

        $this->log('[session:task:escalate] worker did not stop on SIGTERM pid=' . $worker);
        $this->signalWorker($worker, 9);

        if ($this->reapWithin($worker, self::KILL_GRACE_SECONDS)) {
            return;
        }

        $this->log('[session:task:unreaped] worker survived signal 9 pid=' . $worker);
    }

    /**
     * Send one signal to the worker, or do nothing at all.
     *
     * `posix_kill()` is ext-posix while the fork above is ext-pcntl, and the
     * two are separately compilable. In a build with only the latter there is
     * nothing to signal the worker WITH — which is exactly the build in which
     * an unflagged wait would hang forever, so the guard and the bounded reap
     * are the same fix seen from two sides.
     */
    private function signalWorker(int $worker, int $signal): void
    {
        if (\function_exists('posix_kill')) {
            @\posix_kill($worker, $signal);
        }
    }

    /**
     * Collect the worker over a bounded `WNOHANG` window; true if it was
     * reaped.
     *
     * Never an unflagged `pcntl_waitpid()`, for the reason
     * {@see \SugarCraft\Crush\Runtime::reapKilled()} gives at its own call
     * site: the wait is only bounded if the signal landed, and whether it
     * landed is not something this process can assume.
     */
    private function reapWithin(int $worker, float $seconds): bool
    {
        $deadline = \microtime(true) + $seconds;
        $status = 0;

        while (true) {
            // 0 is "still running"; the pid is "reaped"; -1 is "not ours any
            // more" — and only the first of those is worth waiting on.
            if (\pcntl_waitpid($worker, $status, \WNOHANG) !== 0) {
                return true;
            }

            if (\microtime(true) >= $deadline) {
                return false;
            }

            \usleep(self::REAP_POLL_MICROSECONDS);
        }
    }

    /** Collapse a message to one line so it cannot corrupt the buffer's line protocol. */
    private function oneLine(string $text): string
    {
        return \trim(\preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
