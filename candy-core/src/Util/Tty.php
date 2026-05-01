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
