<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use RuntimeException;
use SugarCraft\Pty\Contract\Termios;

/**
 * stty shell-out fallback for termios when ext-ffi is unavailable.
 *
 * Uses `stty -F /dev/fd/<n>` (Linux) or `stty -f /dev/fd/<n>` (macOS)
 * to operate on a raw file descriptor without requiring FFI.
 *
 * Mirrors portable-pty.Termios
 */
final class SttyTermios implements Termios
{
    public const TCSANOW = 0;
    public const TCSADRAIN = 1;
    public const TCSAFLUSH = 2;

    private int $fd;
    private string $savedMode;
    private bool $raw = false;

    public function __construct(int $fd)
    {
        $this->fd = $fd;
        $this->savedMode = '';
    }

    public function current(): self
    {
        $out = $this->runStty('-g');
        $instance = new self($this->fd);
        $instance->savedMode = \trim($out);
        return $instance;
    }

    public function makeRaw(): self
    {
        $clone = clone $this;
        $clone->raw = true;
        return $clone;
    }

    public function apply(int $when = self::TCSANOW): void
    {
        if (!$this->raw) {
            return;
        }
        $this->runStty('raw', '-echo');
    }

    public function restore(): void
    {
        if ($this->savedMode === '') {
            return;
        }
        $this->runStty($this->savedMode);
    }

    public function isAtty(): bool
    {
        if (\function_exists('posix_isatty')) {
            return \posix_isatty($this->fd);
        }
        return false;
    }

    public function fd(): int
    {
        return $this->fd;
    }

    /**
     * Build the stty command arguments for the current OS.
     *
     * Linux uses `-F` to specify the fd path; macOS uses `-f`.
     */
    private function sttyArgs(string ...$args): array
    {
        $fdArg = PHP_OS_FAMILY === 'Darwin'
            ? ['-f', '/dev/fd/' . $this->fd]
            : ['-F', '/dev/fd/' . $this->fd];

        return ['stty', ...$fdArg, ...$args];
    }

    /**
     * Run stty with the given arguments and capture stdout.
     *
     * @param string ...$args stty subcommand and flags
     * @return string stdout content
     * @throws RuntimeException if stty exits non-zero
     */
    private function runStty(string ...$args): string
    {
        $cmd = $this->sttyArgs(...$args);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes);

        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($proc);

        if ($rc !== 0) {
            throw new RuntimeException(
                'stty ' . \implode(' ', $args) . ' failed with rc=' . $rc . ': ' . \trim($err)
            );
        }

        return $out ?? '';
    }
}
