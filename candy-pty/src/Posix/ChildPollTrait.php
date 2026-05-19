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

    private const WNOHANG = 1;

    /**
     * Try to reap an exited child via waitpid() FFI (non-blocking).
     *
     * Returns the exit code if the child has exited, null if still
     * running or FFI is unavailable (falls back to proc_get_status).
     *
     * @internal
     */
    private function tryWaitpid(int $pid): ?int
    {
        static $libc = null;
        if ($libc === null) {
            try {
                $libc = \SugarCraft\Pty\Libc::lib();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            // Allocate int[1] array, pass addr of first element as int*.
            $statusArray = $libc->new('int[1]');
            $result = $libc->waitpid($pid, \FFI::addr($statusArray[0]), self::WNOHANG);
        } catch (\Throwable) {
            return null;
        }

        // result > 0 means the child has exited (returns the pid)
        // result === 0 means WNOHANG and child hasn't exited yet
        // result < 0 means error
        if ($result <= 0) {
            return null;
        }

        // $statusArray[0] is the int that waitpid wrote to.
        // For normally-exited process: status = exit_code (0-255)
        // For signal-terminated process: status = signal_number (1-127)
        // Convention: exit code for signal death is 128 + signal_number.
        $statusVal = (int)$statusArray[0];
        $signal = $statusVal & 0x7F;
        if ($signal !== 0) {
            // Signal-terminated: 128 + signal number.
            return 128 + $signal;
        }
        return ($statusVal >> 8) & 0xFF;
    }

    /**
     * @see creack/pty.Cmd.ProcessState
     */
    public function exited(): bool
    {
        if ($this->exitCode !== null) {
            return true;
        }
        if (!\is_resource($this->process)) {
            return true;
        }

        // Fast path: use waitpid FFI for sub-millisecond detection.
        $exitCode = $this->tryWaitpid($this->pid);
        if ($exitCode !== null) {
            $this->exitCode = $exitCode;
            return true;
        }

        // Fallback: proc_get_status poll.
        $status = \proc_get_status($this->process);
        if ($status['running'] === false) {
            // Note: exitcode is -1 if the child has already been reaped
            // by a prior waitpid call; only use it if it's a real exit code
            // and we haven't already captured the exit code.
            $code = (int) $status['exitcode'];
            if ($code >= 0 && $this->exitCode === null) {
                $this->exitCode = $code;
            }
            return true;
        }
        return false;
    }

    /**
     * @see creack/pty.Cmd.Wait()
     */
    public function wait(): int
    {
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }
        if (!\is_resource($this->process)) {
            return $this->exitCode ?? 0;
        }

        // Fast path: use waitpid FFI for sub-millisecond detection.
        $exitCode = $this->tryWaitpid($this->pid);
        if ($exitCode !== null) {
            $this->exitCode = $exitCode;
            \proc_close($this->process);
            $this->process = null;
            return $this->exitCode;
        }

        while (true) {
            // Try waitpid first on each iteration.
            $exitCode = $this->tryWaitpid($this->pid);
            if ($exitCode !== null) {
                $this->exitCode = $exitCode;
                break;
            }
            $status = \proc_get_status($this->process);
            if ($status['running'] === false) {
                // Note: exitcode is -1 if the child has already been reaped
                // by a prior waitpid call; only use it if it's a real exit code
                // and we haven't already captured the exit code.
                $code = (int) $status['exitcode'];
                if ($code >= 0 && $this->exitCode === null) {
                    $this->exitCode = $code;
                }
                break;
            }
            \usleep(10_000);
        }

        \proc_close($this->process);
        $this->process = null;
        return $this->exitCode;
    }

    /**
     * @see creack/pty.Cmd.ExitCode()
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @see creack/pty.Cmd.Pid()
     */
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
