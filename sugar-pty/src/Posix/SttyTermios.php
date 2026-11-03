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
        // stty -g emits a saved mode string. On glibc it is a single
        // colon-grouped token; on BSD/macOS it is space-separated fields.
        // Split on whitespace so each token becomes a separate argv element.
        $args = \preg_split('/\s+/', \trim($this->savedMode), -1, PREG_SPLIT_NO_EMPTY);
        $this->runStty(...$args);
    }

    /**
     * @see portable-pty.Termios.IsTty()
     */
    public function isAtty(): bool
    {
        if (\function_exists('posix_isatty')) {
            return \posix_isatty($this->fd);
        }
        return false;
    }

    public function fd(): int
    {
        return $this->fd;
    }

    /**
     * Run stty with the given arguments against this fd (as the child's
     * stdin) and capture stdout.
     *
     * @param string ...$args stty subcommand and flags
     * @return string stdout content
     * @throws RuntimeException if the fd cannot be aliased or stty exits non-zero
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

        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);

        if ($rc !== 0) {
            throw new RuntimeException(
                'stty ' . \implode(' ', $args) . ' failed with rc=' . $rc . ': ' . \trim($err)
            );
        }

        return $out ?? '';
    }
}
