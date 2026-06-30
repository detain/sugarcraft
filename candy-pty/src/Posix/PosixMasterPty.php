<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * @see creack/pty.Pty
 * @see portable-pty.MasterPTY
 */
final class PosixMasterPty implements MasterPty
{
    private bool $closed = false;

    /** @var resource|null */
    private $stream = null;

    /**
     * Optional anchor slave fd held open for the lifetime of the
     * master. macOS xnu zeroes the PTY winsize whenever the kernel-
     * side slave count drops to 0 — keeping a slave fd open in the
     * parent process prevents that reset between PosixPtySystem::open
     * and the first proc_open that actually wires the child's stdio
     * to the slave path. Closed in {@see close()}. Negative sentinel
     * means "no anchor was wired" (Linux ptmx doesn't need this).
     */
    private int $anchorSlaveFd = -1;

    public function __construct(
        private readonly int $fd,
        private readonly string $slavePath,
    ) {}

    /**
     * Internal: register a slave fd to hold open for the master's
     * lifetime. Set by {@see PosixPtySystem::open()} on macOS to
     * stabilise TIOCSWINSZ semantics. Idempotent — second call closes
     * the previous anchor first.
     *
     * @internal
     */
    public function attachAnchorSlaveFd(int $fd): void
    {
        if ($this->anchorSlaveFd >= 0) {
            @Libc::lib()->close($this->anchorSlaveFd);
        }
        $this->anchorSlaveFd = $fd;
    }

    /**
     * @see creack/pty.Read()
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

            $deadline = \microtime(true) + $timeout;
            while (true) {
                $remaining = $deadline - \microtime(true);
                if ($remaining <= 0) {
                    return null;
                }
                $sec  = (int) \floor($remaining);
                $usec = (int) \round(($remaining - $sec) * 1_000_000);
                $r = [$stream]; $w = null; $e = null;
                $ready = self::retryOnEintr($r, $w, $e, $sec, $usec);
                if ($ready === false) {
                    throw new PtyException(
                        \SugarCraft\Pty\Lang::t('read.select_failed', ['fd' => $this->fd])
                    );
                }
                if ($ready === 0) {
                    return null;
                }
                break;
            }
        }

        $bytes = @\fread($stream, $len);
        if ($bytes === false) {
            // Distinguish fread error from genuine EOF. Transient errors
            // (would-block on non-blocking) should return null so callers
            // continue looping rather than tearing down.
            if (@\feof($stream)) {
                return '';  // genuine EOF
            }
            return null;  // transient error / no data
        }
        return $bytes;
    }

    /**
     * @see creack/pty.Write()
     */
    public function write(string $bytes): int
    {
        $this->assertOpen();
        $stream = $this->stream();

        $written = @\fwrite($stream, $bytes);
        if ($written === false) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('write.failed', [
                    'fd'  => $this->fd,
                    'len' => \strlen($bytes),
                ])
            );
        }
        return $written;
    }

    /**
     * @see creack/pty.Setsize()
     * @see portable-pty.MasterPty.Resize()
     */
    public function resize(int $cols, int $rows): void
    {
        $this->assertOpen();

        $libc = Libc::lib();
        $ws = \SugarCraft\Pty\SizeIoctl::pack($rows, $cols);
        $rc = \SugarCraft\Pty\SizeIoctl::setSizeViaLibc($libc, $this->fd, $ws);
        if ($rc !== 0) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('resize.failed', [
                    'fd'   => $this->fd,
                    'cols' => $cols,
                    'rows' => $rows,
                    'rc'   => $rc,
                ])
            );
        }
    }

    /**
     * @return array{cols: int, rows: int, xpix: int, ypix: int}
     * @see creack/pty.GetsizeFull()
     */
    public function size(): array
    {
        $this->assertOpen();

        $libc = Libc::lib();
        $ws = \SugarCraft\Pty\SizeIoctl::emptyBuffer();
        $rc = \SugarCraft\Pty\SizeIoctl::getSizeViaLibc($libc, $this->fd, $ws);
        if ($rc !== 0) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('size.failed', [
                    'fd' => $this->fd,
                    'rc' => $rc,
                ])
            );
        }
        return \SugarCraft\Pty\SizeIoctl::unpack($ws);
    }

    /**
     * @return mixed
     * @see creack/pty.Pty.Fd()
     */
    public function stream(): mixed
    {
        $this->assertOpen();
        if ($this->stream !== null) {
            return $this->stream;
        }

        // Use 'w+' so fopen fails atomically if $this->fd has been
        // closed since we were constructed — 'r+b' silently succeeds
        // and returns a stream on whatever fd now occupies that number,
        // which can cause us to close an unrelated fd in close().
        $stream = @\fopen('php://fd/' . $this->fd, 'w+');
        if (!\is_resource($stream)) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('stream.fopen_failed', ['fd' => $this->fd])
            );
        }
        $this->stream = $stream;
        return $this->stream;
    }

    /**
     * Close the master PTY fd.
     *
     * If a stream was materialised via {@see stream()}, `fclose()` is
     * called first. Because `fopen('php://fd/N')` dup()s the fd (php-src
     * plain_wrapper.c), `fclose` only closes the duplicate — the original
     * fd from `posix_openpt` remains open and must be closed explicitly
     * or the kernel's master-side refcount never reaches 0 and
     * `tty_hangup()` never fires (no SIGHUP for the session leader).
     * The fall-through libc `close()` handles the original fd.
     *
     * Idempotent — subsequent calls are no-ops.
     *
     * @see creack/pty.Close()
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        // Release the macOS slave anchor (if any) BEFORE closing the
        // master so the kernel's PTY teardown is symmetric — master
        // first would be fine on Linux, but macOS warns on the
        // anchored fd surviving past the master.
        if ($this->anchorSlaveFd >= 0) {
            @Libc::lib()->close($this->anchorSlaveFd);
            $this->anchorSlaveFd = -1;
        }

        $usedStream = $this->stream !== null;
        if ($usedStream) {
            $stream = $this->stream;
            $this->stream = null;
            if (\is_resource($stream) && !@\fclose($stream)) {
                throw new PtyException(
                    \SugarCraft\Pty\Lang::t('close.failed', [
                        'fd' => $this->fd,
                        'rc' => -1,
                    ])
                );
            }
            // Fall through to libc close: `fopen('php://fd/N')` dup()s
            // the fd (see php-src plain_wrapper.c), so fclose only
            // closed the duplicate. The original fd from posix_openpt
            // must be closed explicitly or tty_hangup() never fires.
        }

        $rc = Libc::lib()->close($this->fd);
        // When we went through the stream path, our libc fd may have
        // been recycled by an unrelated open() between our fopen() and
        // this close() — close() on it can then succeed-but-target-the-
        // wrong-thing OR return EBADF (-1) if nothing claimed it. Either
        // is fine for us; surface only failures from the pure-libc path
        // where rc != 0 means the master fd never closed.
        if ($rc !== 0 && !$usedStream) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('close.failed', ['fd' => $this->fd, 'rc' => $rc])
            );
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function fd(): int
    {
        return $this->fd;
    }

    /**
     * Call stream_select, retrying automatically when EINTR is detected.
     *
     * stream_select returns false when interrupted (EINTR). Rather than
     * conflating EINTR with fatal errors, this wrapper retries on EINTR
     * after dispatching any pending signals.
     *
     * @param array<int, resource> &$read
     * @param array<int, resource>|null &$write  null when caller is not interested in write readiness
     * @param array<int, resource>|null &$except null when caller is not interested in exception readiness
     * @return int|false  false on real error, 0 on timeout, >0 on ready
     */
    public static function retryOnEintr(array &$read, ?array &$write, ?array &$except, ?int $sec, ?int $usec): int|false
    {
        while (true) {
            $ready = @\stream_select($read, $write, $except, $sec, $usec);
            if ($ready !== false) {
                return $ready;
            }
            // stream_select returned false — check if it was EINTR.
            if (Libc::errno() !== Libc::EINTR) {
                return false;
            }
            // EINTR: dispatch any pending signal handlers and retry.
            if (\function_exists('pcntl_signal_dispatch')) {
                @\pcntl_signal_dispatch();
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PtyException('cannot operate on a closed PosixMasterPty');
        }
    }
}
