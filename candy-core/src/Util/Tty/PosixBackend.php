<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * POSIX shell-out TTY backend.
 *
 * Uses `stty` to query and manipulate terminal attributes.  Suitable
 * for Linux, macOS, BSD, and any POSIX-conformant environment that
 * provides `/dev/tty` and the `stty` utility.
 */
final class PosixBackend implements Backend
{
    /** @var resource */
    private $stream;
    private ?string $savedSttyState = null;

    /** @param resource|null $stream defaults to STDIN */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDIN;
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
        if ($this->isTty() && self::hasStty()) {
            $out = @shell_exec('stty size 2>/dev/null');
            if (is_string($out) && preg_match('/^(\d+)\s+(\d+)/', trim($out), $m) === 1) {
                return ['cols' => (int) $m[2], 'rows' => (int) $m[1]];
            }
        }
        return ['cols' => 80, 'rows' => 24];
    }

    public function enableRawMode(): void
    {
        if ($this->savedSttyState !== null || !$this->isTty() || !self::hasStty()) {
            return;
        }
        $saved = @shell_exec('stty -g 2>/dev/null');
        if (!is_string($saved)) {
            return;
        }
        $this->savedSttyState = trim($saved);
        @shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, false);
        }
    }

    public function restore(): void
    {
        if ($this->savedSttyState === null) {
            return;
        }
        @shell_exec('stty ' . escapeshellarg($this->savedSttyState) . ' 2>/dev/null');
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, true);
        }
        $this->savedSttyState = null;
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

    public static function drainSignals(): bool
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            return false;
        }
        return @\pcntl_signal_dispatch();
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
