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

    /** Default cols passed to `spawn()` when caller doesn't override. */
    public const DEFAULT_COLS = 80;

    /** Default rows passed to `spawn()` when caller doesn't override. */
    public const DEFAULT_ROWS = 24;

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
     * environment when null. `$cols` / `$rows` set the initial
     * `TIOCSWINSZ` so terminal-size-aware programs (tput, ncurses,
     * vim) see the requested geometry as soon as they start;
     * defaults of 80×24 mirror the de-facto VT100 baseline.
     *
     * @param list<string>             $cmd
     * @param array<string,string>|null $env
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = self::DEFAULT_COLS,
        int $rows = self::DEFAULT_ROWS,
    ): Child {
        $this->assertOpen();
        $this->resize($cols, $rows);
        return Spawn::proc($this->master, $cmd, $env);
    }

    /**
     * Set the master/slave winsize via `TIOCSWINSZ`. The kernel
     * notifies the child via `SIGWINCH` if the child is in its own
     * process group — currently NOT the case (see TIOCSCTTY caveat
     * in `CALIBER_LEARNINGS.md`), so the new size becomes visible
     * only when the child queries it via `TIOCGWINSZ` itself.
     */
    public function resize(int $cols, int $rows): void
    {
        $this->assertOpen();

        $libc = Libc::lib();
        $ws = SizeIoctl::pack($rows, $cols);
        $rc = $libc->ioctl($this->master->fd, SizeIoctl::setRequest(), $ws);
        if ($rc !== 0) {
            throw new PtyException(Lang::t('resize.failed', [
                'fd'   => $this->master->fd,
                'cols' => $cols,
                'rows' => $rows,
                'rc'   => $rc,
            ]));
        }
    }

    /**
     * Read the current winsize via `TIOCGWINSZ`.
     *
     * @return array{cols:int, rows:int, xpix:int, ypix:int}
     */
    public function size(): array
    {
        $this->assertOpen();

        $libc = Libc::lib();
        $ws = SizeIoctl::emptyBuffer();
        $rc = $libc->ioctl($this->master->fd, SizeIoctl::getRequest(), $ws);
        if ($rc !== 0) {
            throw new PtyException(Lang::t('size.failed', [
                'fd' => $this->master->fd,
                'rc' => $rc,
            ]));
        }
        return SizeIoctl::unpack($ws);
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
