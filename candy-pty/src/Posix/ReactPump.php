<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PumpOptions;

/**
 * ReactPHP-compatible byte pump — the event-loop counterpart to
 * {@see PosixPump}. Registers the PTY master (and optional host stdin)
 * with the React event loop via `Loop::addReadStream()` instead of a
 * blocking `stream_select` loop, so a ReactPHP application can multiplex
 * PTY I/O with timers, sockets, and other loop work.
 *
 * Semantics mirror {@see PosixPump::run()} exactly: the returned promise
 * resolves with the child's exit code when it has exited (tail bytes are
 * drained from the master within `PumpOptions::$flushDeadlineSec`);
 * with `0` when there is no child to monitor and the master EOFs; and
 * with `-1` when a child was supplied but is still running at teardown
 * (stdin EOF grace elapsed, stdout EPIPE, or {@see stop()}) — the caller
 * must kill + wait(), same as the sync pump.
 *
 * Child-exit detection is a periodic-timer poll of the non-blocking
 * {@see Child::exited()} probe (at `PumpOptions::$selectTimeoutUs`
 * cadence, matching the sync pump's idle tick). A SIGCHLD handler is
 * deliberately NOT used: POSIX signal dispositions are process-global,
 * so installing one would conflict with user code / candy-pty's own
 * SignalForwarder, and ext-pcntl is not guaranteed to be loaded.
 *
 * Naming follows the house `React*` prefix for event-loop adapters
 * (candy-input `ReactInputDriver`, candy-query `ReactMysqlConnection`).
 *
 * Implements plan item 8.1 (findings/plan_candy-pty.md).
 *
 * @see PosixPump   the blocking select-loop pump (unchanged)
 * @see portable-pty.Pump
 */
final class ReactPump
{
    /**
     * Resolved lazily in {@see start()} — constructing a pump must not
     * instantiate the global loop (Loop::get() memoises the backend,
     * and ExtUvLoop's internal clock only advances while running, so an
     * eagerly-created-but-idle global loop makes later relative timers
     * fire instantly).
     */
    private ?LoopInterface $loop;

    private bool $running = false;

    private ?Deferred $deferred = null;

    private ?MasterPty $master = null;

    private ?Child $child = null;

    private ?PumpOptions $opts = null;

    /** @var resource|null */
    private $masterStream;

    /** @var resource|null */
    private $stdinStream;

    /** @var resource|null */
    private $stdoutStream;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onData = null;

    private bool $masterWriteAttached = false;

    private ?TimerInterface $pollTimer = null;

    /** stdin-EOF grace timer or post-child-exit flush timer. */
    private ?TimerInterface $deadlineTimer = null;

    /** Buffered stdin bytes not yet written to master (partial-write remainder). */
    private string $pendingStdin = '';

    /** Set once the child was observed as exited; drains tail bytes until finish. */
    private bool $flushing = false;

    /** Whether any master/stdin I/O happened since the last poll tick (idle detection). */
    private bool $sawIo = false;

    private int $lastKnownCols = 0;

    private int $lastKnownRows = 0;

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop;
    }

    /**
     * Start pumping. Parameter order mirrors {@see PosixPump::run()};
     * `$stdinStream` and `$stdoutStream` are optional here so callers
     * can consume master output purely through `$onData` (e.g. feeding
     * a candy-vt Terminal) without wiring host stdio.
     *
     * The pump is single-flight: calling start() while a previous run
     * is still active throws.
     *
     * @param resource|null                 $stdinStream  host input forwarded to the master (null = output-only)
     * @param resource|null                 $stdoutStream sink for master output (null = callback-only)
     * @param (\Closure(string): void)|null $onData       fired with every chunk read from the master
     * @return PromiseInterface<int> resolves with the sync pump's exit-code
     *                               contract (see class doc); cancelling the
     *                               promise behaves like {@see stop()}.
     */
    public function start(
        MasterPty $master,
        $stdinStream = null,
        $stdoutStream = null,
        ?Child $child = null,
        ?PumpOptions $opts = null,
        ?\Closure $onData = null,
    ): PromiseInterface {
        if ($this->running) {
            throw new \RuntimeException('ReactPump::start() called while a pump is already running; call stop() first.');
        }
        if ($stdinStream !== null && !\is_resource($stdinStream)) {
            throw new \InvalidArgumentException('ReactPump::start() requires a resource (or null) $stdinStream');
        }
        if ($stdoutStream !== null && !\is_resource($stdoutStream)) {
            throw new \InvalidArgumentException('ReactPump::start() requires a resource (or null) $stdoutStream');
        }

        $opts ??= new PumpOptions();
        $this->loop ??= Loop::get();

        $this->running = true;
        $this->master = $master;
        $this->child = $child;
        $this->opts = $opts;
        $this->masterStream = $master->stream();
        $this->stdinStream = $stdinStream;
        $this->stdoutStream = $stdoutStream;
        $this->onData = $onData;
        $this->pendingStdin = '';
        $this->flushing = false;
        $this->sawIo = false;
        $this->masterWriteAttached = false;
        $this->deadlineTimer = null;
        $this->deferred = new Deferred(function (): void {
            // Promise cancellation == detach: same contract as stop().
            $this->stop();
        });

        \stream_set_blocking($this->masterStream, false);
        $this->loop->addReadStream($this->masterStream, fn () => $this->onMasterReadable());

        if ($this->stdinStream !== null) {
            \stream_set_blocking($this->stdinStream, false);
            $this->loop->addReadStream($this->stdinStream, fn () => $this->onStdinReadable());
        }

        // Track last known PTY size so the poll tick can detect resize
        // and fire onSigwinch — same mechanism as the sync pump's idle path.
        $this->lastKnownCols = 0;
        $this->lastKnownRows = 0;
        if ($opts->onSigwinch !== null) {
            try {
                $size = $master->size();
                $this->lastKnownCols = $size['cols'];
                $this->lastKnownRows = $size['rows'];
            } catch (\Throwable) {
                // size() can fail if the FD is not a TTY; guard silently.
            }
        }

        // Idle/housekeeping tick at the sync pump's select-timeout cadence:
        // child-exit poll, onIdle/keepalive, resize detection.
        $this->pollTimer = $this->loop->addPeriodicTimer(
            \max(0.001, $opts->selectTimeoutUs / 1_000_000),
            fn () => $this->onPollTick(),
        );

        return $this->deferred->promise();
    }

    /**
     * Detach from the event loop: removes every read/write-stream
     * registration and cancels all timers, then resolves the pending
     * promise with the sync pump's exit-code contract. Idempotent —
     * safe to call from `finally` blocks after the pump already
     * finished on its own.
     */
    public function stop(): void
    {
        $this->finish();
    }

    /** Whether a started pump has not yet finished/stopped. */
    public function isRunning(): bool
    {
        return $this->running;
    }

    private function onMasterReadable(): void
    {
        if (!$this->running) {
            return;
        }
        $bytes = $this->master->read($this->opts->chunkBytes);
        if ($bytes === null) {
            // Transient (would-block / EIO while flushing). During the
            // post-child-exit flush the sync pump breaks on the first
            // empty read once the child is gone — mirror that.
            if ($this->flushing) {
                $this->finish();
            }
            return;
        }
        if ($bytes === '') {
            // Genuine EOF — nothing more will ever arrive.
            $this->finish();
            return;
        }
        $this->sawIo = true;
        if ($this->opts->recorder !== null) {
            $this->opts->recorder->recordOutput($bytes);
        }
        if ($this->stdoutStream !== null) {
            $written = @\fwrite($this->stdoutStream, $bytes);
            if ($written === false) {
                // EPIPE — peer closed the read end; sync pump exits here too.
                $this->finish();
                return;
            }
        }
        if ($this->onData !== null) {
            ($this->onData)($bytes);
        }
    }

    private function onStdinReadable(): void
    {
        if (!$this->running) {
            return;
        }
        $bytes = @\fread($this->stdinStream, $this->opts->chunkBytes);
        if ($bytes === false || $bytes === '') {
            if (\feof($this->stdinStream)) {
                $this->onStdinEof();
            }
            return;
        }
        $this->sawIo = true;
        $this->writeToMaster($bytes);
    }

    /**
     * Stdin EOF: stop watching stdin, send VEOF so a cooked-mode child
     * sees end-of-input, and give it {@see PumpOptions::$stdinEofGraceSec}
     * to exit on its own before the pump tears down (resolving -1 so the
     * caller can enforce its kill policy — identical to the sync pump).
     */
    private function onStdinEof(): void
    {
        $this->loop->removeReadStream($this->stdinStream);
        $this->stdinStream = null;
        @\fwrite($this->masterStream, $this->opts->veof);
        if ($this->deadlineTimer === null) {
            $this->deadlineTimer = $this->loop->addTimer(
                $this->opts->stdinEofGraceSec,
                fn () => $this->finish(),
            );
        }
    }

    /**
     * Short-write-safe master write: buffers the remainder and drains it
     * via `addWriteStream` back-pressure instead of spinning.
     */
    private function writeToMaster(string $bytes): void
    {
        $toWrite = $this->pendingStdin . $bytes;
        $this->pendingStdin = '';

        $written = 0;
        $len = \strlen($toWrite);
        while ($written < $len) {
            $n = $this->master->write(\substr($toWrite, $written));
            if ($n <= 0) {
                $this->pendingStdin = \substr($toWrite, $written);
                if (!$this->masterWriteAttached) {
                    $this->masterWriteAttached = true;
                    $this->loop->addWriteStream($this->masterStream, fn () => $this->onMasterWritable());
                }
                break;
            }
            $written += $n;
        }

        // Recorder tap AFTER the write, same rationale as PosixPump: the
        // cassette must only carry bytes the child actually saw.
        if ($this->opts->recorder !== null && $written > 0) {
            $this->opts->recorder->recordInputBytes(\substr($toWrite, 0, $written));
        }
    }

    private function onMasterWritable(): void
    {
        if (!$this->running || $this->pendingStdin === '') {
            $this->detachMasterWrite();
            return;
        }
        $pending = $this->pendingStdin;
        $this->pendingStdin = '';
        $this->writeToMaster($pending);
        if ($this->pendingStdin === '') {
            $this->detachMasterWrite();
        }
    }

    private function detachMasterWrite(): void
    {
        if ($this->masterWriteAttached) {
            $this->loop->removeWriteStream($this->masterStream);
            $this->masterWriteAttached = false;
        }
    }

    /**
     * Periodic housekeeping tick — the async analogue of the sync pump's
     * `stream_select` timeout branch: child-exit poll first, then
     * onIdle / keepalive / resize detection when no I/O happened since
     * the previous tick.
     */
    private function onPollTick(): void
    {
        if (!$this->running) {
            return;
        }

        if ($this->child !== null && !$this->flushing && $this->child->exited()) {
            $this->flushing = true;
            // Child is gone: stop forwarding stdin, cancel any pending
            // stdin-EOF grace, and bound the tail drain by the flush
            // deadline (mirrors PosixPump::flushMaster()).
            if ($this->stdinStream !== null) {
                $this->loop->removeReadStream($this->stdinStream);
                $this->stdinStream = null;
            }
            if ($this->deadlineTimer !== null) {
                $this->loop->cancelTimer($this->deadlineTimer);
            }
            $this->deadlineTimer = $this->loop->addTimer(
                $this->opts->flushDeadlineSec,
                fn () => $this->finish(),
            );
            if ($this->opts->onChildExit !== null) {
                ($this->opts->onChildExit)($this->child->exitCode() ?? 0);
            }
            return;
        }

        if ($this->sawIo) {
            $this->sawIo = false;
            return;
        }

        if ($this->opts->onIdle !== null) {
            ($this->opts->onIdle)();
        }
        if ($this->opts->keepalive !== null) {
            ($this->opts->keepalive)();
        }
        if ($this->opts->onSigwinch !== null && $this->lastKnownCols !== 0) {
            try {
                $current = $this->master->size();
                if ($current['cols'] !== $this->lastKnownCols || $current['rows'] !== $this->lastKnownRows) {
                    ($this->opts->onSigwinch)((int) $current['cols'], (int) $current['rows']);
                    $this->lastKnownCols = $current['cols'];
                    $this->lastKnownRows = $current['rows'];
                }
            } catch (\Throwable) {
                // size() can fail; guard silently.
            }
        }
    }

    /**
     * Tear down every loop registration and resolve the promise. All
     * exit paths funnel here so a stopped/finished pump can never leak
     * a read stream, write stream, or timer into the loop.
     */
    private function finish(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;

        $this->loop->removeReadStream($this->masterStream);
        if ($this->stdinStream !== null) {
            $this->loop->removeReadStream($this->stdinStream);
            $this->stdinStream = null;
        }
        $this->detachMasterWrite();
        if ($this->pollTimer !== null) {
            $this->loop->cancelTimer($this->pollTimer);
            $this->pollTimer = null;
        }
        if ($this->deadlineTimer !== null) {
            $this->loop->cancelTimer($this->deadlineTimer);
            $this->deadlineTimer = null;
        }

        if ($this->child === null) {
            $result = 0;
        } elseif ($this->child->exited()) {
            $result = $this->child->exitCode() ?? 0;
        } else {
            $result = -1;
        }

        $deferred = $this->deferred;
        $this->deferred = null;
        $deferred?->resolve($result);
    }
}
