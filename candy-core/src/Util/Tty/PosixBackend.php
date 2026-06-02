<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

use SugarCraft\Pty\Contract\Termios;
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
        $tty = self::openTty();
        if ($tty !== null) {
            try {
                $ttyFd = (int) $tty[0];
                $result = SizeIoctl::query($ttyFd);
                fclose($tty[0]);
                fclose($tty[1]);
                if ($result['cols'] > 0 && $result['rows'] > 0) {
                    return ['cols' => $result['cols'], 'rows' => $result['rows']];
                }
            } catch (\Throwable $e) {
                // /dev/tty query failed — fall through
                if (is_resource($tty[0])) {
                    @fclose($tty[0]);
                }
                if (is_resource($tty[1])) {
                    @fclose($tty[1]);
                }
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
        $this->termios->makeRaw()->apply();
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, false);
        }
    }

    public function restore(): void
    {
        if ($this->saved === null) {
            return;
        }
        $this->saved->apply();
        $this->termios = null;
        $this->saved = null;
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
        try {
            self::$rescueSnapshot = TermiosFactory::open((int) STDIN)->current();
        } catch (\Throwable) {
            // STDIN closed (CI runner): silently no-op.
        }
    }
}
