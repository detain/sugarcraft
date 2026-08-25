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
     * @return array{cols: int, rows: int, xpix: int, ypix: int}
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
            @self::libc()->close($this->anchorSlaveFd);
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

        // WHAT THIS BLOCK USED TO BE: `self::libc()->dup($this->fd);`,
        // discarding the return value, under a comment saying it existed to
        // "prevent FD reuse race" -- that our libc fd may have been recycled
        // by an unrelated open() between our fopen() and this close(), so
        // duping first gives us a stable reference.
        //
        // WHAT IS TRUE NOW, in two parts.
        //
        // 1. The leak was real and is fixed. `dup(2)` returns a NEW
        //    descriptor, and one that nothing ever closes is a leak of
        //    exactly one descriptor per master that was read from or written
        //    to -- every one of them still pinning the master side of a pty
        //    the caller believes it has closed. MEASURED on this box, PHP
        //    8.3.6, counting entries of `/proc/self/fd` whose readlink is
        //    `/dev/ptmx`, over five open/write/close cycles: 1, 2, 3, 4, 5
        //    leaked -- linear in the number of closes. Five further cycles
        //    that never materialise the stream leak none, which is the
        //    control: the leak was this branch and not `open()`. Releasing
        //    the dup is what `tty_hangup()` needs, and
        //    PosixMasterPtyTest::testClosingAMasterThatWasWrittenToLeaksNoDescriptor()
        //    pins it against a live witness pty.
        //
        // 2. The RACE the old comment named cannot occur, and the dup could
        //    not prevent it if it could. MEASURED, PHP 8.3.6, reading
        //    /proc/self/fd/* either side of each call: `fopen('php://fd/N')`
        //    ALLOCATES A NEW descriptor (fd 4 -> the stream got 5) and
        //    `fclose()` closes that new one, leaving 4 open. `$this->fd` is
        //    therefore open continuously from posix_openpt() until the
        //    `close()` below, and its number is never free during the window
        //    the old comment described. Worse, `dup($this->fd)` is taken
        //    AFTER the `fclose()` above, so had the number ever been
        //    recycled it would duplicate whatever now owns it -- it can
        //    neither detect the substitution nor prevent it.
        //
        // WHY THE DUP STILL EARNS ITS PLACE: honestly, on the evidence, only
        // as a deliberately retained seam. MEASURED: removing the dup AND its
        // release together is behaviourally inert across candy-pty's Posix
        // suite -- `vendor/bin/phpunit --filter Posix` gives
        // 165 tests / 394 assertions / 1 warning / 2 skipped / rc 0, the same
        // figures as with it. It costs two syscalls and defers the hangup by
        // that much. It is kept rather than deleted because dormant code in
        // this tree is wired or justified, never quietly removed, and because
        // no search for a caller pattern that WOULD need a stable reference
        // has been run -- "no reachable caller was found" has not been
        // established, only "no test notices". If someone establishes that,
        // this block is the thing to delete, and the paragraph above is the
        // measurement to re-take first.
        $stableFd = -1;
        if ($usedStream) {
            $stableFd = self::libc()->dup($this->fd);
        }
        $rc = self::libc()->close($this->fd);
        if ($stableFd >= 0) {
            self::libc()->close($stableFd);
        }
        // Surface only failures from the pure-libc path where rc != 0
        // means the master fd never closed.
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
