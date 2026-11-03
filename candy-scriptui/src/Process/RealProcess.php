<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

use SugarCraft\Pty\Posix\PosixProcess;

/**
 * @deprecated since v0.x; new callers should depend on
 *             `SugarCraft\Pty\Posix\PosixProcess` directly. This class
 *             exists to preserve the in-package `Process` shape
 *             (`exitCode()` / `terminate()` / `close()` / `stdout()` /
 *             `stderr()`) used by SpinModel + SpinCommand, while moving
 *             the proc_open polling lifecycle into sugar-pty's
 *             {@see \SugarCraft\Pty\Posix\ChildPollTrait}.
 *
 * @see PosixProcess for the canonical non-PTY spawn handle.
 */
final class RealProcess implements Process
{
    private bool $closed = false;
    private bool $terminated = false;
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

    /** Seconds a SIGTERM'd child is given to exit before {@see close()} escalates to SIGKILL. */
    public const TERM_GRACE_SECONDS = 2.0;

    /**
     * Signal the child with SIGTERM. Lifecycle split: terminate() only
     * *signals*; close() is the sole *reaping* path and it bounds the
     * wait (grace + SIGKILL escalation), so close-after-terminate neither
     * double-kills nor hangs. Idempotence guards: skip when the handle
     * was already reaped ($closed), when a SIGTERM was already sent
     * ($terminated), or when the child already exited on its own —
     * signalling a dead PID risks hitting an unrelated recycled process.
     *
     * No ext-posix availability gate here: the inner handle's kill()
     * dispatches through sugar-pty's FFI `Libc::kill`, NOT the posix_kill()
     * function, so the previous existence-check gate disabled the signal
     * (and hung close()) on FFI-only hosts for no reason.
     */
    public function terminate(): void
    {
        if ($this->closed || $this->terminated || $this->exitCode() !== null) {
            return;
        }
        $this->terminated = true;
        $this->inner->kill(\defined('SIGTERM') ? \SIGTERM : 15);
    }

    /**
     * Reap the child, bounded. If terminate() asked the child to stop and
     * it is still running, give it {@see TERM_GRACE_SECONDS} to honour
     * SIGTERM, then escalate to SIGKILL — the fleet-standard TERM→grace→
     * KILL ladder. Without this, a TERM-ignoring child hangs the inner
     * wait()'s unbounded poll loop forever (measured: `trap "" TERM`
     * children never returned).
     */
    public function close(): int
    {
        if ($this->closed) {
            return $this->cachedExit ?? 0;
        }
        if ($this->terminated && !$this->inner->exited()) {
            $deadline = \microtime(true) + self::TERM_GRACE_SECONDS;
            while (!$this->inner->exited() && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            if (!$this->inner->exited()) {
                $this->inner->kill(\defined('SIGKILL') ? \SIGKILL : 9);
            }
        }
        $code = $this->inner->wait();
        $this->closed = true;
        $this->cachedExit = $code;
        return $code;
    }
}
