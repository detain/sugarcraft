<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Lang;

/**
 * Package-private trait shared by {@see \SugarCraft\Pty\Child} and any other
 * sugar-pty handle that owns a `proc_open()` resource and needs to expose the
 * canonical waitpid-style lifecycle (`exited()`, `wait()`, `exitCode()`,
 * `pid()`).
 *
 * Mirrors charmbracelet/x/xpty.Cmd's waitpid semantics — `running=false`
 * triggers `exitcode` capture before `proc_close()`. PHP's `proc_close()`
 * returns the wait-status; `-1` means the child was already reaped, which is
 * harmless and intentionally ignored here.
 *
 * Intended only for sugar-pty internal use; PHP has no real access control on
 * traits, so this is a doc-comment convention.
 *
 * @internal
 *
 * @see \SugarCraft\Pty\Child
 * @see \SugarCraft\Pty\Posix\PosixChild
 */
trait ChildPollTrait
{
    use \SugarCraft\Pty\Concerns\LibcAccess;

    /** @var resource|null */
    private $process;

    private ?int $exitCode = null;

    private const WNOHANG = 1;

    /**
     * Probe cadence of {@see onExit()}'s fallback poll, used only where
     * SIGCHLD cannot be delivered: close to the sync {@see wait()} loop's
     * 10 ms sleep, at effectively zero CPU.
     */
    private const EXIT_FALLBACK_POLL_SECONDS = 0.015;

    /**
     * Try to reap an exited child via waitpid() FFI (non-blocking).
     *
     * Returns the exit code if the child has exited, null if still
     * running or FFI is unavailable (falls back to proc_get_status).
     *
     * WNOHANG is used so this never blocks — returns null immediately
     * if the child has not yet exited. Exit code: normally-exited =>
     * (status >> 8) & 0xFF; signal-terminated => 128 + (status & 0x7F).
     *
     * @internal
     *
     * @param int $pid the child process ID to wait on
     * @return int|null the exit code if the child has exited, null if still
     *                  running or FFI is unavailable
     */
    private function tryWaitpid(int $pid): ?int
    {
        static $libc = null;
        if ($libc === null) {
            try {
                $libc = self::libc();
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

        // $statusArray[0] is the int that waitpid wrote to. The raw
        // encoding is NOT the plain exit code: a normal exit rides the
        // HIGH byte (status == exit_code << 8), and a signal death puts
        // the signal number in the LOW byte (status & 0x7F) with the
        // high byte zero.
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
     *
     * Sharp edge: a child reaped by a prior `waitpid` call may report
     * `exited() === true` while `exitCode() === null` (proc_get_status
     * sets exitcode to -1 for reaped children). Callers that need a
     * guaranteed numeric exit code should prefer `wait()`.
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
     *
     * CONTRACT (E717): waitpid semantics — the poll loop below carries
     * no internal deadline; callers MUST bound. It exits only when the
     * child is reaped, so a child that never exits pins the caller
     * forever, exactly like an unbounded pump loop. The bounded
     * composition is the non-blocking {@see exited()} probe plus a
     * caller-side kill; tests additionally sit behind the
     * `sugar-pty/tests/Support/HangWatchdog` process backstop.
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
            // Tight polling: 10 ms sleep between proc_get_status checks.
            // This is intentional — waitpid FFI may be unavailable (ext-ffi
            // disabled) or may return null on the first call before the
            // child has exited. The fallback poll loop needs a small sleep
            // to avoid spinning the CPU at 100% while still detecting exit
            // within ~10 ms. This matches the granularity shells use for
            // $SECONDS-level polling.
            \usleep(10_000);
        }

        \proc_close($this->process);
        $this->process = null;
        // Darwin SIGINT-via-PTY can reap the zombie before tryWaitpid runs;
        // proc_get_status then reports running=false with exitcode=-1 and
        // $this->exitCode stays null. Match PHP's convention: -1 means
        // "process is gone but the exit code could not be captured".
        return $this->exitCode ?? -1;
    }

    /**
     * Run $fn once, with the exit status, when this child exits.
     *
     * DRIVEN BY SIGCHLD where the runtime delivers it: the listener rides the
     * loop's own signal API ({@see LoopInterface::addSignal()}), so the loop
     * multiplexes it with every other SIGCHLD listener instead of this class
     * claiming the process-global disposition for itself. A delivered SIGCHLD
     * only queues a probe on the next loop tick; the probe asks this child
     * alone ({@see exited()}, a `waitpid(pid, WNOHANG)`), so a SIGCHLD from any
     * other child costs one probe and never reaps someone else's status.
     *
     * NO POLL ON THE SIGNAL PATH. Nothing periodic is armed: the loop sleeps
     * until a child exits. One probe runs on the next tick after the listener
     * is armed, because a SIGCHLD delivered before that was not heard.
     *
     * THE FALLBACK. A periodic {@see exited()} probe every $fallbackPollSec
     * takes over only where SIGCHLD cannot be delivered: no ext-pcntl (no
     * `SIGCHLD` constant), a loop whose `addSignal()` throws
     * \BadMethodCallException (StreamSelectLoop without pcntl), or a SIGCHLD
     * disposition the loop does not own. That last case is a handler installed
     * with `pcntl_signal()` directly, such as
     * {@see \SugarCraft\Pty\SignalForwarder::attachSigchld()}, or SIG_IGN:
     * arming the loop's listener would replace that handler, and the loop puts
     * SIG_DFL back, not the old handler, when its last listener goes.
     *
     * A handler installed with `pcntl_signal()` AFTER this call replaces the
     * loop's and silences the listener, and $fn then never runs; install such
     * handlers first, so this call sees them and falls back to the poll.
     *
     * EXACTLY ONCE. $fn never runs synchronously inside this call, even for a
     * child that has already exited: it runs on a loop tick or timer, with
     * {@see wait()} semantics (128 + signo for a signal death read through
     * the FFI probe; -1 when the code could not be captured). The listener or
     * timer is removed before $fn runs, so an exception $fn throws escapes
     * that tick of `$loop->run()` without a second call.
     *
     * The armed listener or timer keeps $loop running until $fn has run or the
     * returned closure is called, the same as a pending timer would.
     *
     * Mirrors charmbracelet/x/xpty.Cmd.Wait() (the Go side blocks a goroutine;
     * here the loop holds a callback).
     *
     * @param callable(int):void $fn
     * @param float              $fallbackPollSec probe cadence in seconds, used
     *                                            only where SIGCHLD cannot be
     *                                            delivered
     * @return \Closure():void detaches $fn if it has not run yet (removes the
     *                         listener or timer); a no-op once $fn has run
     * @throws \InvalidArgumentException if $fallbackPollSec is not a finite
     *                                   number of seconds > 0
     */
    public function onExit(
        LoopInterface $loop,
        callable $fn,
        float $fallbackPollSec = self::EXIT_FALLBACK_POLL_SECONDS,
    ): \Closure {
        if (!\is_finite($fallbackPollSec) || $fallbackPollSec <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('child.on_exit_poll_invalid', [
                'seconds' => $fallbackPollSec,
            ]));
        }

        $settled = false;
        $probeQueued = false;
        /** @var int|null $signal */
        $signal = null;
        /** @var \Closure|null $listener */
        $listener = null;
        /** @var TimerInterface|null $timer */
        $timer = null;

        $disarm = static function () use (&$signal, &$listener, &$timer, $loop): void {
            if ($listener !== null) {
                $loop->removeSignal($signal, $listener);
                $listener = null;
            }
            if ($timer !== null) {
                $loop->cancelTimer($timer);
                $timer = null;
            }
        };

        $probe = function () use (&$settled, &$probeQueued, $disarm, $fn): void {
            $probeQueued = false;
            if ($settled || !$this->exited()) {
                return;
            }
            $settled = true;
            $disarm();
            // exited() === true guarantees wait() returns without blocking:
            // the exit status is already captured (or the process handle is
            // gone), so this is reaping + cache-read only.
            $fn($this->wait());
        };

        // A burst of SIGCHLDs collapses into one queued probe. The listener
        // only queues: with pcntl async signals it runs between two opcodes of
        // whatever code was executing, so $fn must not run from it.
        $queueProbe = static function () use (&$probeQueued, $loop, $probe): void {
            if ($probeQueued) {
                return;
            }
            $probeQueued = true;
            $loop->futureTick($probe);
        };

        $detach = static function () use (&$settled, $disarm): void {
            $settled = true;
            $disarm();
        };

        if ($this->exited()) {
            $queueProbe();

            return $detach;
        }

        $sigchld = self::deliverableSigchld();
        if ($sigchld !== null) {
            $handler = static function () use ($queueProbe): void {
                $queueProbe();
            };
            try {
                $loop->addSignal($sigchld, $handler);
                $signal = $sigchld;
                $listener = $handler;
            } catch (\BadMethodCallException) {
                // The loop cannot deliver signals: fall through to the poll.
            }
        }

        if ($listener !== null) {
            $queueProbe();

            return $detach;
        }

        $timer = $loop->addPeriodicTimer($fallbackPollSec, $probe);

        return $detach;
    }

    /**
     * Async variant of {@see wait()}: returns a ReactPHP promise that
     * resolves with the child's exit code without ever blocking the
     * event loop.
     *
     * Built on {@see onExit()}: SIGCHLD wakes the loop where the runtime
     * delivers it, and nothing periodic is armed. $pollIntervalSec is the
     * cadence of onExit()'s fallback poll only, used where SIGCHLD cannot be
     * delivered (see onExit() for when that is).
     *
     * Multiple concurrent waitAsync() calls each get their own listener and
     * all resolve independently — the exit code is cached on first capture
     * so later resolutions are pure reads.
     *
     * Cancelling the returned promise detaches THAT call's listener (or
     * fallback timer) only — other pending waitAsync() promises keep
     * waiting — and rejects with a \RuntimeException. The child itself is
     * not signalled.
     *
     * @param LoopInterface|null $loop            defaults to the global Loop::get()
     * @param float              $pollIntervalSec fallback exit-probe cadence in seconds
     * @return PromiseInterface<int> resolves with the exit code ({@see wait()} semantics,
     *                               including -1 when the code could not be captured)
     */
    public function waitAsync(?LoopInterface $loop = null, float $pollIntervalSec = self::EXIT_FALLBACK_POLL_SECONDS): PromiseInterface
    {
        if ($pollIntervalSec <= 0.0) {
            throw new \InvalidArgumentException("pollIntervalSec must be > 0; got {$pollIntervalSec}");
        }

        if ($this->exited()) {
            return \React\Promise\resolve($this->wait());
        }

        // Resolve the loop only when a listener is actually needed — the
        // already-exited fast path must not instantiate the global loop.
        $loop ??= Loop::get();

        /** @var \Closure|null $detach */
        $detach = null;
        $deferred = new Deferred(static function (callable $resolve, callable $reject) use (&$detach): void {
            if ($detach !== null) {
                $detach();
                $detach = null;
            }
            $reject(new \RuntimeException('waitAsync() cancelled before the child exited'));
        });

        $detach = $this->onExit($loop, static function (int $code) use ($deferred): void {
            $deferred->resolve($code);
        }, $pollIntervalSec);

        return $deferred->promise();
    }

    /**
     * The SIGCHLD number when the loop may own its delivery, else null.
     *
     * Only an untouched disposition (SIG_DFL) or one a ReactPHP loop installed
     * (its signal multiplexer) is taken. Under ext-uv or ext-ev the loop
     * installs its handler below PHP, so pcntl still reads SIG_DFL and the
     * loop's own `addSignal()` decides.
     */
    private static function deliverableSigchld(): ?int
    {
        if (!\defined('SIGCHLD')) {
            return null;
        }
        $signo = (int) \constant('SIGCHLD');
        if (!\function_exists('pcntl_signal_get_handler')) {
            return $signo;
        }
        $handler = \pcntl_signal_get_handler($signo);
        if ($handler === \SIG_DFL) {
            return $signo;
        }
        // react/event-loop's StreamSelectLoop installs [SignalsHandler, 'call'].
        if (\is_array($handler) && ($handler[0] ?? null) instanceof \React\EventLoop\SignalsHandler) {
            return $signo;
        }

        return null;
    }

    /**
     * @see creack/pty.Cmd.ExitCode()
     *
     * Sharp edge: returns `null` when the child was reaped by a prior
     * `waitpid` call (proc_get_status exitcode is -1 for reaped
     * children). Use `wait()` for a guaranteed numeric return value.
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
            // Keep the code this status poll consumed: a handle that shares
            // this child (PosixChild::withKillOnDrop()) reads it after the
            // close below.
            $code = (int) ($status['exitcode'] ?? -1);
            if ($code >= 0 && $this->exitCode === null) {
                $this->exitCode = $code;
            }
            @\proc_close($this->process);
            $this->process = null;
        }
    }
}
