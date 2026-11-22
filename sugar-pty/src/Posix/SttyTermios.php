<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use RuntimeException;
use SugarCraft\Pty\Contract\Termios;

/**
 * stty shell-out fallback for termios when ext-ffi is unavailable.
 *
 * Operates on a raw file descriptor by aliasing it via `php://fd/<n>` and
 * handing it to stty(1) as the CHILD'S STDIN — stty with no `-f` flag
 * reads and writes the terminal on its standard input, which is the same
 * spelling on every POSIX (GNU and BSD both).
 *
 * Why not `stty -f /dev/fd/<n>`: an fd number is private to this process,
 * and every master this lib mints carries FD_CLOEXEC. The exec'd stty
 * loses that descriptor at exec time, `/dev/fd/<n>` resolves against the
 * CHILD's (now empty) table, and the fallback fails on exactly the fds it
 * exists to serve. `php://fd/<n>` opens the alias HERE — dup() strips the
 * close-on-exec flag on the new descriptor — and the child receives it as
 * fd 0, which stty then uses. (Same reasoning as
 * {@see \SugarCraft\Pty\SizeIoctl::withDupAlias()} for the resize path.)
 *
 * Mirrors portable-pty.Termios
 */
final class SttyTermios implements Termios
{
    public const TCSANOW = 0;
    public const TCSADRAIN = 1;
    public const TCSAFLUSH = 2;

    /**
     * Wall-clock bound on one stty(1) run, output collection included. stty
     * answers in milliseconds, so a run still going after this is wedged; it
     * is killed and reported as a failure instead of hanging the caller.
     */
    public const DEFAULT_TIMEOUT_SEC = 5.0;

    /** The bound in force; a private seam so a test can shorten the wedge case. */
    private static float $timeoutSec = self::DEFAULT_TIMEOUT_SEC;

    private int $fd;
    private string $savedMode;
    private bool $raw = false;

    public function __construct(int $fd)
    {
        $this->fd = $fd;
        $this->savedMode = '';
    }

    /**
     * Capture the current mode as a NEW instance holding the saved
     * settings string — restore() belongs to the snapshot, not to the
     * handle it was captured from (mirrors
     * {@see \SugarCraft\Pty\Posix\PosixTermios::current()}).
     *
     * @see portable-pty.Termios.Current()
     */
    public function current(): self
    {
        $out = $this->runStty('-g');
        $instance = new self($this->fd);
        $instance->savedMode = \trim($out);
        return $instance;
    }

    /**
     * @see portable-pty.Termios.MakeRaw()
     */
    public function makeRaw(): self
    {
        $clone = clone $this;
        $clone->raw = true;
        return $clone;
    }

    /**
     * Apply the (possibly raw) mode.
     *
     * `$when` is accepted for {@see Termios} contract parity only:
     * stty(1) has no NOW/DRAIN/FLUSH variants, so every mode arrives as
     * an immediate tcsetattr. Callers needing a true drain-before-set
     * should write the termios through the FFI-backed PosixTermios.
     *
     * @see portable-pty.Termios.Apply()
     */
    public function apply(int $when = self::TCSANOW): void
    {
        if (!$this->raw) {
            return;
        }
        $this->runStty('raw', '-echo');
    }

    /**
     * Write back the mode captured by {@see current()}.
     *
     * @throws RuntimeException if no snapshot was captured on this
     *                          instance — restoring an empty mode string
     *                          would silently do nothing, which is worse
     *                          than telling the caller the truth.
     *
     * @see portable-pty.Termios.Restore()
     */
    public function restore(): void
    {
        if ($this->savedMode === '') {
            throw new RuntimeException(
                'SttyTermios::restore() called without a captured snapshot; '
                . 'call current() and restore() the returned instance.'
            );
        }
        // PTY-30: replay the captured `-g` string as ONE argv token —
        // array-form proc_open passes it verbatim, no shell involved.
        // Whitespace-splitting murdered the BSD/macOS form (its
        // `baud;`, `line =`, ';'-suffixed and multi-line fields became
        // nonsense settings); glibc's single colon-grouped token is
        // unaffected either way, so one path serves both vendors.
        $this->runStty($this->savedMode);
    }

    /**
     * @see portable-pty.Termios.IsTty()
     */
    public function isAtty(): bool
    {
        if (\function_exists('posix_isatty')) {
            return \posix_isatty($this->fd);
        }
        // No ext-posix: ask stty(1) — the very backend this class is.
        // `stty size` succeeds only on a terminal; a probe failure
        // answers false as evidence, never as a silent floor.
        try {
            return $this->runStty('size') !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    public function fd(): int
    {
        return $this->fd;
    }

    /**
     * Run stty with the given arguments against this fd (as the child's
     * stdin) and capture stdout. Both output pipes are drained together and
     * the run is bounded by {@see DEFAULT_TIMEOUT_SEC}.
     *
     * @param string ...$args stty subcommand and flags
     * @return string stdout content
     * @throws RuntimeException if the fd cannot be aliased, stty exits non-zero,
     *                          or stty is still running at the bound (it is killed)
     */
    private function runStty(string ...$args): string
    {
        // 'c+' = read/write without ever creating or truncating; the open
        // dup()s $this->fd and the duplicate is NOT close-on-exec, which
        // is the entire point (see class docblock).
        $terminal = @\fopen('php://fd/' . $this->fd, 'c+');
        if ($terminal === false) {
            throw new RuntimeException(
                'stty ' . \implode(' ', $args) . " failed: cannot alias fd {$this->fd} via php://fd"
            );
        }

        $cmd = ['stty', ...$args];
        $desc = [
            0 => $terminal,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes);

        // The child holds its own reference; ours is no longer needed.
        \fclose($terminal);

        if (!\is_resource($proc)) {
            throw new RuntimeException('stty ' . \implode(' ', $args) . ' failed: proc_open returned false');
        }

        [$out, $err, $timedOut] = self::collect($pipes[1], $pipes[2], \microtime(true) + self::$timeoutSec);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        if ($timedOut) {
            \proc_terminate($proc, 9);
        }
        $rc = \proc_close($proc);

        if ($timedOut) {
            throw new RuntimeException(
                'stty ' . \implode(' ', $args) . ' failed: no exit within '
                . self::$timeoutSec . 's (killed)'
            );
        }
        if ($rc !== 0) {
            throw new RuntimeException(
                'stty ' . \implode(' ', $args) . ' failed with rc=' . $rc . ': ' . \trim($err)
            );
        }

        return $out;
    }

    /**
     * Read stdout and stderr TOGETHER until both reach EOF or the deadline
     * passes (AUD §2 #59). Reading stdout to EOF first deadlocked as soon
     * as stty wrote more to stderr than the pipe buffer holds: stty blocked
     * on the full stderr pipe and never closed stdout, while this side
     * blocked waiting for that stdout EOF.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @return array{0: string, 1: string, 2: bool} [stdout, stderr, timed out]
     */
    private static function collect($stdout, $stderr, float $deadlineAt): array
    {
        \stream_set_blocking($stdout, false);
        \stream_set_blocking($stderr, false);
        $open = [1 => $stdout, 2 => $stderr];
        $buf = [1 => '', 2 => ''];

        while ($open !== []) {
            $leftUs = (int) (($deadlineAt - \microtime(true)) * 1_000_000);
            if ($leftUs <= 0) {
                return [$buf[1], $buf[2], true];
            }
            $read = \array_values($open);
            $write = null;
            $except = null;
            $ready = PosixMasterPty::retryOnEintr($read, $write, $except, 0, \min($leftUs, 100_000));
            if ($ready === false) {
                // A select failure on our own pipes is not recoverable by
                // waiting; treat it like a wedged run so the child is reaped.
                return [$buf[1], $buf[2], true];
            }
            if ($ready === 0) {
                continue;
            }
            foreach ($open as $n => $pipe) {
                if (!\in_array($pipe, $read, true)) {
                    continue;
                }
                $chunk = @\fread($pipe, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buf[$n] .= $chunk;
                } elseif (\feof($pipe)) {
                    unset($open[$n]);
                }
            }
        }

        return [$buf[1], $buf[2], false];
    }
}
