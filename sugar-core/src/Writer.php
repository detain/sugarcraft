<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\EventLoop\LoopInterface;

/**
 * The ordered byte sink a terminal, pipe or worker stdin writes through.
 *
 * Two modes, chosen by whether a loop is attached:
 *
 *  - Synchronous (no loop): {@see write()} blocks until every byte is handed
 *    to the stream or the wall deadline passes. This is the historical
 *    behaviour, kept for standalone {@see Renderer}s and direct callers.
 *  - Queued ({@see attach()}ed to a loop): the stream goes non-blocking and
 *    {@see write()} never blocks. Bytes the stream takes are sent at once;
 *    the rest wait in one ordered buffer that a write watcher on the loop
 *    drains as the reader catches up. A reader that stalls delays only its
 *    own bytes, never timers, input or other workers.
 *
 * Frame coalescing: every write made while bytes are pending joins the same
 * buffer, so one drain pass sends them in as few syscalls as the stream
 * accepts. A queued frame is never superseded: the diff renderer assumes
 * every diff it emitted reaches the terminal, so dropping one would leave
 * the screen wrong. A saturated writer instead tells its producer to hold
 * the next frame ({@see isSaturated()}). Non-frame output has no such hold,
 * so Program drops it once the backlog saturates, which bounds the backlog
 * at the threshold plus one message. The backlog is discarded only when
 * the stream dies ({@see fail()}), on {@see release()}, or when a flush
 * misses its deadline ({@see detach()}); teardown bytes such as cursor-show
 * can be lost in that last case when a terminal stalls past the deadline.
 */
final class Writer
{
    /**
     * Wall-clock ceiling for a synchronous write, and for {@see flush()}, when
     * the stream stays full. A stream that is still full past it has its tail
     * dropped. Renderer::WRITE_WAIT_DEADLINE aliases this value.
     */
    public const WRITE_WAIT_DEADLINE = 30.0;

    /**
     * How many consecutive stream_select() false returns are tolerated before
     * the stream is declared dead. A false return is a signal-dispatch race,
     * not "not writable", so a single one must not drop the tail.
     */
    public const MAX_SELECT_FALSE_RETRY = 5;

    /** Largest single fwrite() issued by the queued drain. */
    public const CHUNK_BYTES = 65536;

    /**
     * Pending bytes at which {@see isSaturated()} turns true (8 MiB). Frames
     * are held at this threshold; non-frame output (RawMsg, PrintMsg) is
     * dropped at it by Program, so the backlog stays bounded near it.
     */
    public const DEFAULT_MAX_PENDING = 8388608;

    /** Consumed prefix size above which the buffer is compacted. */
    private const COMPACT_THRESHOLD = 1048576;

    private const DRAINED = 0;
    private const BLOCKED = 1;
    private const FAILED = 2;

    /** @var string queued bytes; the first $offset bytes are already sent */
    private string $buffer = '';
    private int $offset = 0;
    /** Total bytes handed to the stream through this writer. */
    private int $handedOff = 0;
    private ?LoopInterface $loop = null;
    private bool $watching = false;
    /** Blocking mode of the stream before attach(); null when there was none. */
    private ?bool $wasBlocking = null;
    private ?Recorder $recorder = null;
    private ?\Closure $onDrain = null;
    private ?\Closure $onError = null;

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
        private readonly int $maxPending = self::DEFAULT_MAX_PENDING,
    ) {
    }

    /** @return resource The stream this writer sends to. */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * Tee every byte this writer actually sends to the recorder (null detaches).
     * The tee sees flushed bytes only, so a cassette never claims bytes the
     * stream refused.
     */
    public function setRecorder(?Recorder $recorder): void
    {
        $this->recorder = $recorder;
    }

    /**
     * Called once each time a queued backlog fully drains.
     *
     * @param (\Closure(): void)|null $callback
     */
    public function onDrain(?\Closure $callback): void
    {
        $this->onDrain = $callback;
    }

    /**
     * Called when a queued drain hits a dead stream. The backlog is discarded
     * before the call. A later write() tries the stream again.
     *
     * @param (\Closure(): void)|null $callback
     */
    public function onError(?\Closure $callback): void
    {
        $this->onError = $callback;
    }

    /**
     * Switch to queued mode: non-blocking writes, drained on $loop.
     * Idempotent for the same loop. The previous blocking mode is restored by
     * {@see detach()} or {@see release()}.
     */
    public function attach(LoopInterface $loop): void
    {
        if ($this->loop !== null) {
            return;
        }
        $this->loop = $loop;
        if (is_resource($this->stream)) {
            $meta = stream_get_meta_data($this->stream);
            $this->wasBlocking = $meta['blocked'];
            @stream_set_blocking($this->stream, false);
        }
    }

    /**
     * Flush the backlog, stop watching and return to synchronous mode.
     * Returns false when the backlog could not be flushed before the deadline;
     * those bytes are then discarded.
     */
    public function detach(float $deadline = self::WRITE_WAIT_DEADLINE): bool
    {
        if ($this->loop === null) {
            return true;
        }
        $flushed = $this->flush($deadline);
        $this->release();
        return $flushed;
    }

    /**
     * Stop watching and return to synchronous mode without flushing. Any
     * backlog is discarded. Use it when the stream is about to be closed.
     */
    public function release(): void
    {
        $this->stopWatching();
        $this->buffer = '';
        $this->offset = 0;
        if ($this->loop !== null && $this->wasBlocking !== null && is_resource($this->stream)) {
            @stream_set_blocking($this->stream, $this->wasBlocking);
        }
        $this->loop = null;
        $this->wasBlocking = null;
    }

    /**
     * Send $bytes in order. Synchronous mode blocks up to $deadline; queued
     * mode never blocks and ignores $deadline.
     */
    public function write(string $bytes, float $deadline = self::WRITE_WAIT_DEADLINE): void
    {
        if ($bytes === '') {
            return;
        }
        try {
            if ($this->loop === null) {
                $flushed = self::writeAll($this->stream, $bytes, $deadline);
                $this->handedOff += $flushed;
                if ($this->recorder !== null && $flushed > 0) {
                    $this->recorder->recordOutput($flushed === strlen($bytes) ? $bytes : substr($bytes, 0, $flushed));
                }
                return;
            }
            $this->buffer .= $bytes;
            if ($this->watching) {
                return;
            }
            $state = $this->pump();
            if ($state === self::BLOCKED) {
                $this->startWatching();
            }
        } catch (\Throwable) {
            // A closed resource makes fwrite()/stream_select() throw TypeError,
            // which the @ silencer does not cover. Teardown and failed-stream
            // writes are best-effort, so the bytes are dropped.
        }
    }

    /** Bytes queued and not yet handed to the stream. */
    public function pendingBytes(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    /** True once the backlog reaches the saturation threshold. */
    public function isSaturated(): bool
    {
        return $this->pendingBytes() >= $this->maxPending;
    }

    /** Total bytes handed to the stream through this writer. */
    public function handedOff(): int
    {
        return $this->handedOff;
    }

    /**
     * Synchronously drain the backlog, waiting on writability for up to
     * $deadline seconds. Returns true when nothing is left queued.
     */
    public function flush(float $deadline = self::WRITE_WAIT_DEADLINE): bool
    {
        if ($this->loop === null) {
            return true;
        }
        $start = hrtime(true);
        $selectFalse = 0;
        while (true) {
            $state = $this->pump();
            if ($state !== self::BLOCKED) {
                break;
            }
            $remaining = $deadline - (hrtime(true) - $start) / 1e9;
            if ($remaining <= 0 || !is_resource($this->stream)) {
                break;
            }
            $wait = min(1.0, $remaining);
            $seconds = (int) floor($wait);
            $micros = (int) (($wait - $seconds) * 1e6);
            $r = $e = null;
            $w = [$this->stream];
            if (@stream_select($r, $w, $e, $seconds, $micros) === false) {
                if (++$selectFalse > self::MAX_SELECT_FALSE_RETRY) {
                    break;
                }
                continue;
            }
            $selectFalse = 0;
        }
        if ($this->pendingBytes() === 0) {
            $this->stopWatching();
            return true;
        }
        return false;
    }

    /**
     * Drain as far as the stream accepts without blocking.
     */
    private function pump(): int
    {
        while ($this->pendingBytes() > 0) {
            if (!is_resource($this->stream)) {
                $this->fail();
                return self::FAILED;
            }
            $chunk = substr($this->buffer, $this->offset, self::CHUNK_BYTES);
            try {
                $n = @fwrite($this->stream, $chunk);
            } catch (\Throwable) {
                $n = false;
            }
            if ($n === false) {
                $this->fail();
                return self::FAILED;
            }
            if ($n === 0) {
                return self::BLOCKED;
            }
            $this->consume($n, $chunk);
        }
        return self::DRAINED;
    }

    private function consume(int $n, string $chunk): void
    {
        $this->offset += $n;
        $this->handedOff += $n;
        if ($this->recorder !== null) {
            $this->recorder->recordOutput($n === strlen($chunk) ? $chunk : substr($chunk, 0, $n));
        }
        if ($this->offset >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        } elseif ($this->offset >= self::COMPACT_THRESHOLD) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }

    private function fail(): void
    {
        $this->stopWatching();
        $this->buffer = '';
        $this->offset = 0;
        if ($this->onError !== null) {
            ($this->onError)();
        }
    }

    private function startWatching(): void
    {
        if ($this->watching || $this->loop === null) {
            return;
        }
        $this->watching = true;
        $this->loop->addWriteStream($this->stream, function (): void {
            $this->onWritable();
        });
    }

    private function onWritable(): void
    {
        $state = $this->pump();
        if ($state === self::BLOCKED) {
            return;
        }
        // DRAINED and FAILED both end the watch. fail() has already run for
        // FAILED, so onDrain only fires for a real, complete drain.
        $this->stopWatching();
        if ($state === self::DRAINED && $this->onDrain !== null) {
            ($this->onDrain)();
        }
    }

    private function stopWatching(): void
    {
        if (!$this->watching) {
            return;
        }
        $this->watching = false;
        if ($this->loop !== null && is_resource($this->stream)) {
            $this->loop->removeWriteStream($this->stream);
        }
    }

    /**
     * Write every byte of $bytes to $out synchronously, looping over short
     * writes, and return the number of bytes actually flushed.
     *
     * @param resource $out
     */
    private static function writeAll($out, string $bytes, float $deadline): int
    {
        $len = strlen($bytes);
        $written = 0;
        $selectFalse = 0;
        $start = hrtime(true);
        while ($written < $len) {
            $n = @fwrite($out, $written === 0 ? $bytes : substr($bytes, $written));
            if ($n === false) {
                return $written; // unrecoverable write error — drop the rest
            }
            if ($n === 0) {
                if ((hrtime(true) - $start) / 1e9 >= $deadline) {
                    return $written; // wall deadline — flushed prefix is the truth
                }
                // Non-blocking stream with a full buffer: wait for writability
                // rather than busy-spinning and dropping the tail.
                $w = [$out];
                $r = $e = null;
                if (@stream_select($r, $w, $e, 1) === false) {
                    if (++$selectFalse > self::MAX_SELECT_FALSE_RETRY) {
                        return $written; // persistently failing select — stream is dead
                    }
                    continue;
                }
                $selectFalse = 0;
                continue;
            }
            $written += $n;
        }
        return $written;
    }
}
