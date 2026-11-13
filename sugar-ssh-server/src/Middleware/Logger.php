<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Middleware;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;

/**
 * Connect / disconnect logger.
 *
 * Writes a one-line JSON record at session start and another at
 * session end (with elapsed seconds).
 *
 * **Where the lines land, and who can read them.** The obvious
 * target — the process's error stream — is NOT a safe operator
 * sink on the transport this library exists for. Under
 * `ForceCommand`, sshd forwards the child's fd 1 AND fd 2 to the
 * CONNECTING CLIENT as the session's data and extended-data
 * channels: `new Logger()` with no argument prints every session
 * record into the user's terminal and gives the operator nothing
 * to keep. (The `Auth`, `KeyboardInteractive`, `CertificateAuth`
 * and `RateLimit` notices have the same shape for the same
 * reason.) journald only captures what *sshd itself* writes, not
 * what its forced command writes.
 *
 * So the default is resolved rather than assumed:
 *
 * - `Logger::auto()` (what the no-argument constructor calls)
 *   reads `SSH_SERVER_LOG` from the environment and appends to
 *   that file when it is set — a directory the service user owns,
 *   provisioned once (see `docs/admin/sugar-ssh-server.md`).
 * - With nothing configured it falls back to `php://stderr`,
 *   which is the right answer for a plain CLI/tooling run and the
 *   wrong one for a live `sshd` panel. `target()` reports which
 *   one you got; it is worth logging at boot.
 * - `Logger::toFile($path)` states the intent. A log file this
 *   constructor CREATES is chmod-ed to `0600` (mode comes from us,
 *   not from the ambient umask, because the records carry
 *   usernames and client addresses); a file that already exists is
 *   left at its current mode so a deliberate group-readable
 *   rotation setup survives.
 * - `Logger::toStream($resource)` hands the lines to something the
 *   operator controls — a pipe into the daemon's own logger, an
 *   already-open journald socket handle, a test buffer.
 *
 * `ext-curl`/HTTP shipping is out of scope here: whatever the sink,
 * one line per event, appended, flushed by the stream layer.
 */
final class Logger implements Middleware
{
    /**
     * Environment variable `Logger::auto()` consults for an
     * operator-owned log path. Deliberately not a name sshd
     * exports: this is the deployment's own configuration, read
     * from the service environment (systemd `Environment=`).
     */
    public const LOG_PATH_ENV = 'SSH_SERVER_LOG';

    /**
     * Deliberately left as a docblock-typed property: PHP has no
     * `resource` type (a native `mixed` would state less), and the
     * sink-release paths probe it with isset()/is_resource() from
     * reconfiguration code that can run before any handle was stored -
     * a typed-but-uninitialised property would raise there instead of
     * falling through. (strict-typing sweep: the docblock stays the
     * only faithful type for a resource handle)
     *
     * @var resource
     */
    private $stream;
    private bool $owns = false;
    private string $describedAs = 'stream';

    /**
     * @param resource|string|null $target File path, open resource,
     *                                     or null to resolve with
     *                                     `Logger::auto()`
     *                                     (`LOG_PATH_ENV`, then
     *                                     `php://stderr`).
     */
    public function __construct($target = null)
    {
        if ($target === null) {
            $configured = getenv(self::LOG_PATH_ENV);
            if (is_string($configured) && $configured !== '') {
                $this->openFile($configured);
                return;
            }
            $this->openStderr();
            return;
        }
        if (is_string($target)) {
            $this->openFile($target);
            return;
        }
        if (!is_resource($target)) {
            throw new \InvalidArgumentException(Lang::t('logger.invalid_target'));
        }
        $this->stream = $target;
        $this->describedAs = 'stream';
    }

    /**
     * Resolve the sink from the deployment environment: the
     * configured `SSH_SERVER_LOG` file when present, stderr
     * otherwise. Prefer this over `new Logger()` in boot code so
     * the resolution is visible at the call site.
     */
    public static function auto(): self
    {
        return new self();
    }

    /** Append to `$path`, creating it `0600` when it does not exist. */
    public static function toFile(string $path): self
    {
        return new self($path);
    }

    /**
     * Write to an already-open resource the caller keeps (a pipe
     * into the daemon's logger, a test buffer). Not closed by this
     * middleware.
     *
     * @param resource $stream
     */
    public static function toStream($stream): self
    {
        return new self($stream);
    }

    /**
     * Explicitly choose the process error stream. Under
     * `ForceCommand` that is the connecting client's extended-data
     * channel — use it only where that is what you want (a local
     * run, a tool, a test).
     */
    public static function toStderr(): self
    {
        $logger = new self();
        $logger->openStderr();
        return $logger;
    }

    /**
     * Which sink this instance resolved to, as a short
     * machine-readable description: `file:<path>`, `stderr`, or
     * `stream`. The client-visible hazard of `stderr` is why this
     * is public.
     */
    public function target(): string
    {
        return $this->describedAs;
    }

    private function openStderr(): void
    {
        // `toStderr()` resolves through the constructor first (which
        // may have opened the configured file); release that handle
        // rather than orphaning it.
        if ($this->owns && isset($this->stream) && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $stream = fopen('php://stderr', 'a');
        if ($stream === false) {
            throw new \RuntimeException(Lang::t('middleware.cannot_open_stderr'));
        }
        $this->stream = $stream;
        $this->owns = true;
        $this->describedAs = 'stderr';
    }

    private function openFile(string $path): void
    {
        $directory = dirname($path);
        $existed = is_file($path);
        if (!$existed && !is_dir($directory)) {
            // The audit case that matters: a missing directory used
            // to throw here, and the fatal trace travelled on the
            // same channel as the log it failed to write.
            @mkdir($directory, 0700, true);
        }
        $stream = @fopen($path, 'a');
        if ($stream === false) {
            throw new \RuntimeException(Lang::t('logger.cannot_open_target', ['target' => $path]));
        }
        if (!$existed) {
            @chmod($path, 0600);
        }
        $this->stream = $stream;
        $this->owns = true;
        $this->describedAs = 'file:' . $path;
    }

    public function __destruct()
    {
        if ($this->owns && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        $start = microtime(true);
        $this->write([
            'event' => 'session.start',
            'ts'    => date('c'),
            ...$session->toLogContext(),
        ]);

        try {
            $next($ctx, $session);
        } finally {
            $this->write([
                'event'  => 'session.end',
                'ts'     => date('c'),
                'elapsed_s' => round(microtime(true) - $start, 3),
                ...$session->toLogContext(),
            ]);
        }
    }

    /**
     * Append one record, whatever it contains.
     *
     * The naive shape here - encode, and `return` when the encoder says no -
     * was audit MAJOR 3's third finding. `json_encode()` refuses the WHOLE
     * document on a single malformed UTF-8 byte, and the fields this row
     * carries are mostly peer-supplied strings: `TERM`,
     * `SSH_ORIGINAL_COMMAND`, a username a wrapper exported. So one odd byte
     * from a strange client did not degrade a log line, it deleted it,
     * silently, from the only record that a session ever happened.
     *
     * Refusal is therefore retried with substitution on and the
     * non-encodable containers flattened; the reason lands in the line as
     * `json_error` so a reader knows the row was recovered rather than
     * trusted. Only a document that still cannot be encoded degrades to a
     * one-word row. Nothing about a session is allowed to be unrecordable.
     *
     * @param array<string,mixed> $record
     */
    private function write(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = json_encode(
                ['json_error' => json_last_error_msg()] + self::scalarsOnly($record),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        }
        if ($line === false) {
            $line = '{"event":"unencodable_record"}';
        }
        fwrite($this->stream, $line . "\n");
    }

    /**
     * Keep the fields a line can hold, and name the ones it cannot.
     *
     * Nested arrays and objects are what usually break an encoder on a
     * recovery path (a caller passed a deeper structure with the bad byte
     * inside), so they are replaced by their type rather than walked into:
     * this is a fallback, not a second serialiser. Strings survive here even
     * when they are not valid UTF-8, because the retry that uses them turns
     * substitution on.
     *
     * @param array<string,mixed> $record
     * @return array<string,null|bool|float|int|string>
     */
    private static function scalarsOnly(array $record): array
    {
        $flat = [];

        foreach ($record as $key => $value) {
            $name = (string) $key;

            if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $flat[$name] = $value;
                continue;
            }

            $flat[$name] = get_debug_type($value);
        }

        return $flat;
    }
}
