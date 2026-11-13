<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Terminal attributes (termios) for getting/setting raw mode and
 * other tty flags on a file descriptor.
 *
 * @see portable-pty.Termios
 */
interface Termios
{
    /** Apply changes immediately. */
    public const TCSANOW = 0;

    /** Flush the output queue (wait for it to drain) before applying. */
    public const TCSADRAIN = 1;

    /** Flush both input and output queues before applying. */
    public const TCSAFLUSH = 2;

    /**
     * Get current terminal attributes as an immutable snapshot.
     *
     * @see portable-pty.Termios.Current()
     */
    public function current(): self;

    /**
     * Return a raw-mode copy with canonical input, echo, signal chars,
     * and output processing disabled.
     *
     * Does not modify the current instance — returns a new one.
     *
     * @see portable-pty.Termios.MakeRaw()
     */
    public function makeRaw(): self;

    /**
     * Apply these attributes to the terminal.
     *
     * @param int $when one of TCSANOW, TCSADRAIN, TCSAFLUSH (0 = TCSANOW).
     *                  TCSASOFT is a macOS flag modifier, not an action —
     *                  it is deliberately absent from this trio.
     * @see portable-pty.Termios.Apply()
     */
    public function apply(int $when = self::TCSANOW): void;

    /**
     * Restore the attributes captured by a prior {@see current()} call.
     *
     * restore() without a capture throws: a fresh instance never read
     * the fd's real settings, so there is nothing truthful to put back.
     *
     * @see portable-pty.Termios.Restore()
     */
    public function restore(): void;

    /**
     * True when the file descriptor refers to a terminal device.
     *
     * @see portable-pty.Termios.IsTty()
     */
    public function isAtty(): bool;
}
