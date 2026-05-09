<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Facade for opening a master/slave PTY pair and (in later PRs)
 * driving I/O / resize / signals on it.
 *
 * Usage:
 * ```
 * $pty   = Pty::open();
 * $child = $pty->spawn(['/bin/echo', 'hello']);
 * $exit  = $child->wait();
 * $pty->close();
 * ```
 *
 * The opened {@see Master} is exposed read-only via {@see $master}; do
 * not call libc directly on its fd — go through this facade so the
 * close lifecycle stays single-source.
 *
 * Mirrors charmbracelet/x/xpty.Open() for Linux/macOS.
 *
 * @see https://github.com/charmbracelet/x/tree/main/xpty
 */
final class Pty
{
    /** `O_RDWR` flag — value identical on Linux and macOS. */
    private const O_RDWR = 0x0002;

    private bool $closed = false;

    public function __construct(
        public readonly Master $master,
    ) {}

    /**
     * Open a fresh PTY pair. Steps mirror `posix_openpt + grantpt +
     * unlockpt + ptsname_r`; any failure throws {@see PtyException}
     * after closing the master fd if step 1 succeeded.
     */
    public static function open(): self
    {
        $libc = Libc::lib();

        $masterFd = $libc->posix_openpt(self::O_RDWR | self::oNoCtty());
        if ($masterFd < 0) {
            throw new PtyException(Lang::t('open.posix_openpt_failed', ['rc' => $masterFd]));
        }

        if ($libc->grantpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.grantpt_failed', ['fd' => $masterFd]));
        }

        if ($libc->unlockpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.unlockpt_failed', ['fd' => $masterFd]));
        }

        $slavePath = self::readPtsName($libc, $masterFd);

        return new self(new Master($masterFd, $slavePath));
    }

    /**
     * Spawn a child process with stdin/stdout/stderr wired to the
     * slave PTY. The child's PID, exit code, and lifecycle are
     * exposed through the returned {@see Child}.
     *
     * `$cmd` is passed to `proc_open()` as an array (no shell
     * expansion). `$env` defaults to inheriting the parent's
     * environment when null.
     *
     * @param list<string>             $cmd
     * @param array<string,string>|null $env
     */
    public function spawn(array $cmd, ?array $env = null): Child
    {
        $this->assertOpen();
        return Spawn::proc($this->master, $cmd, $env);
    }

    /**
     * Close the master fd. Idempotent — second call is a no-op.
     *
     * Children spawned through this Pty are NOT auto-killed; reap
     * them via {@see Child::wait()} or {@see Child::exited()} before
     * (or after) closing the parent end.
     *
     * @throws PtyException if `close(2)` returns non-zero on the
     *                      first call.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $rc = Libc::lib()->close($this->master->fd);
        if ($rc !== 0) {
            throw new PtyException(Lang::t('close.failed', ['fd' => $this->master->fd, 'rc' => $rc]));
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PtyException('cannot operate on a closed Pty');
        }
    }

    /** Platform-specific `O_NOCTTY`: Linux 0o400, macOS 0x20000. */
    private static function oNoCtty(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
    }

    /**
     * Read the slave PTY path via `ptsname_r` into a 256-byte buffer.
     * 256 leaves headroom over the de-facto cap (Linux ≤14 chars,
     * macOS ≤13 chars) without forcing a re-call loop.
     */
    private static function readPtsName(\FFI $libc, int $masterFd): string
    {
        $buf = $libc->new('char[256]');
        $rc = $libc->ptsname_r($masterFd, $buf, 256);
        if ($rc !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.ptsname_failed', ['fd' => $masterFd]));
        }
        return \FFI::string($buf);
    }
}
