<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\Process;
use SugarCraft\Pty\Lang;
use SugarCraft\Pty\PtyException;

/**
 * Handle to a non-PTY child process spawned via `proc_open()`.
 *
 * Counterpart to {@see PosixChild}: that class wraps a child whose
 * stdio is bound to a slave PTY; this one wraps a child whose stdio
 * is `/dev/null`-stdin plus inherited-or-piped stdout/stderr. It is
 * the runtime backbone that {@see \SugarCraft\ScriptUi\Process\RealProcess}
 * will migrate to.
 *
 * stdin is deliberately bound to `/dev/null` — if it were inherited
 * from the parent, a candy-scriptui process running inside a sugar-ssh-server
 * SSH session would steal the supervisor's stdin and starve the
 * outer event loop.
 *
 * Mirrors creack/pty.Cmd and the portable-pty.Process lifecycle.
 *
 * @see \SugarCraft\Pty\Posix\PosixChild for the PTY-attached sibling.
 * @see \SugarCraft\ScriptUi\Process\RealProcess for the legacy non-PTY shape this replaces.
 */
final class PosixProcess implements Process
{
    use ChildPollTrait;

    /**
     * Captured pipes; null entries mean "not captured / inherited".
     *
     * @var array{0: ?resource, 1: ?resource}
     */
    private array $pipes = [null, null];

    private string $bufferedStdout = '';

    private string $bufferedStderr = '';

    /**
     * @param resource       $process    live `proc_open()` resource
     * @param resource|null  $stdoutPipe captured stdout pipe (non-blocking)
     * @param resource|null  $stderrPipe captured stderr pipe (non-blocking)
     */
    public function __construct(
        public readonly int $pid,
        $process,
        $stdoutPipe = null,
        $stderrPipe = null,
    ) {
        if (!\is_resource($process)) {
            throw new \InvalidArgumentException('PosixProcess requires a live proc_open() resource');
        }
        $this->process = $process;
        $this->pipes[0] = \is_resource($stdoutPipe) ? $stdoutPipe : null;
        $this->pipes[1] = \is_resource($stderrPipe) ? $stderrPipe : null;
    }

    /**
     * Spawn a non-PTY child. `$captureStdout` / `$captureStderr` decide
     * whether stdout/stderr are captured into in-memory buffers (true)
     * or inherited from the parent's STDOUT / STDERR (false).
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env null inherits parent env
     * @see creack/pty.Start()
     */
    public static function spawn(
        array $cmd,
        ?array $env = null,
        bool $captureStdout = false,
        bool $captureStderr = false,
    ): self {
        if ($cmd === []) {
            throw new \InvalidArgumentException('PosixProcess::spawn requires a non-empty command');
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $captureStdout ? ['pipe', 'w'] : \STDOUT,
            2 => $captureStderr ? ['pipe', 'w'] : \STDERR,
        ];
        $pipes = [];

        $process = @\proc_open($cmd, $descriptors, $pipes, null, $env, null);
        if (!\is_resource($process)) {
            throw new PtyException(Lang::t('process.spawn_failed', [
                'cmd' => \implode(' ', $cmd),
            ]));
        }

        $status = \proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            \proc_close($process);
            throw new PtyException(Lang::t('process.no_pid', [
                'cmd' => \implode(' ', $cmd),
            ]));
        }

        $stdoutPipe = $captureStdout && isset($pipes[1]) && \is_resource($pipes[1]) ? $pipes[1] : null;
        $stderrPipe = $captureStderr && isset($pipes[2]) && \is_resource($pipes[2]) ? $pipes[2] : null;

        // Non-blocking pipes prevent the parent's drain loop from stalling
        // when the child has produced no output yet.
        if ($stdoutPipe !== null) {
            \stream_set_blocking($stdoutPipe, false);
        }
        if ($stderrPipe !== null) {
            \stream_set_blocking($stderrPipe, false);
        }

        return new self($pid, $process, $stdoutPipe, $stderrPipe);
    }

    /**
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void
    {
        // Post-reap pid-reuse guard (see PosixChild::kill()).
        if ($this->exited()) {
            return;
        }
        // Libc::kill(), not a bare posix_kill(): ext-posix is optional.
        if (!\SugarCraft\Pty\Libc::kill($this->pid, $signal) && !$this->exited()) {
            throw new \SugarCraft\Pty\PtyException(
                "kill(signal {$signal}) failed for pid {$this->pid}",
            );
        }
    }

    public function stdoutBytes(): string
    {
        $this->drainCapturePipes();
        return $this->bufferedStdout;
    }

    public function stderrBytes(): string
    {
        $this->drainCapturePipes();
        return $this->bufferedStderr;
    }

    /**
     * Override of {@see ChildPollTrait::wait()}: drains the captured
     * pipes every poll iteration so the child never blocks writing
     * into a full pipe buffer while we're sleeping. Without this,
     * `/bin/sh -c 'yes | head'`-style commands deadlock.
     *
     * E717 contract holds unchanged: the loop carries no internal
     * deadline; callers MUST bound (exited() probe + caller-side kill).
     *
     * Poll cadence and reap mirror the trait's wait() (`usleep(10_000)`
     * loop, `proc_close()`), with the added per-iteration drain; unlike
     * the trait, this variant does not take the FFI `waitpid(WNOHANG)`
     * fast path — it must drain between probes, and a drain racing a
     * waitpid reap could truncate the capture.
     *
     * @see creack/pty.Cmd.Wait()
     */
    public function wait(): int
    {
        if ($this->exitCode !== null) {
            // A cached exit code must still settle the handles: capture
            // pipes get one final drain (bytes produced before the
            // earlier reap stay readable), and an un-closed proc
            // resource is reaped here rather than lingering to the
            // destructor.
            $this->drainCapturePipes();
            $this->closeCapturePipes();
            if (\is_resource($this->process)) {
                \proc_close($this->process);
                $this->process = null;
            }
            return $this->exitCode;
        }
        if (!\is_resource($this->process)) {
            // Destructor (or an external reap) already closed the
            // handle: report the captured code, or -1 — NEVER a
            // fabricated success. Matches the trait's wait() tail.
            return $this->exitCode ?? -1;
        }

        while (true) {
            $this->drainCapturePipes();
            $status = \proc_get_status($this->process);
            if ($status['running'] === false) {
                $this->exitCode = (int) $status['exitcode'];
                break;
            }
            \usleep(10_000);
        }

        $this->drainCapturePipes();
        $this->closeCapturePipes();
        \proc_close($this->process);
        $this->process = null;
        return $this->exitCode;
    }

    public function __destruct()
    {
        $this->drainCapturePipes();
        $this->closeCapturePipes();
        $this->pollDestruct();
    }

    private function drainCapturePipes(): void
    {
        if ($this->pipes[0] !== null && \is_resource($this->pipes[0])) {
            $chunk = @\stream_get_contents($this->pipes[0]);
            if (\is_string($chunk)) {
                $this->bufferedStdout .= $chunk;
            }
        }
        if ($this->pipes[1] !== null && \is_resource($this->pipes[1])) {
            $chunk = @\stream_get_contents($this->pipes[1]);
            if (\is_string($chunk)) {
                $this->bufferedStderr .= $chunk;
            }
        }
    }

    private function closeCapturePipes(): void
    {
        foreach ($this->pipes as $i => $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
            $this->pipes[$i] = null;
        }
    }
}
