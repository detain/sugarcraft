<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * The loop-driven body of {@see ChildRun::launch()}: one child, its pipes, its
 * deadline and its reap, all as callbacks on the loop. Built and started only by
 * ChildRun; not a public entry point.
 *
 * Exit is observed by a short periodic check (proc_get_status), because the
 * child's exit is not a readable stream. Once the output is read to EOF and the
 * exit is seen, the result settles. At the deadline the child is signalled with
 * SIGTERM, then SIGKILL after the sugar-core grace budgets, and the result
 * settles as TIMED_OUT. Signals are integer literals, the sugar-core doctrine.
 */
final class ChildRunLaunch
{
    /** How often the exit is checked while the child runs. */
    private const EXIT_POLL_SECONDS = 0.02;

    /** @var array<int, resource> Open readable pipes, keyed by slot (1 stdout, 2 stderr) */
    private array $open;

    /** @var null|resource The stdin pipe while its payload is still going out */
    private $stdinPipe;

    private int $stdinOffset = 0;

    /** @var list<TimerInterface> */
    private array $timers = [];

    private ChildCapture $capture;

    private bool $exited = false;

    private ?int $exitCode = null;

    private float $exitedAt = 0.0;

    private bool $timedOut = false;

    private bool $settled = false;

    private readonly bool $windows;

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes  0 stdin, 1 stdout, 2 stderr
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly mixed $proc,
        array $pipes,
        private readonly string $stdinBytes,
        private readonly float $timeout,
        int $maxBytes,
        private readonly Deferred $deferred,
    ) {
        $this->open = [1 => $pipes[1], 2 => $pipes[2]];
        $this->stdinPipe = $pipes[0];
        $this->capture = new ChildCapture([1, 2], [], $maxBytes);
        $this->windows = WindowsPipes::runtimeNeedsIt();
    }

    public function start(): void
    {
        $this->timers[] = $this->loop->addTimer($this->timeout, fn () => $this->onDeadline());
        $this->timers[] = $this->loop->addPeriodicTimer(self::EXIT_POLL_SECONDS, fn () => $this->checkExit());

        if ($this->windows) {
            // No readable stream on the Windows runtime: poll each pipe's readable bytes.
            $this->timers[] = $this->loop->addPeriodicTimer(
                WindowsPipes::POLL_MICROSECONDS / 1_000_000,
                fn () => $this->pollWindows(),
            );
        } else {
            foreach ($this->open as $slot => $pipe) {
                stream_set_blocking($pipe, false);
                $this->loop->addReadStream($pipe, fn () => $this->onReadable($slot));
            }
        }

        if ($this->stdinPipe !== null && $this->stdinBytes === '') {
            fclose($this->stdinPipe);
            $this->stdinPipe = null;
        } elseif ($this->stdinPipe !== null) {
            stream_set_blocking($this->stdinPipe, false);
            $this->loop->addWriteStream($this->stdinPipe, fn () => $this->onWritable());
        }
    }

    private function onReadable(int $slot): void
    {
        if ($this->settled || !isset($this->open[$slot])) {
            return;
        }

        $pipe = $this->open[$slot];
        $chunk = fread($pipe, ChildPump::READ_BYTES);
        if ($chunk === false || $chunk === '') {
            // feof(), not the empty read: a non-blocking pipe returns '' while the child is quiet.
            if (feof($pipe)) {
                $this->closeSlot($slot);
                $this->checkDone();
            }

            return;
        }

        $this->capture->offer($slot, $chunk);
    }

    private function onWritable(): void
    {
        if ($this->stdinPipe === null) {
            return;
        }

        $written = @fwrite($this->stdinPipe, substr($this->stdinBytes, $this->stdinOffset, ChildPump::READ_BYTES));
        if ($written === false) {
            // Broken pipe: the child exited without reading its stdin. Stop feeding.
            $this->stdinOffset = strlen($this->stdinBytes);
        } else {
            $this->stdinOffset += $written;
        }

        if ($this->stdinOffset >= strlen($this->stdinBytes)) {
            $this->closeStdin();
        }
    }

    private function pollWindows(): void
    {
        if ($this->settled) {
            return;
        }

        $got = false;
        foreach ($this->open as $slot => $pipe) {
            $chunk = WindowsPipes::readAvailable($pipe, ChildPump::READ_BYTES);
            if ($chunk !== '') {
                $this->capture->offer($slot, $chunk);
                $got = true;
            }
        }

        // Windows cannot report a pipe's EOF, so the output is done once the
        // child has exited and a pass finds nothing (or the grace has run out).
        if ($this->exited && (!$got || Clock::monotonicSeconds() - $this->exitedAt >= WindowsPipes::EXIT_DRAIN_GRACE_SECONDS)) {
            $this->closeAllPipes();
        }

        $this->checkDone();
    }

    private function checkExit(): void
    {
        if ($this->settled) {
            return;
        }

        if (!$this->exited) {
            $status = proc_get_status($this->proc);
            if ($status['running'] === false) {
                $this->exited = true;
                $this->exitedAt = Clock::monotonicSeconds();
                $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
            }
        }

        $this->checkDone();
    }

    private function onDeadline(): void
    {
        if ($this->settled) {
            return;
        }

        $this->timedOut = true;
        $this->closeAllPipes();
        $this->closeStdin();

        if ($this->exited) {
            $this->finish(ChildResult::TIMED_OUT);

            return;
        }

        @proc_terminate($this->proc, 15);
        $this->timers[] = $this->loop->addTimer(
            BoundedShutdown::TERMINATE_GRACE_SECONDS,
            fn () => $this->escalate(),
        );
        $this->timers[] = $this->loop->addTimer(
            BoundedShutdown::TERMINATE_GRACE_SECONDS + BoundedShutdown::KILL_GRACE_SECONDS,
            fn () => $this->forceFinish(),
        );
    }

    private function escalate(): void
    {
        if (!$this->settled && !$this->exited) {
            @proc_terminate($this->proc, 9);
        }
    }

    /** The kill budget is spent: settle now. SIGKILL has landed or is one kernel step away, so the reap cannot wait long. */
    private function forceFinish(): void
    {
        if (!$this->settled) {
            $this->finish(ChildResult::TIMED_OUT);
        }
    }

    private function checkDone(): void
    {
        if ($this->settled || !$this->exited || $this->open !== []) {
            return;
        }

        $this->finish($this->timedOut ? ChildResult::TIMED_OUT : ChildResult::EXITED);
    }

    private function finish(string $status): void
    {
        $this->settled = true;
        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->timers = [];
        $this->closeAllPipes();
        $this->closeStdin();

        $reaped = proc_close($this->proc);
        $code = $this->exitCode ?? ($reaped >= 0 ? $reaped : null);

        $r = $this->capture->result(DrainResult::CLOSED);
        $this->deferred->resolve(new ChildResult(
            $r->buffer(1),
            $r->buffer(2),
            $code,
            $status,
            $r->droppedBytes(1) + $r->droppedBytes(2),
        ));
    }

    private function closeSlot(int $slot): void
    {
        $pipe = $this->open[$slot];
        $this->loop->removeReadStream($pipe);
        fclose($pipe);
        unset($this->open[$slot]);
    }

    private function closeAllPipes(): void
    {
        foreach (array_keys($this->open) as $slot) {
            $this->closeSlot($slot);
        }
    }

    private function closeStdin(): void
    {
        if ($this->stdinPipe === null) {
            return;
        }

        $this->loop->removeWriteStream($this->stdinPipe);
        fclose($this->stdinPipe);
        $this->stdinPipe = null;
    }
}
