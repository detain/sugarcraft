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
     * @see creack/pty.Read()
     * @see portable-pty.MasterPty.Read()
     */
    public function read(int $len = 8192, ?float $timeout = null): ?string;

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
     * @return array{cols: int, rows: int, xpix: int, ypix: int}
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
}
