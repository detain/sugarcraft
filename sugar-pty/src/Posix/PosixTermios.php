<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Concerns\LibcAccess;
use SugarCraft\Pty\Contract\Termios;

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
    use LibcAccess;

    /** Apply changes immediately. */
    public const TCSANOW = 0;

    /** Drain output before applying. */
    public const TCSADRAIN = 1;

    /** Drain output and discard input. */
    public const TCSAFLUSH = 2;

    /**
     * Opaque termios buffer — ≥80 bytes covers glibc/musl/Darwin.
     *
     * There is deliberately no runtime sizeof guard: 'struct termios'
     * is never declared in the FFI cdef (the calls take void*), BECAUSE
     * the layout differs across libc vendors — taking its size at
     * runtime would require committing to one layout, defeating the
     * opacity this constant exists to buy. 80 exceeds every observed
     * vendor layout (glibc/musl ≈60, Darwin 72) with headroom.
     */
    private const BUFSIZE = 80;

    private int $fd;
    private \FFI\CData $buf;
    private \FFI $libc;
    private ?self $original = null;
    private bool $captured = false;

    public function __construct(int $fd)
    {
        $this->fd = $fd;
        $this->libc = self::libc();
        $this->buf = $this->libc->new('char[' . self::BUFSIZE . ']');
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
        // Record the just-captured attributes as the restore target so
        // restore() on this snapshot can revert the fd to them. Without
        // this, $original stays null and restore() is a dead no-op.
        $instance->original = $this->withCData($instance->buf);
        $instance->captured = true;
        return $instance;
    }

    /**
     * @see portable-pty.Termios.MakeRaw()
     *
     * Cfmakeraw() mutates whatever bytes it is handed, so a raw snapshot
     * of a freshly-constructed (never tcgetattr'd) instance would carry
     * a zeroed buffer with only cfmakeraw's explicit bits set — apply()
     * would then write junk for every flag cfmakeraw does not touch
     * (c_cflag, the whole c_cc array beyond VMIN/VTIME). On a PTY that
     * junk is mostly moot, which is why the behavior tests stayed green
     * — but the class is fd-generic, so an uncaptured instance now
     * fetches the fd's real current attributes first (via current()) and
     * cfmakeraws THOSE. A side benefit: the returned snapshot carries
     * $original, so `$termios->makeRaw()->apply()` followed by
     * restore() works instead of hitting restore()'s LogicException door.
     */
    public function makeRaw(): self
    {
        $base = $this->captured ? $this : $this->current();
        $instance = $base->withCData($base->buf);
        $this->libc->cfmakeraw(\FFI::addr($instance->buf));
        $instance->captured = true;
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
     *
     * @throws \LogicException if no snapshot was captured via current()
     *                         first. Unlike SttyTermios — whose empty
     *                         saved-mode string is a legitimate "never
     *                         captured, nothing to restore" no-op — a
     *                         PosixTermios restore() is only ever reached
     *                         on the immutable snapshot current() returns.
     *                         A null $original therefore means the caller
     *                         is restoring the wrong instance (e.g. the
     *                         fresh constructor instance), which would
     *                         silently leave the terminal in raw mode. Fail
     *                         loudly rather than pretend the tty was reset.
     */
    public function restore(): void
    {
        if ($this->original === null) {
            throw new \LogicException(
                'PosixTermios::restore() called without a captured snapshot; '
                . 'call current() and restore() the returned instance.'
            );
        }
        \FFI::memcpy(\FFI::addr($this->buf), \FFI::addr($this->original->buf), self::BUFSIZE);
        $this->apply(self::TCSANOW);
    }

    /**
     * @see portable-pty.Termios.IsTty()
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
        $clone->buf = $this->libc->new('char[' . self::BUFSIZE . ']');
        \FFI::memcpy(\FFI::addr($clone->buf), \FFI::addr($newBuf), self::BUFSIZE);
        return $clone;
    }
}
