<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Libc;

/**
 * FFI-based termios implementation using libc tcgetattr/tcsetattr/cfmakeraw.
 *
 * The struct termios is treated as opaque (≥80 bytes) because layout
 * differs across glibc/musl (≈60 bytes) and Darwin (72 bytes). Only
 * call cfmakeraw/tcgetattr/tcsetattr — do NOT read individual fields.
 *
 * Mirrors portable-pty.Termios
 * @see creack/pty.SetSize()
 */
final class PosixTermios implements Termios
{
    /** Apply changes immediately. */
    public const TCSANOW = 0;

    /** Drain output before applying. */
    public const TCSADRAIN = 1;

    /** Drain output and discard input. */
    public const TCSAFLUSH = 2;

    /** Opaque termios buffer — ≥80 bytes covers glibc/musl/Darwin. */
    private const BUFSIZE = 80;

    private int $fd;
    private \FFI\CData $buf;
    private \FFI $libc;
    private ?self $original = null;

    public function __construct(int $fd)
    {
        $this->fd = $fd;
        $this->libc = Libc::lib();
        $this->buf = \FFI::new('char[' . self::BUFSIZE . ']');
    }

    /**
     * @see portable-pty.Termios.Current()
     */
    public function current(): self
    {
        $instance = $this->withCData($this->buf);
        $rc = $this->libc->tcgetattr($this->fd, \FFI::addr($instance->buf));
        if ($rc !== 0) {
            throw new \RuntimeException(
                'tcgetattr failed on fd ' . $this->fd
            );
        }
        return $instance;
    }

    /**
     * @see portable-pty.Termios.MakeRaw()
     */
    public function makeRaw(): self
    {
        $instance = $this->withCData($this->buf);
        $this->libc->cfmakeraw(\FFI::addr($instance->buf));
        return $instance;
    }

    /**
     * @param int $when one of TCSANOW, TCSADRAIN, TCSAFLUSH
     * @see portable-pty.Termios.Apply()
     */
    public function apply(int $when = self::TCSANOW): void
    {
        $rc = $this->libc->tcsetattr($this->fd, $when, \FFI::addr($this->buf));
        if ($rc !== 0) {
            throw new \RuntimeException(
                'tcsetattr failed on fd ' . $this->fd . ' with when=' . $when
            );
        }
    }

    /**
     * @see portable-pty.Termios.Restore()
     */
    public function restore(): void
    {
        if ($this->original === null) {
            return;
        }
        \FFI::memcpy(\FFI::addr($this->buf), \FFI::addr($this->original->buf), self::BUFSIZE);
        $this->apply(self::TCSANOW);
    }

    /**
     * @see portable-pty.Termios.IsAty()
     */
    public function isAtty(): bool
    {
        if (!\function_exists('posix_isatty')) {
            return false;
        }
        return \posix_isatty($this->fd);
    }

    /**
     * Get the raw file descriptor.
     */
    public function fd(): int
    {
        return $this->fd;
    }

    /**
     * Clone this instance with a new buffer, preserving the fd and libc handle.
     *
     * @param \FFI\CData $newBuf replacement buffer (≥BUFSIZE bytes)
     */
    private function withCData(\FFI\CData $newBuf): self
    {
        $clone = clone $this;
        $clone->buf = \FFI::new('char[' . self::BUFSIZE . ']');
        \FFI::memcpy(\FFI::addr($clone->buf), \FFI::addr($newBuf), self::BUFSIZE);
        return $clone;
    }
}
