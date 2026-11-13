<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

use React\EventLoop\LoopInterface;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;
use SugarCraft\ScriptUi\Lang;

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
 * and the bounded TERM→grace→KILL reap ladder). Native Windows is
 * refused outright at spawn (scriptui-08): this ladder has no Windows
 * backend, so callers get a localized clean refusal instead of the old
 * half-spawn-and-throw behaviour.
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

    /**
     * setsid-helper arm for hosts without a usable setsid(1) (stock macOS
     * ships none): the helper session-leads itself, then REPLACES its image
     * with the real command, so the tracked pid stays the group leader and
     * `-w`-style status forwarding is free (mirrors candy-code's proven
     * ProcessContainment design, kept as a local copy per scriptui-01's
     * one-copy rule).
     */
    public const SETSID_STUB = 'if (posix_setsid() < 0) { exit(127); } pcntl_exec("/usr/bin/env", array_slice($argv, 1)); exit(127);';

    /** @var ?array<int,string> memoised detach-wrapper prefix ([] = plain spawn) */
    private static ?array $detachPrefix = null;

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

    /** Loop the capture pipes are currently pumped on (null = pure polling) */
    private ?LoopInterface $pumpLoop = null;

    /** @var array{0: bool, 1: bool} per-slot "read stream registered on pumpLoop" flags */
    private array $pumped = [false, false];

    /** True when the tracked pid LEADS the child's process group (detach wrapper armed). */
    private bool $groupManaged = false;

    private function __construct(
        private readonly int $pid,
        $process,
        $stdoutPipe = null,
        $stderrPipe = null,
        bool $groupManaged = false,
    ) {
        $this->process = $process;
        $this->pipes[0] = \is_resource($stdoutPipe) ? $stdoutPipe : null;
        $this->pipes[1] = \is_resource($stderrPipe) ? $stderrPipe : null;
        $this->groupManaged = $groupManaged;
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

        if (\DIRECTORY_SEPARATOR === '\\') {
            // scriptui-08 (candy-scriptui-021): the whole lifecycle this
            // class is built on — /dev/null stdio, the Libc::kill FFI
            // signalling rung, the detach/group-kill wrapper — has no
            // native Windows backend (sugar-pty's Libc::kill throws there
            // unconditionally; its Windows/ConPTY routing is a separate
            // rider). Before this guard `spin` merely LOOKED green on the
            // Windows CI matrix: RealProcessTest skips there and
            // SpinCommandTest injects FakeProcess, so the half-spawned
            // child and the always-throwing terminate were never exercised.
            // Fail fast instead, with a localized sentence the command
            // surfaces as a clean refusal (rc FAILURE), never an exception.
            throw new PtyException(Lang::t('spinner.windows_unsupported'));
        }

        // scriptui-04 (candy-scriptui-006): plain proc_open leaves the
        // child in OUR process group, so a timeout/Esc cancel could only
        // ever signal the direct child — grandchildren (`sh -c 'sleep 27 &
        // sleep 27'`) survived the reap as orphans. When the host offers a
        // detach wrapper we give the child its own group by construction;
        // kill() then reaches -pid, the whole tree.
        $prefix     = self::detachSpawnPrefix();
        $command    = $prefix === [] ? $command : [...$prefix, ...\array_values($command)];

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

        return new self($pid, $process, $stdoutPipe, $stderrPipe, $prefix !== []);
    }

    /**
     * Whether the tracked pid leads the child's process group (the detach
     * wrapper was armed). True ⇒ signals go to the whole tree; false ⇒
     * direct-child only (the pre-scriptui-04 shape, still correct when no
     * wrapper exists — signalling our own group would be self-harm).
     */
    public function groupManaged(): bool
    {
        return $this->groupManaged;
    }

    /**
     * The proven detach wrapper for this host, memoised: util-linux
     * `setsid -w --` first (the cheap native arm — `-w` waits and forwards
     * the command's exit status, so proc_get_status still reports THE
     * command), else the PHP setsid+exec helper arm when ext-posix and
     * ext-pcntl are present, else [] (plain spawn, direct-child signalling).
     * Located by a PATH stat walk — never by executing the candidate.
     *
     * @return array<int,string>
     */
    private static function detachSpawnPrefix(): array
    {
        if (self::$detachPrefix !== null) {
            return self::$detachPrefix;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows arms the refusal path (scriptui-08), not a group wrapper.
            return self::$detachPrefix = [];
        }

        $setsid = self::locateOnPath('setsid');
        if ($setsid !== '') {
            return self::$detachPrefix = [$setsid, '-w', '--'];
        }

        if (\PHP_BINARY !== '' && \function_exists('posix_setsid') && \function_exists('pcntl_exec')) {
            return self::$detachPrefix = [\PHP_BINARY, '-r', self::SETSID_STUB, '--'];
        }

        return self::$detachPrefix = [];
    }

    /** PATH-walk an executable, returning '' when absent (no spawn, no shell). */
    private static function locateOnPath(string $binary): string
    {
        $path = (string) (\getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin');
        foreach (\explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = \rtrim($dir, '/') . '/' . $binary;
            if (\is_executable($candidate) && !\is_dir($candidate)) {
                return $candidate;
            }
        }
        return '';
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
                    // Dry (non-blocking) — or the writer is gone. A pipe at
                    // EOF must leave the pump: an always-readable dead fd
                    // would spin the event loop at 100% CPU.
                    if ($chunk === false || \feof($pipe)) {
                        $this->retireSlot($slot);
                    }
                    break;
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
     * Register both open capture pipes on the event loop (scriptui-02, the
     * 64KiB cure). The run loop used to poll only the exit verdict, so a
     * child writing past the ~64KiB OS pipe buffer blocked forever while
     * the spinner painted: nobody drained until after the loop ended.
     * With the pump attached, every loop wakeup that sees readable pipe
     * bytes runs {@see drain()} — the child never stalls, the parents
     * poll keeps ticking, and EOF retires the watcher.
     *
     * Idempotent per loop; attaching on a different loop moves the pump.
     */
    public function attachPump(LoopInterface $loop): void
    {
        if ($this->pumpLoop !== null && $this->pumpLoop !== $loop) {
            $this->detachPump();
        }
        $this->pumpLoop = $loop;

        foreach ([0, 1] as $slot) {
            $pipe = $this->pipes[$slot];
            if (!\is_resource($pipe) || $this->pumped[$slot]) {
                continue;
            }
            $loop->addReadStream($pipe, function (): void {
                $this->drain();
            });
            $this->pumped[$slot] = true;
        }
    }

    /**
     * Unregister the read-stream pump (no-op when not attached). Called by
     * the command right after the run loop returns and by every wait()
     * settle path, so capture fds never outlive the loop watching them.
     */
    public function detachPump(): void
    {
        if ($this->pumpLoop === null) {
            return;
        }
        foreach ([0, 1] as $slot) {
            if ($this->pumped[$slot] && \is_resource($this->pipes[$slot])) {
                $this->pumpLoop->removeReadStream($this->pipes[$slot]);
            }
            $this->pumped[$slot] = false;
        }
        $this->pumpLoop = null;
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
     * Signal the child — its whole process GROUP when the detach wrapper
     * armed the tree (scriptui-04), the bare pid otherwise. Libc::kill()
     * (sugar-pty's FFI route), not bare posix_kill(): ext-posix is
     * optional on this fleet. The exited() guard before AND after the
     * failed call is the pid-reuse guard — signalling a reaped (possibly
     * recycled) PID risks an unrelated process, so a post-exit kill is
     * silently dropped instead. While the group leader (our tracked pid)
     * lives, the group exists, so a running handle never ESRCHs.
     */
    public function kill(int $signal): void
    {
        if ($this->exited()) {
            return;
        }

        // (1) the group, best-effort: the kernel-exact rung once the
        //     wrapper has exec'd; a plain miss just means the exec window
        //     hasn't opened yet (pre-establishment ESRCH).
        // (2) every currently observable descendant, by pid: independent
        //     of group establishment, so the launch window between fork
        //     and setsid(2) can never shield the tree.
        if ($this->groupManaged) {
            Libc::kill(-$this->pid, $signal);
            foreach ($this->descendantPids() as $kid) {
                Libc::kill($kid, $signal);
            }
        }

        $ok = Libc::kill($this->pid, $signal);
        if (!$ok && !$this->exited()) {
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

        // Reap-completeness sweep (scriptui-04): the tree can fork new
        // members while it dies; keep SIGKILLing observable descendants
        // until the walk comes back empty (bounded), so close() returning
        // means NOTHING of the child's tree survives — the promise the
        // timeout/Esc cancels make to the terminal.
        if ($this->groupManaged) {
            $sweepDeadline = \microtime(true) + self::TERM_GRACE_SECONDS;
            $sigkill       = \defined('SIGKILL') ? \SIGKILL : 9;
            while (($kids = $this->descendantPids()) !== [] && \microtime(true) < $sweepDeadline) {
                foreach ($kids as $kid) {
                    Libc::kill($kid, $sigkill);
                }
                \usleep(10_000);
            }
        }

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
            $this->detachPump();
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

        $this->detachPump();
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

    /**
     * Zero-IO view of the buffered stdout — for pump-proof instrumentation
     * that must observe bytes WITHOUT draining (a peek that drains would
     * hide a broken pump behind its own polling).
     */
    public function bufferedPeek(): string
    {
        return $this->bufferedStdout;
    }

    public function stderrBytes(): string
    {
        $this->drain();
        return $this->bufferedStderr;
    }

    /**
     * The process tree rooted at the tracked pid (direct+transient
     * children, breadth-first), EXCLUDING the tracked pid itself and this
     * runner. Linux reads /proc/<pid>/task/<pid>/children per level;
     * elsewhere one `ps -A -o ppid=,pid=` helper per level does the job.
     * A process that raced away between levels simply contributes no
     * children — the walk must never signal pids it did not verify.
     *
     * @return list<int>
     */
    public function descendantPids(): array
    {
        if (DIRECTORY_SEPARATOR === '\\' || $this->pid <= 0) {
            return [];
        }

        $tree       = [];
        $frontier   = [$this->pid];
        $seen       = [$this->pid => true];
        $myPid      = \getmypid();
        $rounds     = 0;
        while ($frontier !== [] && $rounds++ < 16) {
            $next = [];
            foreach ($frontier as $parent) {
                foreach ($this->directChildren((int) $parent) as $kid) {
                    if ($kid <= 0 || $kid === $myPid || isset($seen[$kid])) {
                        continue;
                    }
                    $seen[$kid]  = true;
                    $tree[]      = $kid;
                    $next[]      = $kid;
                }
            }
            $frontier = $next;
        }

        return $tree;
    }

    /** @return list<int> */
    private function directChildren(int $parent): array
    {
        if (\is_dir('/proc')) {
            $raw = @\file_get_contents("/proc/{$parent}/task/{$parent}/children");
            if ($raw !== false) {
                $ids = \preg_split('/\s+/', \trim($raw));
                return \is_array($ids) && $ids !== [''] ? \array_map('intval', $ids) : [];
            }
            // /proc without the children file (pre-3.5 kernels): ppid walk.
            $out = [];
            foreach ((array) \glob('/proc/[0-9]*/stat') as $stat) {
                $raw = @\file_get_contents($stat);
                if ($raw === false) {
                    continue;
                }
                $close = \strrpos($raw, ')');
                if ($close === false) {
                    continue;
                }
                $open  = \strpos($raw, '(');
                $rest  = \preg_split('/\s+/', \trim(\substr($raw, $close + 1)));
                if ($open === false || !\is_array($rest) || \count($rest) < 2) {
                    continue;
                }
                if ((int) $rest[1] === $parent) {
                    $out[] = (int) \substr($raw, 0, $open);
                }
            }
            return $out;
        }

        // ps-walk fallback (macOS): one bounded helper per level, reaped inline.
        $out = @\popen('ps -A -o ppid=,pid= 2>/dev/null', 'r');
        if (!\is_resource($out)) {
            return [];
        }
        $kids = [];
        while (($line = \fgets($out)) !== false) {
            $parts = \preg_split('/\s+/', \trim($line));
            if (\is_array($parts) && \count($parts) === 2 && (int) $parts[0] === $parent) {
                $kids[] = (int) $parts[1];
            }
        }
        \pclose($out);
        return $kids;
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

    /**
     * Take one exhausted slot out of service: unregister its pump watcher
     * first (never leave a closed fd on the loop), then close the read
     * end. Only ever called from drain() once feof() proves the writer
     * is gone, so no child bytes are lost.
     */
    private function retireSlot(int $slot): void
    {
        $pipe = $this->pipes[$slot];
        if ($this->pumpLoop !== null && $this->pumped[$slot] && \is_resource($pipe)) {
            $this->pumpLoop->removeReadStream($pipe);
        }
        $this->pumped[$slot] = false;
        if (\is_resource($pipe)) {
            @\fclose($pipe);
        }
        $this->pipes[$slot] = null;
    }
}
