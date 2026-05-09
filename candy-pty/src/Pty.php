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

    /**
     * Lazily-materialised PHP stream wrapper around the master fd.
     *
     * Created on first {@see stream()} / {@see read()} / {@see write()}
     * / {@see setBlocking()} call. The wrapper takes ownership of the
     * fd — `fclose()` on the resource closes the underlying fd, so
     * {@see close()} routes through this resource when it's set.
     *
     * @var resource|null
     */
    private $stream = null;

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
     * `$controllingTerminal` (default `false`) routes the spawn
     * through `bin/pty-shim.php` so the child claims the slave PTY
     * as its controlling terminal — required for Ctrl+C → SIGINT
     * delivery and other tty-driven job-control signals. Costs
     * ~5-50 ms of shim startup; pass `true` only when running
     * interactive shells / editors that depend on it.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = self::DEFAULT_COLS,
        int $rows = self::DEFAULT_ROWS,
        bool $controllingTerminal = false,
    ): Child {
        $this->assertOpen();
        $this->resize($cols, $rows);
        return Spawn::proc($this->master, $cmd, $env, $controllingTerminal);
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
     * Return (and cache) a PHP stream resource wrapping the master fd
     * via the `php://fd/N` wrapper. The wrapper takes ownership of the
     * fd — closing the returned resource also closes the underlying
     * fd, so prefer {@see close()} to unwind both at once.
     *
     * @return resource
     */
    public function stream()
    {
        $this->assertOpen();
        if ($this->stream !== null) {
            return $this->stream;
        }

        $stream = @\fopen('php://fd/' . $this->master->fd, 'r+b');
        if (!\is_resource($stream)) {
            throw new PtyException(Lang::t('stream.fopen_failed', ['fd' => $this->master->fd]));
        }
        $this->stream = $stream;
        return $this->stream;
    }

    /**
     * Write `$bytes` to the master end. Returns the number of bytes
     * actually written (may be less than `strlen($bytes)` in
     * non-blocking mode under back-pressure).
     */
    public function write(string $bytes): int
    {
        $this->assertOpen();
        $stream = $this->stream();

        $written = @\fwrite($stream, $bytes);
        if ($written === false) {
            throw new PtyException(Lang::t('write.failed', [
                'fd'  => $this->master->fd,
                'len' => \strlen($bytes),
            ]));
        }
        return $written;
    }

    /**
     * Read up to `$len` bytes from the master end.
     *
     * Return semantics:
     *
     * | Mode                              | No data on PTY | Data available | Slave closed       |
     * |-----------------------------------|----------------|----------------|--------------------|
     * | Blocking, `$timeout` null         | blocks         | bytes          | `''` (EOF)         |
     * | Non-blocking, `$timeout` null     | `''`           | bytes          | `''` (EOF)         |
     * | Any blocking mode, `$timeout` set | `null`         | bytes          | `''` (EOF)         |
     *
     * `$timeout` is in fractional seconds (`0.05` = 50 ms). It uses
     * `stream_select()` to wait for the master fd to become readable;
     * if the timeout elapses with nothing pending, returns `null`.
     */
    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        $this->assertOpen();
        if ($len <= 0) {
            throw new \InvalidArgumentException("read length must be > 0; got {$len}");
        }

        $stream = $this->stream();

        if ($timeout !== null) {
            if ($timeout < 0) {
                throw new \InvalidArgumentException("timeout must be >= 0; got {$timeout}");
            }

            // Deadline-based retry handles `EINTR` cleanly: a SIGWINCH
            // (or any caught signal) interrupts `stream_select` with
            // a `false` return; we dispatch pending pcntl handlers,
            // recompute the remaining timeout, and retry. The loop
            // bails out if pcntl is unavailable (can't distinguish
            // EINTR from a real error) or the deadline elapses.
            $deadline = \microtime(true) + $timeout;
            while (true) {
                $remaining = $deadline - \microtime(true);
                if ($remaining <= 0) {
                    return null;
                }
                $sec  = (int) \floor($remaining);
                $usec = (int) \round(($remaining - $sec) * 1_000_000);
                $r = [$stream]; $w = null; $e = null;
                $ready = @\stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) {
                    if (\function_exists('pcntl_signal_dispatch')) {
                        @\pcntl_signal_dispatch();
                        continue;
                    }
                    throw new PtyException(Lang::t('read.select_failed', ['fd' => $this->master->fd]));
                }
                if ($ready === 0) {
                    return null;
                }
                break;
            }
        }

        $bytes = @\fread($stream, $len);
        if ($bytes === false) {
            // Linux: master fread() after every slave fd is closed
            // returns false (errno EIO). Treat as EOF — same outcome
            // the caller expects when the child has exited cleanly.
            return '';
        }
        return $bytes;
    }

    /**
     * Toggle blocking / non-blocking mode on the master fd.
     */
    public function setBlocking(bool $blocking): void
    {
        $this->assertOpen();
        if (!@\stream_set_blocking($this->stream(), $blocking)) {
            throw new PtyException(Lang::t('stream.set_blocking_failed', [
                'fd'       => $this->master->fd,
                'blocking' => $blocking ? 'true' : 'false',
            ]));
        }
    }

    /**
     * Close the master fd. Idempotent — second call is a no-op.
     *
     * Routes through `fclose()` if the stream wrapper was materialised
     * (it owns the fd) or `close(2)` otherwise.
     *
     * Children spawned through this Pty are NOT auto-killed; reap
     * them via {@see Child::wait()} or {@see Child::exited()} before
     * (or after) closing the parent end.
     *
     * @throws PtyException if the underlying close fails on the first
     *                      call.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if ($this->stream !== null) {
            $stream = $this->stream;
            $this->stream = null;
            if (\is_resource($stream) && !@\fclose($stream)) {
                throw new PtyException(Lang::t('close.failed', [
                    'fd' => $this->master->fd,
                    'rc' => -1,
                ]));
            }
            return;
        }

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
