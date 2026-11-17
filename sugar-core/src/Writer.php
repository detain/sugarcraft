<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * FIFO byte queue in front of one output stream (a terminal, a pipe, a
 * worker's stdin).
 *
 * Two modes, chosen by whether a loop is attached:
 *
 *   - **Detached** (no loop): {@see enqueue()} writes synchronously, looping
 *     over short writes and waiting on writability up to a wall deadline.
 *     This is the pre-queue behaviour, kept for direct {@see Renderer} users
 *     and for teardown.
 *   - **Attached**: {@see enqueue()} appends and makes one non-blocking write
 *     attempt. The rest waits for the loop's write-readiness watcher
 *     ({@see LoopInterface::addWriteStream()}), so a stalled reader never
 *     holds the loop. The stream is switched to non-blocking for as long as it
 *     is attached; {@see close()} restores its blocking mode.
 *
 * Stall policy: the stall timer is progress-based. It is armed while bytes
 * are pending and re-armed on every successful write, so a slow but moving
 * reader is never cut off. When no progress happens for the stall bound the
 * unsent tail is dropped, the {@see drain()} promises reject with a
 * {@see WriteStalledException}, and the optional stall callback fires. The
 * bound is what keeps a dead client from growing the queue without limit.
 *
 * Coalescing is not done here. A terminal diff is only valid against the
 * frame the terminal actually received, so merging queued diffs would
 * corrupt the screen. The Program instead holds its render while the writer
 * has a backlog, so every update that arrives meanwhile collapses into one
 * frame when the queue drains.
 */
final class Writer
{
    /**
     * Stall bound, in seconds of zero progress, before a backlog is dropped
     * (audit sugar-core-018, rank 13). The same figure the synchronous path
     * always used as its wall deadline.
     */
    public const WRITE_WAIT_DEADLINE = 30.0;

    /**
     * How many consecutive {@see stream_select()} failures the synchronous
     * path tolerates before declaring the stream dead. Signal-dispatch races
     * surface as `false` even on a healthy stream.
     */
    public const MAX_SELECT_FALSE_RETRY = 5;

    /** Largest slice handed to one fwrite(), bounding per-write copies. */
    public const CHUNK_BYTES = 65536;

    /** @var resource */
    private $stream;

    /** Queued bytes; the first $offset of them are already on the stream. */
    private string $buffer = '';
    private int $offset = 0;

    /** Bytes queued and bytes handed off since the backlog last emptied. */
    private int $backlogTotal = 0;
    private int $backlogWritten = 0;

    private ?LoopInterface $loop = null;
    private ?LoopInterface $watchedOn = null;
    private float $stallSeconds = self::WRITE_WAIT_DEADLINE;
    private ?TimerInterface $stallTimer = null;
    /** @var (\Closure(WriteStalledException): void)|null */
    private ?\Closure $onStall = null;
    private bool $switchedNonBlocking = false;

    /** @var list<Deferred> */
    private array $waiters = [];

    /**
     * The error that ended the last backlog while no drain() was waiting on
     * it. enqueue() on an attached writer can fail synchronously, before the
     * caller reaches drain(); drain() must still report that failure rather
     * than resolve over bytes that never left. Cleared when a new backlog
     * starts and when drain() hands it out.
     */
    private ?\Throwable $undelivered = null;

    private ?Recorder $recorder = null;

    /** @param resource $stream */
    private function __construct($stream, ?LoopInterface $loop)
    {
        $this->stream = $stream;
        if ($loop !== null) {
            $this->attach($loop);
        }
    }

    /**
     * @param resource                           $stream
     * @param LoopInterface|null                 $loop    attach now; null starts detached
     */
    public static function new($stream, ?LoopInterface $loop = null): self
    {
        return new self($stream, $loop);
    }

    /**
     * Tee every flushed chunk to the recorder (or detach with null). The tee
     * sees only bytes the stream accepted, so a cassette never shows bytes the
     * terminal did not get.
     */
    public function setRecorder(?Recorder $recorder): void
    {
        $this->recorder = $recorder;
    }

    /**
     * Attach to a loop: later writes drain on write-readiness instead of
     * blocking. $onStall runs when a backlog is dropped for lack of progress.
     *
     * @param (\Closure(WriteStalledException): void)|null $onStall
     */
    public function attach(LoopInterface $loop, float $stallSeconds = self::WRITE_WAIT_DEADLINE, ?\Closure $onStall = null): void
    {
        $this->loop = $loop;
        $this->stallSeconds = $stallSeconds;
        $this->onStall = $onStall;
        if ($this->buffer !== '' && $this->offset < strlen($this->buffer)) {
            $this->pump();
        }
    }

    /**
     * Detach from the loop after flushing synchronously. Restores the stream's
     * blocking mode when attaching had switched it. Safe to call repeatedly.
     *
     * @return int bytes handed off by the flush
     */
    public function close(float $deadline = self::WRITE_WAIT_DEADLINE): int
    {
        $flushed = $this->flushNow($deadline);
        $this->loop = null;
        $this->stallSeconds = self::WRITE_WAIT_DEADLINE;
        $this->onStall = null;
        $this->restoreBlocking();
        return $flushed;
    }

    /** True when nothing is queued, so a new frame would not wait behind older bytes. */
    public function isIdle(): bool
    {
        return $this->offset >= strlen($this->buffer);
    }

    /** Bytes queued and not yet handed off to the stream. */
    public function pendingBytes(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    /**
     * Queue bytes for the stream. Detached, this writes them now; attached,
     * it makes one non-blocking attempt and leaves the rest to the loop.
     */
    public function enqueue(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->undelivered = null;
        if ($this->offset >= self::CHUNK_BYTES) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
        $this->buffer .= $bytes;
        $this->backlogTotal += strlen($bytes);

        if ($this->loop === null) {
            $this->flushNow();
            return;
        }
        $this->pump();
    }

    /**
     * Promise that settles once the queue is empty. It resolves with the bytes
     * the backlog handed off (0 when nothing was pending), or rejects with a
     * {@see WriteStalledException} or a write error when the tail is dropped.
     * A backlog that failed before anyone called drain() rejects here too, so
     * a synchronous write failure is never reported as success.
     *
     * @return PromiseInterface<int>
     */
    public function drain(): PromiseInterface
    {
        $deferred = new Deferred();
        if ($this->isIdle()) {
            if ($this->undelivered !== null) {
                $error = $this->undelivered;
                $this->undelivered = null;
                $deferred->reject($error);
                return $deferred->promise();
            }
            $deferred->resolve(0);
            return $deferred->promise();
        }
        $this->waiters[] = $deferred;
        if ($this->loop === null) {
            $this->flushNow();
        }
        return $deferred->promise();
    }

    /**
     * Drop the queue and reject its waiters with a plain error, without
     * touching the stream. For owners about to close the stream under a
     * live backlog (a worker being reaped).
     */
    public function cancel(): void
    {
        $this->fail(new \RuntimeException('output writer cancelled'));
    }

    /**
     * Synchronously write everything queued, bounded by $deadline of zero
     * progress. A stream that stays full past the bound drops its tail and
     * the waiters reject, the same outcome the old blocking path had.
     *
     * This is the blocking path, kept on purpose for the two writes that have
     * no loop to pump: before attach() (setup) and after close() (teardown),
     * plus any write made while the writer is detached. An attached writer
     * never reaches this loop from enqueue(). The stream_select() wait here is
     * therefore a moved wait, not a removed one, and it is bounded only by the
     * no-progress deadline.
     *
     * @return int bytes handed off by this call
     */
    public function flushNow(float $deadline = self::WRITE_WAIT_DEADLINE): int
    {
        $this->unwatch();
        $this->cancelStall();

        $flushed = 0;
        $selectFalse = 0;
        $progressAt = hrtime(true);
        while ($this->offset < strlen($this->buffer)) {
            if (!is_resource($this->stream)) {
                $this->fail(new \RuntimeException('output stream is closed'));
                return $flushed;
            }
            $piece = substr($this->buffer, $this->offset, self::CHUNK_BYTES);
            $n = @fwrite($this->stream, $piece);
            if ($n === false) {
                $this->fail(new \RuntimeException('output write failed'));
                return $flushed;
            }
            if ($n === 0) {
                if ((hrtime(true) - $progressAt) / 1e9 >= $deadline) {
                    $this->fail(new WriteStalledException($deadline, $this->backlogWritten, $this->backlogTotal));
                    return $flushed;
                }
                $w = [$this->stream];
                $r = $e = null;
                if (@stream_select($r, $w, $e, 1) === false) {
                    if (++$selectFalse > self::MAX_SELECT_FALSE_RETRY) {
                        $this->fail(new \RuntimeException('output stream select failed'));
                        return $flushed;
                    }
                }
                continue;
            }
            $selectFalse = 0;
            $progressAt = hrtime(true);
            $this->offset += $n;
            $this->backlogWritten += $n;
            $flushed += $n;
            $this->tee($n === strlen($piece) ? $piece : substr($piece, 0, $n));
        }
        $this->complete();
        return $flushed;
    }

    /** One non-blocking pass over the queue; arms or releases the watcher. */
    private function pump(): void
    {
        $this->ensureNonBlocking();
        $progressed = false;
        while ($this->offset < strlen($this->buffer)) {
            if (!is_resource($this->stream)) {
                $this->fail(new \RuntimeException('output stream is closed'));
                return;
            }
            $piece = substr($this->buffer, $this->offset, self::CHUNK_BYTES);
            $n = @fwrite($this->stream, $piece);
            if ($n === false) {
                $this->fail(new \RuntimeException('output write failed'));
                return;
            }
            if ($n === 0) {
                break;
            }
            $progressed = true;
            $this->offset += $n;
            $this->backlogWritten += $n;
            $this->tee($n === strlen($piece) ? $piece : substr($piece, 0, $n));
        }

        if ($this->offset >= strlen($this->buffer)) {
            $this->complete();
            return;
        }

        $this->watch();
        if ($progressed || $this->stallTimer === null) {
            $this->armStall();
        }
    }

    private function watch(): void
    {
        if ($this->watchedOn !== null || $this->loop === null || !is_resource($this->stream)) {
            return;
        }
        $this->loop->addWriteStream($this->stream, function (): void {
            $this->pump();
        });
        $this->watchedOn = $this->loop;
    }

    private function unwatch(): void
    {
        if ($this->watchedOn === null) {
            return;
        }
        if (is_resource($this->stream)) {
            $this->watchedOn->removeWriteStream($this->stream);
        }
        $this->watchedOn = null;
    }

    private function armStall(): void
    {
        $this->cancelStall();
        if ($this->loop === null) {
            return;
        }
        $this->stallTimer = $this->loop->addTimer($this->stallSeconds, function (): void {
            $this->stallTimer = null;
            $error = new WriteStalledException($this->stallSeconds, $this->backlogWritten, $this->backlogTotal);
            $this->fail($error);
            if ($this->onStall !== null) {
                ($this->onStall)($error);
            }
        });
    }

    private function cancelStall(): void
    {
        if ($this->stallTimer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->stallTimer);
        }
        $this->stallTimer = null;
    }

    private function complete(): void
    {
        $this->buffer = '';
        $this->offset = 0;
        $this->unwatch();
        $this->cancelStall();
        $written = $this->backlogWritten;
        $this->backlogTotal = 0;
        $this->backlogWritten = 0;
        $this->settle(null, $written);
    }

    private function fail(\Throwable $error): void
    {
        $this->buffer = '';
        $this->offset = 0;
        $this->unwatch();
        $this->cancelStall();
        $this->backlogTotal = 0;
        $this->backlogWritten = 0;
        $this->settle($error, 0);
    }

    /**
     * Resolve or reject every waiter. Queue state is reset before this runs,
     * so a waiter that re-enters the writer sees a clean backlog.
     */
    private function settle(?\Throwable $error, int $written): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        if ($error !== null && $waiters === []) {
            $this->undelivered = $error;
        }
        foreach ($waiters as $deferred) {
            if ($error === null) {
                $deferred->resolve($written);
            } else {
                $deferred->reject($error);
            }
        }
    }

    private function tee(string $bytes): void
    {
        $this->recorder?->recordOutput($bytes);
    }

    private function ensureNonBlocking(): void
    {
        if ($this->switchedNonBlocking || !is_resource($this->stream)) {
            return;
        }
        $meta = @stream_get_meta_data($this->stream);
        if (($meta['blocked'] ?? false) === true && @stream_set_blocking($this->stream, false)) {
            $this->switchedNonBlocking = true;
        }
    }

    private function restoreBlocking(): void
    {
        if (!$this->switchedNonBlocking) {
            return;
        }
        $this->switchedNonBlocking = false;
        if (is_resource($this->stream)) {
            @stream_set_blocking($this->stream, true);
        }
    }
}
