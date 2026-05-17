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
                $ready = @\stream_select($r, $w, $e, $sec, $usec);
                if ($ready === false) {
                    if (\function_exists('pcntl_signal_dispatch')) {
                        @\pcntl_signal_dispatch();
                        continue;
                    }
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
            return '';
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
        $rc = $libc->ioctl($this->fd, \SugarCraft\Pty\SizeIoctl::setRequest(), $ws);
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
        $rc = $libc->ioctl($this->fd, \SugarCraft\Pty\SizeIoctl::getRequest(), $ws);
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

        $stream = @\fopen('php://fd/' . $this->fd, 'r+b');
        if (!\is_resource($stream)) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('stream.fopen_failed', ['fd' => $this->fd])
            );
        }
        $this->stream = $stream;
        return $this->stream;
    }

    /**
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

        if ($this->stream !== null) {
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
            return;
        }

        $rc = Libc::lib()->close($this->fd);
        if ($rc !== 0) {
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

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PtyException('cannot operate on a closed PosixMasterPty');
        }
    }
}
