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
        $cols = (int) (getenv('COLUMNS') ?: 0);
        $rows = (int) (getenv('LINES') ?: 0);
        if ($cols > 0 && $rows > 0) {
            return ['cols' => $cols, 'rows' => $rows];
        }
        if ($this->isTty()) {
            $fd = (int) $this->stream;
            if ($fd >= 0) {
                try {
                    $result = SizeIoctl::query($fd);
                    return ['cols' => $result['cols'], 'rows' => $result['rows']];
                } catch (\RuntimeException) {
                }
            }
        }
        // Non-TTY stream: try /dev/tty directly.
        $tty = self::openTty();
        if ($tty !== null) {
            try {
                $ttyFd = (int) $tty[0];
                $result = SizeIoctl::query($ttyFd);
                fclose($tty[0]);
                fclose($tty[1]);
                return ['cols' => $result['cols'], 'rows' => $result['rows']];
            } catch (\RuntimeException) {
                fclose($tty[0]);
                fclose($tty[1]);
            }
        }
        return ['cols' => 80, 'rows' => 24];
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
