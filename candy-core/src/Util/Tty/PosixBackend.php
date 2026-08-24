<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Pty\TermiosFactory;

/**
 * POSIX TTY backend delegating to candy-pty for termios and size queries.
 *
 * Uses TermiosFactory (FFI primary, stty fallback) for raw mode and
 * SizeIoctl for terminal dimensions.
 *
 * Mirrors charmbracelet/bubbletea TtyBackend
 */
final class PosixBackend implements Backend
{
    /** @var resource */
    private $stream;

    /** @var Termios|null */
    private ?Termios $termios = null;

    /** @var Termios|null saved original termios for restore() */
    private ?Termios $saved = null;

    /**
     * PID that called {@see enableRawMode()} - {@see restore()} only
     * applies the saved termios when called from this SAME process. See
     * restore()'s docblock for why.
     */
    private ?int $ownerPid = null;

    /**
     * Injected Termios override (set when a caller wired one via
     * {@see \SugarCraft\Core\ProgramOptions::$termios}). When non-null
     * {@see enableRawMode()} uses it directly instead of resolving via
     * {@see TermiosFactory}; the host TTY is never touched. Test seam.
     */
    private readonly ?Termios $injectedTermios;

    /** Saved termios snapshot for restoreLast(). */
    private static ?\SugarCraft\Pty\Contract\Termios $rescueSnapshot = null;

    /**
     * @param resource|null $stream  defaults to STDIN
     * @param Termios|null  $termios optional pre-built Termios; when
     *                               null, {@see enableRawMode()} resolves
     *                               via {@see TermiosFactory}.
     */
    public function __construct($stream = null, ?Termios $termios = null)
    {
        $this->stream = $stream ?? STDIN;
        $this->injectedTermios = $termios;
    }

    public function isTty(): bool
    {
        return is_resource($this->stream) && stream_isatty($this->stream);
    }

    /**
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        if (!is_readable('/dev/tty') || !is_writable('/dev/tty')) {
            return null;
        }
        $in  = @fopen('/dev/tty', 'rb');
        $out = @fopen('/dev/tty', 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            return null;
        }
        return [$in, $out];
    }

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        // 1. FFI ioctl on the stream's fd (works for PTY slave).
        // The kernel's TIOCGWINSZ is the ground truth and is updated live on
        // every resize. We prefer it over the COLUMNS/LINES env vars because
        // those are frequently stale — bash only refreshes them for its own
        // prompt (and does not export them by default), and SSH/tmux/screen
        // sessions often export the size captured at login, which never
        // tracks a later resize. Trusting stale env produced frames sized to
        // the wrong (often halved) height. Env remains the fallback for the
        // no-tty case where the ioctl can't run.
        if ($this->isTty()) {
            $fd = (int) $this->stream;
            if ($fd >= 0) {
                try {
                    $result = SizeIoctl::query($fd);
                    // Validate: kernel returns 0 for unset fields on some emulators
                    if ($result['cols'] > 0 && $result['rows'] > 0) {
                        return ['cols' => $result['cols'], 'rows' => $result['rows']];
                    }
                } catch (\Throwable $e) {
                    // FFI ioctl failed — fall through to next method
                }
            }
        }

        // 2. Env vars (the only signal when stdout is not a tty, e.g. piped
        // or non-interactive; on a live tty the ioctl above already won).
        $cols = (int) (getenv('COLUMNS') ?: 0);
        $rows = (int) (getenv('LINES') ?: 0);
        if ($cols > 0 && $rows > 0) {
            return ['cols' => $cols, 'rows' => $rows];
        }

        // 3. /dev/tty — the controlling terminal (always has the real size)
        //
        // WHAT THIS ARM USED TO DO, AND WHY IT CHANGED
        //
        // WHAT IT DID: `$tty = self::openTty(); $ttyFd = (int) $tty[0];` and
        // then `SizeIoctl::query($ttyFd)`. An `(int)` cast of a PHP stream
        // yields its RESOURCE ID, which is not a file descriptor.
        //
        // WHAT IS TRUE NOW: the other members of this family are latent
        // because descriptors 0/1/2 name the same device in an ordinary
        // terminal, so asking the wrong number still returns the right
        // answer. This arm could never be: `openTty()` FRESHLY OPENS
        // /dev/tty, and a fresh handle's resource id can never equal its own
        // descriptor once the low numbers are taken. MEASURED, PHP 8.3.6,
        // under a real pty: the handle's resource id was 5 while its actual
        // descriptor was 4, and `posix_isatty()` gives OPPOSITE answers for
        // the two — false for 5, true for 4. `SizeIoctl::query()` opens with
        // exactly that `posix_isatty()` check, so this arm THREW on every
        // single invocation it ever had and fell through to the `stty`
        // shell-out below. It had never returned an answer.
        //
        // WHY THIS ARM STILL EARNS ITS PLACE: the reasoning above it is
        // correct and unchanged — the controlling terminal is the one device
        // that always carries the real size, and reaching it by ioctl rather
        // than by shelling out to `stty` is the whole point of arms 1 and 3.
        // Only the descriptor was wrong. It now asks libc to open /dev/tty,
        // which hands back a GENUINE descriptor rather than a number derived
        // from a PHP stream. There is no portable userland call that maps a
        // PHP stream to its descriptor, which is why the fix opens its own
        // rather than trying to recover one.
        //
        // `self::openTty()` is untouched and is NOT made dormant by this: it
        // is a {@see Backend} interface method and `Program` reaches it
        // through `Tty::openTty()` for its `openTty: true` option.
        $ttyFd = self::openTerminalDescriptor(self::CONTROLLING_TERMINAL);
        if ($ttyFd !== null) {
            try {
                $result = SizeIoctl::query($ttyFd);
                if ($result['cols'] > 0 && $result['rows'] > 0) {
                    return ['cols' => $result['cols'], 'rows' => $result['rows']];
                }
            } catch (\Throwable $e) {
                // /dev/tty query failed — fall through
            } finally {
                self::closeTerminalDescriptor($ttyFd);
            }
        }

        // 4. stty -F /dev/tty — queries the controlling terminal directly (most reliable)
        // This avoids the stdin redirection problem where "stty size" reads from pipe
        $sttyTty = trim((string) shell_exec('stty -F /dev/tty size 2>/dev/null'));
        if ($sttyTty !== '' && str_contains($sttyTty, ' ')) {
            [$sRows, $sCols] = explode(' ', $sttyTty, 2);
            if ((int) $sRows > 0 && (int) $sCols > 0) {
                return ['cols' => (int) $sCols, 'rows' => (int) $sRows];
            }
        }

        // 5. stty size fallback — works when stdin is the terminal
        $stty = trim((string) shell_exec('stty size 2>/dev/null'));
        if ($stty !== '' && str_contains($stty, ' ')) {
            [$sRows, $sCols] = explode(' ', $stty, 2);
            if ((int) $sRows > 0 && (int) $sCols > 0) {
                return ['cols' => (int) $sCols, 'rows' => (int) $sRows];
            }
        }

        // 6. Wtmp query — check last login's terminal size (rough proxy)
        $who = trim((string) shell_exec('who -a 2>/dev/null | grep -m1 pts/0'));
        if ($who !== '' && preg_match('/\d+\s+\d+\s+(\d+)\s+(\d+)/', $who, $m)) {
            // who format: user tty pts/0 ... rows cols
            if ((int) $m[1] > 0 && (int) $m[2] > 0) {
                return ['cols' => (int) $m[2], 'rows' => (int) $m[1]];
            }
        }

        // 7. Final fallback — reasonable default for modern terminals
        return ['cols' => 200, 'rows' => 60];
    }

    /**
     * The controlling terminal's device path.
     *
     * Named rather than inlined so {@see openTerminalDescriptor()} reads as
     * a general "open this terminal device" helper — which is what makes it
     * testable on a host that has no controlling terminal at all, by handing
     * it /dev/ptmx instead. See that method's doc-block.
     */
    private const CONTROLLING_TERMINAL = '/dev/tty';

    /**
     * `O_RDONLY`. Zero on both platforms candy-pty's libc cdef supports
     * (Linux and Darwin); it is the one open flag whose value is fixed by
     * POSIX rather than by the platform's <fcntl.h>.
     */
    private const O_RDONLY = 0;

    /**
     * Open a terminal device and return the GENUINE file descriptor, or null.
     *
     * This exists because {@see SizeIoctl::query()} and
     * {@see TermiosFactory::open()} both take an `int` descriptor and PHP
     * offers no portable way to get one out of a stream handle — an `(int)`
     * cast yields the resource id, a different number entirely. So rather
     * than deriving a descriptor from a stream, this asks libc for one
     * directly.
     *
     * @internal Test seam as well as an implementation detail: the parameter
     *           is what lets a test exercise this on a host with no
     *           controlling terminal. MEASURED, PHP 8.3.6, in a process whose
     *           /dev/tty open fails with ENXIO (no controlling terminal),
     *           three takes: `open('/dev/ptmx', O_RDONLY)` returns descriptor
     *           3 with `posix_isatty(3) === true`, while `/dev/tty` returns
     *           -1. So the positive half of this helper's contract is
     *           assertable everywhere, not only under a terminal.
     *
     * @return int|null a descriptor the caller MUST hand to
     *                  {@see closeTerminalDescriptor()}, or null when the
     *                  device cannot be opened (no controlling terminal, no
     *                  ext-ffi, no libc)
     */
    public static function openTerminalDescriptor(string $device): ?int
    {
        try {
            $fd = Libc::lib()->open($device, self::O_RDONLY);
        } catch (\Throwable) {
            // No ext-ffi, or libc would not load. SizeIoctl::query() needs
            // the same FFI handle, so there is nothing this arm could have
            // done with a descriptor anyway.
            return null;
        }

        return \is_int($fd) && $fd >= 0 ? $fd : null;
    }

    /**
     * Close a descriptor obtained from {@see openTerminalDescriptor()}.
     *
     * Paired rather than inlined so the `finally` at the call site cannot
     * drift away from the libc handle that produced the descriptor. A leaked
     * descriptor here would be per-`size()`-call, and `size()` is called on
     * every SIGWINCH.
     *
     * @internal
     */
    public static function closeTerminalDescriptor(int $fd): void
    {
        try {
            Libc::lib()->close($fd);
        } catch (\Throwable) {
            // Unreachable in practice: we only get here with a descriptor
            // that the same libc handle just returned. Swallowed rather than
            // propagated because size() must always answer.
        }
    }

    public function enableRawMode(): void
    {
        if ($this->termios !== null) {
            return;
        }

        if ($this->injectedTermios !== null) {
            $this->termios = $this->injectedTermios;
        } else {
            if (!$this->isTty()) {
                return;
            }
            $fd = (int) $this->stream;
            if ($fd < 0) {
                return;
            }
            $this->termios = TermiosFactory::open($fd);
        }

        $this->saved = $this->termios->current();
        $this->ownerPid = getmypid();
        $this->termios->makeRaw()->apply();
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, false);
        }
    }

    /**
     * Restore the termios captured by {@see enableRawMode()}.
     *
     * A `pcntl_fork()`'d child inherits a COPY of this object with $saved
     * already populated - termios settings live on the shared kernel TTY
     * device, not per-process, so if that child's own shutdown sequence
     * ever reaches here (a plain `exit()` runs PHP's normal destructor
     * chain), applying $saved would restore the PARENT's terminal to its
     * pre-raw-mode state the instant the child exits, even though the
     * parent's raw-mode session is still live. Only the process that
     * actually called enableRawMode() may take the terminal back out of
     * raw mode - a forked child silently skips the real syscall instead.
     */
    public function restore(): void
    {
        if ($this->saved === null) {
            return;
        }
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            $this->termios = null;
            $this->saved = null;
            $this->ownerPid = null;

            return;
        }
        $this->saved->apply();
        $this->termios = null;
        $this->saved = null;
        $this->ownerPid = null;
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, true);
        }
    }

    public function __destruct()
    {
        $this->restore();
    }

    public static function onResize(\Closure $onResize): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }
        // SIGWINCH = 28 on Linux; look it up portably.
        $sig = defined('SIGWINCH') ? SIGWINCH : 28;
        $tty = new self();
        return @\pcntl_signal($sig, static function () use ($tty, $onResize): void {
            $size = $tty->size();
            $onResize($size['cols'], $size['rows']);
        });
    }

    /**
     * @return int|false bitmask of dispatched signals (SIGNAL_RESIZE), or false if not available
     */
    public static function drainSignals(): int|false
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            return false;
        }

        // pcntl_signal_dispatch() returns true if any handler was invoked.
        // We treat that as equivalent to SIGNAL_RESIZE since drainSignals
        // on POSIX is only wired for SIGWINCH; a fired handler means a
        // resize was detected.
        return @\pcntl_signal_dispatch() ? self::SIGNAL_RESIZE : 0;
    }

    public static function restoreLast(): void
    {
        if (self::$rescueSnapshot !== null) {
            // Second+ call: restore saved termios.
            try {
                self::$rescueSnapshot->apply();
            } finally {
                self::$rescueSnapshot = null;
            }
            return;
        }
        // First call: save current state from STDIN.
        //
        // WHAT THE CODE UNDER THIS SENTENCE USED TO SAY, AND WHY IT MOVED
        //
        // WHAT IT SAID: `TermiosFactory::open((int) STDIN)`. The sentence
        // above it has always read "save current state from STDIN", and the
        // sentence was the half telling the truth about the intent.
        //
        // WHAT IS TRUE: an `(int)` cast of a PHP stream yields its RESOURCE
        // ID, and for the three standard streams that number is not the
        // descriptor. MEASURED, PHP 8.3.6, in a fresh CLI process, three
        // takes, identical every time: `(int) STDIN` is 1, `(int) STDOUT` is
        // 2 and `(int) STDERR` is 3, over descriptors 0, 1 and 2. So this
        // snapshotted descriptor 1 — STDOUT — and the whole rescue path was
        // saving and restoring the wrong end of the terminal. It looked
        // right in an ordinary terminal only because 0 and 1 name the same
        // device there.
        //
        // WHY THE FIX IS A LITERAL AND NOT A CONSTANT: `TermiosFactory::open()`
        // takes an `int` descriptor, and STDIN's descriptor is 0. There is no
        // PHP constant that holds it — `STDIN` holds the stream. candy-vcr's
        // `Cli\RecordCommand` already spells the same call `open(0)`.
        try {
            self::$rescueSnapshot = TermiosFactory::open(0)->current();
        } catch (\Throwable) {
            // Descriptor 0 is closed or is not a terminal (CI runner):
            // silently no-op.
        }
    }
}
