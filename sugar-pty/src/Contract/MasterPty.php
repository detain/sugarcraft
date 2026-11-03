<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * The master end of a PTY pair — used by the host to read output from
 * and send input to the child process connected to the slave end.
 *
 * @see creack/pty.Pty
 * @see portable-pty.MasterPTY
 */
interface MasterPty
{
    public const SIGTERM = 15;
    public const SIGKILL = 9;
    public const SIGINT = 2;

    /**
     * Read up to $len bytes from the master.
     *
     * Returns null on timeout, empty string on EOF, bytes otherwise.
     *
     * Timeout contract: the default is a FINITE 0.05s bound so the bare
     * one-argument shape can never hang a caller. With a finite $timeout the
     * wait is bounded by an internal select. With $timeout = null NO mode
     * control happens — the read inherits the stream's current blocking mode,
     * which only stream_set_blocking on {@see stream()} (or Pty::setBlocking()
     * via the facade) changes and which a freshly opened master defaults to
     * BLOCKING. Passing null therefore requires the caller to have armed
     * non-blocking mode itself, or to accept an unbounded wait.
     *
     * Return taxonomy: non-empty string = data; '' = confirmed EOF (every
     * slave closed); null = nothing this instant (timeout elapsed or
     * would-block). Quiet and gone are never conflated.
     *
     * @see creack/pty.Read()
     * @see portable-pty.MasterPty.Read()
     */
    public function read(int $len = 8192, ?float $timeout = 0.05): ?string;

    /**
     * Write bytes to the master.
     *
     * Returns the number of bytes written (may be less than strlen in
     * non-blocking mode under back-pressure).
     *
     * @see creack/pty.Write()
     */
    public function write(string $bytes): int;

    /**
     * Send TIOCSWINSZ to resize the slave's terminal.
     *
     * @see creack/pty.Setsize()
     * @see portable-pty.MasterPty.Resize()
     */
    public function resize(int $cols, int $rows): void;

    /**
     * Read the current terminal size via TIOCGWINSZ.
     *
     * @return array{rows: int, cols: int, xpix: int, ypix: int}
     * @see creack/pty.GetsizeFull()
     */
    public function size(): array;

    /**
     * Return a PHP stream resource wrapping the master fd.
     *
     * @return resource
     * @see creack/pty.Pty.Fd()
     */
    public function stream(): mixed;

    /**
     * Close the master fd. Idempotent.
     *
     * @see creack/pty.Close()
     */
    public function close(): void;

    /**
     * True if {@see close()} has already run on this master.
     *
     * Surfaced on the contract (not just implementations) so signal
     * handlers and supervisors can guard `resize()` / `write()` calls
     * against a torn-down session without forcing a concrete-class
     * dependency.
     */
    public function isClosed(): bool;

    /**
     * Return the raw POSIX file descriptor number.
     *
     * This is exposed on the contract so callers do not need to cast
     * to {@see PosixMasterPty} to access the underlying fd — a common
     * need when wiring FFI-level integrations.
     *
     * Note: Windows does not have POSIX fd semantics; any Windows Pty
     * implementation (not yet built) should throw if this is called.
     */
    public function fd(): int;
}
