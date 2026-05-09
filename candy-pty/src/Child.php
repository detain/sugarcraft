<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Handle to a child process spawned through {@see Pty::spawn()}.
 *
 * Wraps the {@see proc_open()} resource so {@see exited()} can poll
 * non-blockingly via `proc_get_status()` and {@see wait()} can block
 * until termination. The exit code is captured the first time it
 * becomes available and cached — subsequent calls are idempotent.
 *
 * Mirrors charmbracelet/x/xpty.Cmd's lifecycle hooks (`Wait`,
 * `ProcessState.Exited`).
 */
final class Child
{
    /** @var resource|null */
    private $process;

    private ?int $exitCode = null;

    /**
     * @param resource $process the proc_open() handle
     */
    public function __construct(
        public readonly int $pid,
        $process,
    ) {
        if (!\is_resource($process)) {
            throw new \InvalidArgumentException('Child requires a live proc_open() resource');
        }
        $this->process = $process;
    }

    /**
     * Non-blocking check. Returns true once the kernel has reported
     * termination at least once (the exit code is cached at that point).
     */
    public function exited(): bool
    {
        if ($this->exitCode !== null) {
            return true;
        }
        if (!\is_resource($this->process)) {
            return true;
        }

        $status = \proc_get_status($this->process);
        if ($status['running'] === false) {
            $this->exitCode = (int) $status['exitcode'];
            return true;
        }
        return false;
    }

    /**
     * Block until the child exits and return its exit code.
     *
     * Polls `proc_get_status()` at 10 ms intervals because PHP does
     * not expose a blocking-`waitpid` wrapper — `proc_close()` does
     * block, but it returns -1 when the kernel has already reaped
     * the child via a prior `proc_get_status()` call, costing us the
     * actual exit code. Polling first then closing keeps the value.
     */
    public function wait(): int
    {
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }
        if (!\is_resource($this->process)) {
            return $this->exitCode ?? 0;
        }

        while (true) {
            $status = \proc_get_status($this->process);
            if ($status['running'] === false) {
                $this->exitCode = (int) $status['exitcode'];
                break;
            }
            \usleep(10_000);
        }

        \proc_close($this->process);
        $this->process = null;
        return $this->exitCode;
    }

    /**
     * Cached exit code or `null` if the child is still running.
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }
}
