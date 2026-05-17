<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Process;

use SugarCraft\Pty\Posix\PosixProcess;

/**
 * @deprecated since v0.x; new callers should depend on
 *             `SugarCraft\Pty\Posix\PosixProcess` directly. This class
 *             exists to preserve the in-package `Process` shape
 *             (`exitCode()` / `terminate()` / `close()` / `stdout()` /
 *             `stderr()`) used by SpinModel + SpinCommand, while moving
 *             the proc_open polling lifecycle into candy-pty's
 *             {@see \SugarCraft\Pty\Posix\ChildPollTrait}.
 *
 * @see PosixProcess for the canonical non-PTY spawn handle.
 */
final class RealProcess implements Process
{
    private bool $closed = false;
    private ?int $cachedExit = null;

    public function __construct(
        private readonly PosixProcess $inner,
    ) {}

    public static function spawn(
        array $command,
        bool $captureStdout = false,
        bool $captureStderr = false,
    ): self {
        return new self(PosixProcess::spawn(
            cmd: $command,
            env: null,
            captureStdout: $captureStdout,
            captureStderr: $captureStderr,
        ));
    }

    public function pid(): int                  { return $this->inner->pid(); }
    public function exited(): bool               { return $this->inner->exited(); }
    public function wait(): int                  { return $this->inner->wait(); }
    public function kill(int $signal): void     { $this->inner->kill($signal); }
    public function exitCode(): ?int
    {
        if ($this->cachedExit !== null) {
            return $this->cachedExit;
        }
        if (!$this->inner->exited()) {
            return null;
        }
        $this->cachedExit = $this->inner->exitCode();
        return $this->cachedExit;
    }

    public function stdout(): string             { return $this->inner->stdoutBytes(); }
    public function stderr(): string             { return $this->inner->stderrBytes(); }
    public function stdoutBytes(): string         { return $this->inner->stdoutBytes(); }
    public function stderrBytes(): string         { return $this->inner->stderrBytes(); }

    public function terminate(): void
    {
        if ($this->closed || $this->cachedExit !== null) {
            return;
        }
        if (\function_exists('posix_kill')) {
            $this->inner->kill(\defined('SIGTERM') ? \SIGTERM : 15);
        }
    }

    public function close(): int
    {
        if ($this->closed) {
            return $this->cachedExit ?? 0;
        }
        $code = $this->inner->wait();
        $this->closed = true;
        $this->cachedExit = $code;
        return $code;
    }
}
