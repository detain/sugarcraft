<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Handle to a child process spawned through {@see Pty::spawn()}.
 *
 * @deprecated since v0.x; use SugarCraft\Pty\Posix\PosixChild directly.
 *             Will be removed in v2.0. This class is a backward-
 *             compatible alias for PosixChild.
 *
 * Mirrors charmbracelet/x/xpty.Cmd's lifecycle hooks (`Wait`,
 * `ProcessState.Exited`).
 */
class Child
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

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function __destruct()
    {
        if (!\is_resource($this->process)) {
            return;
        }
        $status = @\proc_get_status($this->process);
        if (\is_array($status) && ($status['running'] ?? true) === false) {
            @\proc_close($this->process);
            $this->process = null;
        }
    }
}
