<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;

/**
 * The canonical non-PTY child handle for candy-scriptui: one copy owns
 * the whole lifecycle (spawn → drain → signal → reap) so every fix in the
 * spin process chain lands exactly once.
 *
 * Process-lifecycle consolidation (scriptui-01, gate G1 default-leg).
 * The class used to be a deprecated-marker shim over sugar-pty's
 * PosixProcess whose internal private drain was only reachable from
 * wait() and the
 * full-buffer accessors — every lifecycle fix (the 64KiB run-loop pump,
 * group-kill, the finally/destructor reaps) either landed in the wrong
 * copy or needed a private surface this package cannot widen without an
 * owned seat. The alternative consolidation path (fold INTO sugar-pty's
 * PosixProcess + adapter) is blocked on that lib's public pump-vocabulary
 * rider leg landing first; when it does, ProcessContractTest pins the
 * interface this class must keep, so the fold becomes provably safe.
 *
 * Mirrors charmbracelet/gum.spin's child lifecycle and sugar-pty's
 * PosixProcess handle shape (proc_open with /dev/null stdin — a child
 * that inherited our stdin would steal it from the interactive
 * runtime — non-blocking capture pipes, pid-reuse-guarded signalling,
 * and the bounded TERM→grace→KILL reap ladder).
 *
 * @see \SugarCraft\ScriptUi\Process\Process for the pinned contract.
 */
final class RealProcess implements Process
{
    /** Seconds a SIGTERM'd child is given to exit before {@see close()} escalates to SIGKILL. */
    public const TERM_GRACE_SECONDS = 2.0;

    /** Bytes requested per non-blocking read inside {@see drain()}. */
    public const DRAIN_CHUNK_BYTES = 65536;

    /** Safety valve: at most this many bytes leave ONE pipe per drain() call. */
    public const DRAIN_MAX_BYTES_PER_CALL = 4_194_304;

    /** @var resource|null live `proc_open()` handle (null once reaped) */
    private $process = null;

    /**
     * Captured pipes; null entries mean "not captured / inherited".
     *
     * @var array{0: ?resource, 1: ?resource}
     */
    private array $pipes = [null, null];

    private string $bufferedStdout = '';

    private string $bufferedStderr = '';

    private bool $closed = false;

    private bool $terminated = false;

    private ?int $cachedExit = null;

    private function __construct(
        private readonly int $pid,
        $process,
        $stdoutPipe = null,
        $stderrPipe = null,
    ) {
        $this->process = $process;
        $this->pipes[0] = \is_resource($stdoutPipe) ? $stdoutPipe : null;
        $this->pipes[1] = \is_resource($stderrPipe) ? $stderrPipe : null;
    }

    /**
     * Spawn a non-PTY child. `$captureStdout`/`$captureStderr` decide
     * whether stdout/stderr land in our capture pipes (drained by
     * {@see drain()}/{@see stdoutBytes()}) or are inherited from the
     * parent's STDOUT/STDERR.
     *
     * @param list<string> $command argv passed straight to proc_open (no shell)
     */
    public static function spawn(
        array $command,
        bool $captureStdout = false,
        bool $captureStderr = false,
    ): self {
        if ($command === []) {
            throw new \InvalidArgumentException('RealProcess::spawn requires a non-empty command');
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $captureStdout ? ['pipe', 'w'] : \STDOUT,
            2 => $captureStderr ? ['pipe', 'w'] : \STDERR,
        ];
        $pipes = [];

        $process = @\proc_open($command, $descriptors, $pipes, null, null, null);
        if (!\is_resource($process)) {
            throw new PtyException('child spawn failed: ' . \implode(' ', $command));
        }

        $status = \proc_get_status($process);
        $pid    = (int) ($status['pid'] ?? 0);
        if ($pid <= 0) {
            \proc_close($process);
            throw new PtyException('child spawn returned no pid: ' . \implode(' ', $command));
        }

        $stdoutPipe = $captureStdout && isset($pipes[1]) && \is_resource($pipes[1]) ? $pipes[1] : null;
        $stderrPipe = $captureStderr && isset($pipes[2]) && \is_resource($pipes[2]) ? $pipes[2] : null;

        // Non-blocking pipes are the load-bearing half of the 64KiB cure:
        // every reader on this handle drains, and a drain must never stall
        // on a pipe that has merely run dry between writes.
        if ($stdoutPipe !== null) {
            \stream_set_blocking($stdoutPipe, false);
        }
        if ($stderrPipe !== null) {
            \stream_set_blocking($stderrPipe, false);
        }

        return new self($pid, $process, $stdoutPipe, $stderrPipe);
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Bounded non-blocking pump of both capture pipes. This is the
     * run-loop drain vocabulary: call it per tick (or per read-stream
     * event) and a child writing past the ~64KiB OS pipe buffer never
     * blocks waiting for a reader that only shows up after the loop ends.
     *
     * Reads are incremental (chunk appends only), so pumping repeatedly
     * is O(new bytes) — no full-buffer copy per tick.
     */
    public function drain(): void
    {
        foreach ([0, 1] as $slot) {
            $pipe = $this->pipes[$slot];
            if (!\is_resource($pipe)) {
                continue;
            }
            $budget = self::DRAIN_MAX_BYTES_PER_CALL;
            while ($budget > 0) {
                $chunk = @\fread($pipe, \min(self::DRAIN_CHUNK_BYTES, $budget));
                if ($chunk === false || $chunk === '') {
                    break; // dry (non-blocking) or EOF — nothing more right now
                }
                $budget -= \strlen($chunk);
                if ($slot === 0) {
                    $this->bufferedStdout .= $chunk;
                } else {
                    $this->bufferedStderr .= $chunk;
                }
            }
        }
    }

    /**
     * Non-blocking exit probe: reads live status while the handle is
     * open, caches the verdict once the child is gone. Never drains or
     * reaps — that is {@see drain()}/{@see wait()}/{@see close()}'s job.
     */
    public function exited(): bool
    {
        if ($this->cachedExit !== null || !\is_resource($this->process)) {
            return true;
        }
        $status = \proc_get_status($this->process);
        if ($status['running'] === false) {
            $this->cachedExit = (int) $status['exitcode'];
            return true;
        }
        return false;
    }

    public function exitCode(): ?int
    {
        if ($this->cachedExit !== null) {
            return $this->cachedExit;
        }
        return $this->exited() ? ($this->cachedExit ?? -1) : null;
    }

    /**
     * Signal the child. Libc::kill() (sugar-pty's FFI route), not bare
     * posix_kill(): ext-posix is optional on this fleet. The exited()
     * guard before AND after the failed call is the pid-reuse guard —
     * signalling a reaped (possibly recycled) PID risks an unrelated
     * process, so a post-exit kill is silently dropped instead.
     */
    public function kill(int $signal): void
    {
        if ($this->exited()) {
            return;
        }
        if (!Libc::kill($this->pid, $signal) && !$this->exited()) {
            throw new PtyException("kill(signal {$signal}) failed for pid {$this->pid}");
        }
    }

    /**
     * Signal the child with SIGTERM. Lifecycle split: terminate() only
     * *signals*; close() is the sole *reaping* path and it bounds the
     * wait (grace + SIGKILL escalation), so close-after-terminate neither
     * double-kills nor hangs. Idempotence guards: skip when the handle
     * was already reaped ($closed), when a SIGTERM was already sent
     * ($terminated), or when the child already exited on its own.
     */
    public function terminate(): void
    {
        if ($this->closed || $this->terminated || $this->exitCode() !== null) {
            return;
        }
        $this->terminated = true;
        $this->kill(\defined('SIGTERM') ? \SIGTERM : 15);
    }

    /**
     * Reap the child, bounded. If terminate() asked the child to stop and
     * it is still running, give it {@see TERM_GRACE_SECONDS} to honour
     * SIGTERM, then escalate to SIGKILL — the fleet-standard TERM→grace→
     * KILL ladder. The grace poll drains the capture pipes as it goes: a
     * dying child that is still writing must not wedge the window.
     */
    public function close(): int
    {
        if ($this->closed) {
            return $this->cachedExit ?? 0;
        }
        if ($this->terminated && !$this->exited()) {
            $deadline = \microtime(true) + self::TERM_GRACE_SECONDS;
            while (!$this->exited() && \microtime(true) < $deadline) {
                $this->drain();
                \usleep(10_000);
            }
            if (!$this->exited()) {
                $this->kill(\defined('SIGKILL') ? \SIGKILL : 9);
            }
        }
        $code               = $this->wait();
        $this->closed       = true;
        $this->cachedExit   = $code;
        return $code;
    }

    /**
     * Block until the child exits, pumping the capture pipes every poll
     * iteration, then settle the handles: final drain, close pipes,
     * proc_close. A cached or already-reaped verdict still settles
     * (bytes produced before the reap stay readable); a handle the
     * closer never saw returns the cached code or -1 — NEVER a fabricated
     * success.
     */
    public function wait(): int
    {
        if ($this->cachedExit !== null) {
            $this->drain();
            $this->closeCapturePipes();
            if (\is_resource($this->process)) {
                \proc_close($this->process);
                $this->process = null;
            }
            return $this->cachedExit;
        }
        if (!\is_resource($this->process)) {
            return $this->cachedExit ?? -1;
        }

        while (true) {
            $this->drain();
            $status = \proc_get_status($this->process);
            if ($status['running'] === false) {
                $this->cachedExit = (int) $status['exitcode'];
                break;
            }
            \usleep(10_000);
        }

        $this->drain();
        $this->closeCapturePipes();
        \proc_close($this->process);
        $this->process = null;
        return $this->cachedExit;
    }

    /** Captured stdout bytes (drains first). */
    public function stdout(): string
    {
        return $this->stdoutBytes();
    }

    /** Captured stderr bytes (drains first). */
    public function stderr(): string
    {
        return $this->stderrBytes();
    }

    public function stdoutBytes(): string
    {
        $this->drain();
        return $this->bufferedStdout;
    }

    public function stderrBytes(): string
    {
        $this->drain();
        return $this->bufferedStderr;
    }

    private function closeCapturePipes(): void
    {
        foreach ([0, 1] as $slot) {
            if (\is_resource($this->pipes[$slot])) {
                @\fclose($this->pipes[$slot]);
            }
            $this->pipes[$slot] = null;
        }
    }
}
