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

    /** Saved stty state for restoreLast(). */
    private static ?string $lastSttyState = null;

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
            if (is_resource($in))  fclose($in);
            if (is_resource($out)) fclose($out);
            return null;
        }
        return [$in, $out];
    }

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        $cols = (int) (getenv('COLUMNS') ?: 0);
        $rows = (int) (getenv('LINES')   ?: 0);
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
            if (self::hasStty()) {
                $out = @shell_exec('stty size 2>/dev/null');
                if (is_string($out) && preg_match('/^(\d+)\s+(\d+)/', trim($out), $m) === 1) {
                    return ['cols' => (int) $m[2], 'rows' => (int) $m[1]];
                }
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
        if (self::$lastSttyState !== null) {
            // Second+ call: actually restore.
            @shell_exec('stty ' . escapeshellarg(self::$lastSttyState) . ' 2>/dev/null');
            self::$lastSttyState = null;
            return;
        }
        // First call: save current state.
        if (is_resource(STDIN) && stream_isatty(STDIN) && self::hasStty()) {
            $saved = @shell_exec('stty -g 2>/dev/null');
            if (is_string($saved)) {
                self::$lastSttyState = trim($saved);
            }
        }
    }

    private static function hasStty(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $out = @shell_exec('command -v stty 2>/dev/null');
        return $cached = is_string($out) && trim($out) !== '';
    }
}
