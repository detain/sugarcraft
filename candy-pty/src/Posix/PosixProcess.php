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
 * the runtime backbone that {@see \SugarCraft\Shell\Process\RealProcess}
 * will migrate to in plan step P3.3.
 *
 * stdin is deliberately bound to `/dev/null` — if it were inherited
 * from the parent, a candy-shell process running inside a candy-wish
 * SSH session would steal the supervisor's stdin and starve the
 * outer event loop.
 *
 * Mirrors creack/pty.Cmd and the portable-pty.Process lifecycle.
 *
 * @see \SugarCraft\Pty\Posix\PosixChild for the PTY-attached sibling.
 * @see \SugarCraft\Shell\Process\RealProcess for the legacy non-PTY shape this replaces.
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
        \posix_kill($this->pid, $signal);
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
     * Timing matches the trait's wait() byte-for-byte aside from the
     * drain call — same `usleep(10_000)`, same `proc_close()` reap.
     *
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
