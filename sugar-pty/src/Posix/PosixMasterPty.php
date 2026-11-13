<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Concerns\LibcAccess;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * @see creack/pty.Pty
 * @see portable-pty.MasterPTY
 *
 * ## Abandonment
 *
 * There is deliberately NO destructor here: an abandoned master leaks
 * its fd until process exit (and sends no SIGHUP cascade), which beats
 * the alternative — auto-closing on GC would yank a descriptor a child
 * still legitimately owns through php://fd inheritance or setsid
 * wiring. `close()` is the contract. Pool-created pairs are the one
 * rescue: PtyPool's sweep closes masters left fully unreachable
 * (PTY-38). See "Abandoned masters" under Known limitations.
 */
final class PosixMasterPty implements MasterPty
{
    use LibcAccess;

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
            @self::libc()->close($this->anchorSlaveFd);
        }
        $this->anchorSlaveFd = $fd;
    }

    /**
     * Read up to `$len` bytes, waiting at most `$timeout` seconds.
     *
     * Return taxonomy: a non-empty string is data; `''` is END-OF-FILE
     * (every slave closed — feof confirmed); `null` means "nothing to
     * report this instant" (select timeout elapsed, or a transient
     * would-block). Idle non-blocking reads therefore return `null`,
     * never `''` — a caller must never confuse "quiet" with "gone".
     *
     * The default is a FINITE 0.05s bound so the bare one-argument
     * shape cannot hang an interactive caller (audit: the previous
     * `null` default meant "mode-governed", which surfaced as a
     * permanent stall on the lib's own default-blocking master).
     * Passing `null` explicitly keeps the old mode-governed contract:
     * the select arm is skipped entirely and @fread runs on the stream
     * AS-IS, so the wait is governed by the stream's blocking mode —
     * set only externally via stream_set_blocking($master->stream(),
     * false) or Pty::setBlocking(). Callers passing null must ensure
     * non-blocking mode themselves or accept the blocking read. The
     * mode is deliberately NOT forced here: the stream is shared with
     * PosixPump/MultiPump selects and owned by the caller's mode choice.
     *
     * @see creack/pty.Read()
     */
    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
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
                // Decompose seconds + microseconds using intdiv/% to guarantee
                // $usec < 1_000_000 (stream_select contract). Rounding the
                // fractional part directly can produce 1_000_000 when
                // $remaining is very close to the next whole second.
                $totalUsec = (int) ($remaining * 1_000_000);
                $sec  = \intdiv($totalUsec, 1_000_000);
                $usec = $totalUsec % 1_000_000;
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
        if ($bytes === '' && !@\feof($stream)) {
            // A would-block on a non-blocking stream surfaces as a
            // SUCCESSFUL zero-length fread, not as false. Reporting that
            // as '' would make "quiet" indistinguishable from "gone" —
            // the pump loops key teardown off ''-is-EOF. `null` keeps
            // the taxonomy: '' only ever means confirmed EOF.
            return null;
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

        $libc = self::libc();
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
     * @return array{rows: int, cols: int, xpix: int, ypix: int}
     * @see creack/pty.GetsizeFull()
     */
    public function size(): array
    {
        $this->assertOpen();

        $libc = self::libc();
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

    public function setBlocking(bool $blocking): void
    {
        if (!@\stream_set_blocking($this->stream(), $blocking)) {
            throw new PtyException(\SugarCraft\Pty\Lang::t('stream.set_blocking_failed', [
                'fd'       => $this->fd,
                'blocking' => $blocking ? 'true' : 'false',
            ]));
        }
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
     * The fall-through libc `close()` handles the original fd, and it runs
     * on every path: a failed `fclose()` is reported only after the
     * original fd has been released.
     *
     * Idempotent — subsequent calls are no-ops.
     *
     * @throws PtyException when the libc `close()` of the master fd fails
     *         (on either path), or when `fclose()` of the materialised
     *         stream failed (the master fd was still closed first)
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
            @self::libc()->close($this->anchorSlaveFd);
            $this->anchorSlaveFd = -1;
        }

        $fcloseFailed = false;
        if ($this->stream !== null) {
            $stream = $this->stream;
            $this->stream = null;
            // A failed fclose is RECORDED, not thrown here: throwing at this
            // point skipped the libc close below, and with $this->closed
            // already true there was no retry path -- the posix_openpt fd
            // leaked for the life of the process. The failure is still
            // surfaced, after the original fd is released.
            if (\is_resource($stream) && !@\fclose($stream)) {
                $fcloseFailed = true;
            }
            // Fall through to libc close: `fopen('php://fd/N')` dup()s
            // the fd (see php-src plain_wrapper.c), so fclose only
            // closed the duplicate. The original fd from posix_openpt
            // must be closed explicitly or tty_hangup() never fires.
        }

        // HISTORY (was ~85 forensic comment lines; finding sugar-pty-038):
        // this branch once dup()ed the master fd and discarded the result
        // under an "FD reuse race" claim. The ghost-hunt measured the
        // truth: fopen('php://fd/N') takes a NEW descriptor, $this->fd is
        // open continuously from posix_openpt() until the close below, and
        // the dup never survived the call — a caller reading the number
        // back after close() answers EBADF with or without it. The pair
        // only deferred tty_hangup() by two syscalls, unobservable from any
        // caller position, so the seam is deleted; the leak lesson the hunt
        // also taught (a discarded dup pins the master forever) stays
        // pinned by testClosingAMasterThatWasWrittenToLeaksNoDescriptor().
        $rc = self::libc()->close($this->fd);
        // errno is read immediately: nothing may overwrite it before the
        // failure surface below.
        $errno = $rc !== 0 ? Libc::errnoDetail() : '';
        // A failed libc close is surfaced on BOTH paths. It used to be
        // checked only when no stream had been materialised, so a master
        // that had been read from or written to -- every real consumer's
        // master -- could fail to close while isClosed() reported true and
        // the caller proceeded as if the hangup had been delivered. Because
        // php://fd/N dup()s (see above), $this->fd is still open at this
        // point on the stream path too, so a non-zero rc is a real failure
        // there and never the expected echo of the fclose.
        if ($rc !== 0) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('close.failed', ['fd' => $this->fd, 'rc' => $rc, 'errno' => $errno])
            );
        }
        if ($fcloseFailed) {
            throw new PtyException(
                \SugarCraft\Pty\Lang::t('close.stream_fclose_failed', ['fd' => $this->fd])
            );
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close a bare master fd when no instance is left to do it — the
     * orphan leg of PtyPool's sweep (PTY-38). The caller has already
     * proved the descriptor still names a /dev/ptmx; the rc is
     * returned, not thrown, because an orphan sweep must never raise.
     *
     * @internal
     */
    public static function closeRawFd(int $fd): int
    {
        return self::libc()->close($fd);
    }

    public function fd(): int
    {
        $this->assertOpen();
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
        // A FINITE timeout is a deadline, so it is computed ONCE here and the
        // remainder recomputed per retry. The retry used to re-pass the
        // caller's original $sec/$usec, which restarts the full wait on every
        // interruption: under a signal arriving faster than the timeout -- 200
        // pty acquire/release cycles reaping children is exactly that -- the
        // deadline never converges and the caller blocks past its own budget
        // with nothing to show for it. Every production caller passes a finite
        // timeout (MultiPump, PosixPump, and read()'s select arm), so every one
        // of them was exposed. A null timeout means "block until ready" by
        // contract and is retried unchanged.
        $deadline = $sec === null ? null : \microtime(true) + $sec + (($usec ?? 0) / 1_000_000);

        while (true) {
            // error_clear_last() so the message inspected below is THIS call's
            // and never a stale one from earlier in the process.
            \error_clear_last();
            $ready = @\stream_select($read, $write, $except, $sec, $usec);
            if ($ready !== false) {
                return $ready;
            }
            // stream_select returned false — check if it was EINTR.
            if (!self::wasInterrupted()) {
                return false;
            }
            // EINTR: dispatch any pending signal handlers and retry.
            if (\function_exists('pcntl_signal_dispatch')) {
                @\pcntl_signal_dispatch();
            }

            if ($deadline !== null) {
                $remaining = $deadline - \microtime(true);
                if ($remaining <= 0) {
                    // Timed out DURING the interruption. Reported as 0 (the
                    // stream_select "nothing became ready" answer) rather than
                    // false, because false means a real error and callers turn
                    // it into a thrown PtyException -- an expired deadline is
                    // not an error.
                    return 0;
                }
                // Decomposed with intdiv/% so $usec can never reach 1_000_000,
                // which stream_select rejects; same guard as read()'s arm.
                $totalUsec = (int) ($remaining * 1_000_000);
                $sec = \intdiv($totalUsec, 1_000_000);
                $usec = $totalUsec % 1_000_000;
            }
        }
    }

    /**
     * Did the `stream_select()` that just returned `false` fail with EINTR?
     *
     * MEASURED on PHP 8.3.6: `Libc::errno()` reads 0 immediately after an
     * interrupted `stream_select()`, not `EINTR`. PHP raises its own warning
     * first, and that path resets the C-level errno before any userland code —
     * FFI shim included — can read it. So the errno test this helper was
     * originally written as could never be true, and the EINTR retry it guards
     * had never executed once. Nothing caught it because the only coverage the
     * function had was a `method_exists()` assertion.
     *
     * PHP does still report the number, in the warning text:
     *
     *     stream_select(): Unable to select [4]: Interrupted system call (max_fd=4)
     *
     * The bracketed value is errno, so it is matched NUMERICALLY rather than by
     * the strerror string — `strerror()` output is locale-dependent and
     * "Interrupted system call" is not a stable token, while `[4]` is.
     *
     * The errno read is kept and tried FIRST: it is correct wherever it works,
     * costs one FFI call, and this fallback is only needed on builds that clear
     * errno on the way out. Neither source is removed in favour of the other.
     */
    private static function wasInterrupted(): bool
    {
        if (Libc::errno() === Libc::EINTR) {
            return true;
        }

        $last = \error_get_last();
        if ($last === null || !isset($last['message'])) {
            return false;
        }

        if (\preg_match('/Unable to select \[(\d+)\]/', (string) $last['message'], $m) !== 1) {
            return false;
        }

        return (int) $m[1] === Libc::EINTR;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PtyException('cannot operate on a closed PosixMasterPty');
        }
    }
}
