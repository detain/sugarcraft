<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * The one status reader for a ChildRun child: every proc_get_status() on the child
 * handle, from the spawn through run() and launch(), goes through here.
 *
 * The read that first sees the child gone is the one that reaps it, and only that
 * read carries the signal of a signal death: on PHP 8.3 a later proc_get_status()
 * reports signaled=false. A child that dies within the spawn's own reads (the group
 * read, the pid read for onSpawn) used to lose its signal number to whichever read
 * threw the status away. This keeps that first exited status, and every later read
 * is answered from it instead of asking the kernel again.
 *
 * Internal to ChildRun and ChildRunLaunch; not a public entry point.
 *
 * @internal
 */
final class ChildStatus
{
    /** @var null|array<string, mixed> The status the first read that saw the exit returned */
    private ?array $exit = null;

    private int $pid = 0;

    /** @param resource $proc the value a proc_open() call returned */
    public function __construct(private readonly mixed $proc)
    {
    }

    /**
     * The child's status: the live read while it runs, the recorded exit once any
     * read has seen it gone. A handle already closed reads as not running, but is
     * not recorded as an exit: there is no code or signal to report for it.
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if ($this->exit !== null) {
            return $this->exit;
        }
        $status = \is_resource($this->proc) ? @\proc_get_status($this->proc) : false;
        if (!\is_array($status)) {
            return ['pid' => $this->pid, 'running' => false, 'exitcode' => -1, 'signaled' => false];
        }
        $this->pid = (int) $status['pid'];
        if ($status['running'] === false) {
            $this->exit = $status;
        }

        return $status;
    }

    public function running(): bool
    {
        return $this->read()['running'] === true;
    }

    /** The child's pid, read through the recorder so the read cannot discard an exit. */
    public function pid(): int
    {
        if ($this->pid === 0) {
            $this->read();
        }

        return $this->pid;
    }

    public function exited(): bool
    {
        return $this->exit !== null;
    }

    /** The raw exit code a reaping read saw (-1 for a signal death), or null while not seen gone. */
    public function exitCode(): ?int
    {
        return $this->exit === null ? null : (int) $this->exit['exitcode'];
    }

    /** The number of the signal that ended the child, or null for an exit or a child not seen gone. */
    public function signal(): ?int
    {
        if ($this->exit === null || ($this->exit['signaled'] ?? false) !== true || !isset($this->exit['termsig'])) {
            return null;
        }

        return (int) $this->exit['termsig'];
    }

    /**
     * The group the live child leads, Containment::groupId()'s reading taken through
     * this recorder; null once the child is gone, since a dead pid answers nothing.
     */
    public function groupId(): ?int
    {
        if (!\function_exists('posix_getpgid')) {
            return null;
        }
        $status = $this->read();
        $pid = (int) $status['pid'];
        if ($status['running'] !== true || $pid <= 0) {
            return null;
        }

        return \posix_getpgid($pid) === $pid ? $pid : null;
    }
}
