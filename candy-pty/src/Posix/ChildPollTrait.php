<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

/**
 * Package-private trait shared by {@see \SugarCraft\Pty\Child} and any other
 * candy-pty handle that owns a `proc_open()` resource and needs to expose the
 * canonical waitpid-style lifecycle (`exited()`, `wait()`, `exitCode()`,
 * `pid()`).
 *
 * Mirrors charmbracelet/x/xpty.Cmd's waitpid semantics — `running=false`
 * triggers `exitcode` capture before `proc_close()`. PHP's `proc_close()`
 * returns the wait-status; `-1` means the child was already reaped, which is
 * harmless and intentionally ignored here.
 *
 * Intended only for candy-pty internal use; PHP has no real access control on
 * traits, so this is a doc-comment convention.
 *
 * @internal
 *
 * @see \SugarCraft\Pty\Child
 * @see \SugarCraft\Pty\Posix\PosixChild
 */
trait ChildPollTrait
{
    /** @var resource|null */
    private $process;

    private ?int $exitCode = null;

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

    /**
     * Zombie-reaper safety net for the consuming class's `__destruct`.
     *
     * PHP traits do not compose `__destruct` cleanly with the consuming
     * class (the trait method is shadowed if the class declares its own).
     * Each consumer must therefore define its own `__destruct` that
     * delegates here.
     *
     * Suppression around `proc_get_status()` / `proc_close()` is deliberate:
     * destructors MUST NOT throw, and `proc_close()` returning `-1` for an
     * already-reaped child is expected (see class doc-comment).
     */
    protected function pollDestruct(): void
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
