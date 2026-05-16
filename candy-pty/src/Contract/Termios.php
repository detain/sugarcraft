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
     * @param int $when one of TCSANOW, TCSADRAIN, TCSASOFT (0 = TCSANOW)
     * @see portable-pty.Termios.Apply()
     */
    public function apply(int $when = self::TCSANOW): void;

    /**
     * Restore the original terminal attributes saved at construction.
     *
     * @see portable-pty.Termios.Restore()
     */
    public function restore(): void;

    /**
     * True when the file descriptor refers to a terminal device.
     *
     * @see portable-pty.Termios.IsAty()
     */
    public function isAtty(): bool;
}
