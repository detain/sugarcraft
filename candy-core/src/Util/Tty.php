<?php

declare(strict_types=1);

namespace CandyCore\Core\Util;

/**
 * Minimal portable TTY control. Uses `stty` shell-out on POSIX; FFI/termios
 * is a future optimization. Windows support is deferred until VT-mode toggling
 * is wired up in CandyCore\Core\Program.
 */
final class Tty
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
     * Open the controlling terminal directly (`/dev/tty`) so a program
     * can read keys when stdin is piped from a file or another
     * process. Mirrors Bubble Tea v2's `OpenTTY()`.
     *
     * Returns `[input, output]` — both are pointers at the same
     * `/dev/tty` device, opened separately so each can be configured
     * independently. Returns `null` on platforms without `/dev/tty`
     * (Windows, some sandboxed envs) so callers can fall back to
     * STDIN/STDOUT.
     *
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return null;
        }
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

    private static function hasStty(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return $cached = false;
        }
        $out = @shell_exec('command -v stty 2>/dev/null');
        return $cached = is_string($out) && trim($out) !== '';
    }
}
