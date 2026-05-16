<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Process;

use SugarCraft\Pty\Posix\PosixProcess;

/**
 * Thin adapter that satisfies candy-shell's {@see Process} interface
 * by delegating to {@see PosixProcess} from candy-pty.
 *
 * @deprecated since v0.x; new callers should depend on
 *             `SugarCraft\Pty\Posix\PosixProcess` directly. This class
 *             exists to preserve the in-package `Process` shape
 *             (`exitCode()` / `terminate()` / `close()` / `stdout()` /
 *             `stderr()`) used by SpinModel + SpinCommand, while moving
 *             the proc_open polling lifecycle into candy-pty's
 *             {@see \SugarCraft\Pty\Posix\ChildPollTrait}.
 *
 * @see PosixProcess for the canonical non-PTY spawn handle.
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P3.3)
 */
final class RealProcess implements Process
{
    private bool $closed = false;
    private ?int $cachedExit = null;

    public function __construct(
        private readonly PosixProcess $inner,
    ) {}

    /**
     * @param list<string> $command
     */
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

    public function stdout(): string
    {
        return $this->inner->stdoutBytes();
    }

    public function stderr(): string
    {
        return $this->inner->stderrBytes();
    }

    public function terminate(): void
    {
        if ($this->closed || $this->cachedExit !== null) {
            return;
        }
        if (\function_exists('posix_kill')) {
            $this->inner->kill(\defined('SIGTERM') ? \SIGTERM : 15);
        }
    }

    /**
     * Reap the OS process handle. Idempotent — second call returns the
     * cached exit code without re-reaping. Required so SpinModel can
     * call close() in both happy-path and error-path branches without
     * worrying about double-reap.
     */
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
