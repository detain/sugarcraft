<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

/**
 * Stream-generic read pump: `stream_select()` over any set of plain streams
 * (child pipes, a FIFO, a socket pair, a pty master's stream) with the same
 * signal and deadline discipline {@see PosixPump} / {@see MultiPump} carry,
 * but without requiring a {@see \SugarCraft\Pty\Contract\MasterPty}.
 *
 * Extracted from candy-code's hand-rolled relay loops (PtyRelay's
 * master+fifo walk, DrainsStreamedChild's stdout+stderr walk). The waits go
 * through {@see PosixMasterPty::retryOnEintr()}, so a SIGWINCH/SIGINT
 * handler under `pcntl_async_signals()` interrupting the select is
 * dispatched and the wait resumed against the ORIGINAL deadline instead of
 * being mistaken for end-of-output (a truncated tail) or restarting the full
 * timeout on every signal.
 *
 * Ownership contract (child-lifetime): the pump never spawns, kills, or
 * closes anything. A deadline or stop request is an EXIT CONDITION, not a
 * kill; the caller owns the child and decides what to do with the answer.
 * Source streams are switched non-blocking for the run and restored on every
 * way out, including a throwing callback.
 *
 * Unlike PosixPump the pump carries no stdin/VEOF policy. A relay's write leg
 * is {@see writeStream()}.
 */
final class StreamPump
{
    /** Every EOF-ending source reached EOF. */
    public const EOF = 'eof';

    /** The shouldStop callback answered true. */
    public const STOPPED = 'stopped';

    /** The absolute deadline passed with sources still open. */
    public const EXPIRED = 'expired';

    /** `stream_select()` failed for a reason other than EINTR (EBADF: a caller-closed stream). */
    public const SELECT_FAILED = 'select-failed';

    public const DEFAULT_CHUNK_BYTES = 8192;
    public const DEFAULT_TICK_US = 50_000;

    /**
     * @param list<array{stream: resource, onChunk: \Closure(string): void, endsOnEof: bool}> $sources
     */
    private function __construct(
        private readonly array $sources,
        private readonly int $chunkBytes,
        private readonly int $tickUs,
        private readonly ?float $deadlineAt,
        private readonly ?\Closure $onTick,
        private readonly ?\Closure $shouldStop,
    ) {
    }

    public static function new(): self
    {
        return new self([], self::DEFAULT_CHUNK_BYTES, self::DEFAULT_TICK_US, null, null, null);
    }

    /**
     * Watch $stream and hand every chunk read from it to $onChunk.
     *
     * @param resource $stream
     * @param \Closure(string): void $onChunk
     * @param bool $endsOnEof true (default): this source's EOF counts toward
     *        the pump finishing with {@see EOF}. false: an EOF merely drops the
     *        source from the select set (a relay's input FIFO whose writer
     *        closed must not end the session); the run then ends only by
     *        stop/deadline once nothing ending remains.
     */
    public function withSource($stream, \Closure $onChunk, bool $endsOnEof = true): self
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('StreamPump::withSource requires an open stream resource');
        }
        $sources = $this->sources;
        $sources[] = ['stream' => $stream, 'onChunk' => $onChunk, 'endsOnEof' => $endsOnEof];
        return new self($sources, $this->chunkBytes, $this->tickUs, $this->deadlineAt, $this->onTick, $this->shouldStop);
    }

    public function withChunkBytes(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException("chunkBytes must be >= 1; got {$bytes}");
        }
        return new self($this->sources, $bytes, $this->tickUs, $this->deadlineAt, $this->onTick, $this->shouldStop);
    }

    /** Longest single select wait; also the cadence of onTick/shouldStop/deadline re-checks. */
    public function withTickUs(int $us): self
    {
        if ($us < 1) {
            throw new \InvalidArgumentException("tickUs must be >= 1; got {$us}");
        }
        return new self($this->sources, $this->chunkBytes, $us, $this->deadlineAt, $this->onTick, $this->shouldStop);
    }

    /** Absolute `microtime(true)` instant after which run() answers {@see EXPIRED}; null = unbounded. */
    public function withDeadlineAt(?float $at): self
    {
        return new self($this->sources, $this->chunkBytes, $this->tickUs, $at, $this->onTick, $this->shouldStop);
    }

    /** Convenience: deadline $seconds from now. */
    public function withTimeout(float $seconds): self
    {
        return $this->withDeadlineAt(\microtime(true) + $seconds);
    }

    /**
     * Called once per loop iteration (after pending signals are dispatched,
     * before the select) for parent-patrol, wall-clock, and escalation-ladder
     * work. Exceptions propagate; the pump restores stream modes first.
     *
     * @param ?\Closure(): void $fn
     */
    public function withOnTick(?\Closure $fn): self
    {
        return new self($this->sources, $this->chunkBytes, $this->tickUs, $this->deadlineAt, $fn, $this->shouldStop);
    }

    /**
     * Checked at the top of every iteration; true ends the run with {@see STOPPED}
     * (a child that exited, or a caller whose appetite is full).
     *
     * @param ?\Closure(): bool $fn
     */
    public function withShouldStop(?\Closure $fn): self
    {
        return new self($this->sources, $this->chunkBytes, $this->tickUs, $this->deadlineAt, $this->onTick, $fn);
    }

    /**
     * Drive the pump. Returns one of {@see EOF}, {@see STOPPED}, {@see EXPIRED},
     * {@see SELECT_FAILED}.
     */
    public function run(): string
    {
        /** @var array<int, bool> $wasBlocking */
        $wasBlocking = [];
        $open = [];
        foreach ($this->sources as $i => $src) {
            $meta = @\stream_get_meta_data($src['stream']);
            $wasBlocking[$i] = \is_array($meta) ? (bool) ($meta['blocked'] ?? true) : true;
            \stream_set_blocking($src['stream'], false);
            $open[$i] = $src;
        }

        try {
            return $this->loop($open);
        } finally {
            foreach ($this->sources as $i => $src) {
                if (\is_resource($src['stream'])) {
                    @\stream_set_blocking($src['stream'], $wasBlocking[$i]);
                }
            }
        }
    }

    /**
     * @param array<int, array{stream: resource, onChunk: \Closure(string): void, endsOnEof: bool}> $open
     */
    private function loop(array $open): string
    {
        while (true) {
            if (\function_exists('pcntl_signal_dispatch')) {
                @\pcntl_signal_dispatch();
            }

            if ($this->shouldStop !== null && ($this->shouldStop)()) {
                return self::STOPPED;
            }
            if ($this->deadlineAt !== null && \microtime(true) >= $this->deadlineAt) {
                return self::EXPIRED;
            }
            // EOF = every EOF-ending source drained (or nothing registered at
            // all). A pump holding only persistent sources lives on
            // stop/deadline alone.
            if ($this->sources === [] || ($this->hasEndingSource() && !$this->anyEndingOpen($open))) {
                return self::EOF;
            }

            if ($this->onTick !== null) {
                ($this->onTick)();
            }

            $tickUs = $this->tickUs;
            if ($this->deadlineAt !== null) {
                $leftUs = (int) \max(0.0, ($this->deadlineAt - \microtime(true)) * 1_000_000);
                $tickUs = \min($tickUs, $leftUs);
            }

            if ($open === []) {
                \usleep($tickUs);
                continue;
            }

            $read = [];
            foreach ($open as $src) {
                if (!\is_resource($src['stream'])) {
                    // Closed under us: select would TypeError, so name it.
                    return self::SELECT_FAILED;
                }
                $read[] = $src['stream'];
            }
            $w = null;
            $e = null;
            $ready = PosixMasterPty::retryOnEintr($read, $w, $e, \intdiv($tickUs, 1_000_000), $tickUs % 1_000_000);
            if ($ready === false) {
                return self::SELECT_FAILED;
            }

            foreach ($open as $i => $src) {
                if (!\in_array($src['stream'], $read, true)) {
                    continue;
                }
                $chunk = @\fread($src['stream'], $this->chunkBytes);
                if ($chunk === false || $chunk === '') {
                    // feof(), not the empty read: a non-blocking stream
                    // legitimately answers '' when nothing has arrived YET.
                    if (\feof($src['stream'])) {
                        unset($open[$i]);
                    }
                    continue;
                }
                ($src['onChunk'])($chunk);
            }
        }
    }

    /**
     * @param array<int, array{stream: resource, onChunk: \Closure(string): void, endsOnEof: bool}> $open
     */
    private function anyEndingOpen(array $open): bool
    {
        foreach ($open as $src) {
            if ($src['endsOnEof']) {
                return true;
            }
        }
        return false;
    }

    private function hasEndingSource(): bool
    {
        foreach ($this->sources as $src) {
            if ($src['endsOnEof']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write $bytes to $stream until it is all accepted, the deadline passes,
     * or the reader is gone — the relay's input leg (master/pipe/socket).
     *
     * Non-blocking with a writability select (EINTR-resumed), so a full pipe
     * buffer parks this call for at most $timeoutSeconds instead of wedging it
     * in a blocking fwrite. The stream's blocking mode is restored on every
     * way out. EPIPE-style failure (fwrite false) ends the call quietly: the
     * returned count tells the caller how much landed.
     *
     * @param resource $stream
     * @return int bytes accepted (== strlen($bytes) on full delivery)
     */
    public static function writeStream($stream, string $bytes, float $timeoutSeconds = 5.0): int
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('StreamPump::writeStream requires an open stream resource');
        }
        $total = \strlen($bytes);
        if ($total === 0) {
            return 0;
        }
        $meta = @\stream_get_meta_data($stream);
        $wasBlocking = \is_array($meta) ? (bool) ($meta['blocked'] ?? true) : true;
        \stream_set_blocking($stream, false);
        $deadline = \microtime(true) + $timeoutSeconds;
        $sent = 0;

        try {
            while ($sent < $total) {
                $n = @\fwrite($stream, \substr($bytes, $sent));
                if ($n === false) {
                    break;
                }
                if ($n > 0) {
                    $sent += $n;
                    continue;
                }
                $left = $deadline - \microtime(true);
                if ($left <= 0) {
                    break;
                }
                $r = [];
                $w = [$stream];
                $e = null;
                $us = (int) ($left * 1_000_000);
                $ready = PosixMasterPty::retryOnEintr($r, $w, $e, \intdiv($us, 1_000_000), $us % 1_000_000);
                if ($ready === false) {
                    break;
                }
            }
        } finally {
            if (\is_resource($stream)) {
                @\stream_set_blocking($stream, $wasBlocking);
            }
        }
        return $sent;
    }
}
