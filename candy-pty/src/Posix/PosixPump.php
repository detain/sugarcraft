<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\Pump as PumpContract;
use SugarCraft\Pty\PumpOptions;

/**
 * Byte pump that forwards stdin ↔ master PTY with back-pressure,
 * EOF grace, and optional SIGWINCH / keepalive / child-exit callbacks.
 *
 * Mirrors charmbracelet/bubbletea/pty.Pump for Go parity.
 * Extracted from {@see \SugarCraft\Wish\Transport\InProcessTransport}.
 *
 * @see creack/pty.Pump
 * @see portable-pty.Pump
 */
final class PosixPump implements PumpContract
{
    /** Buffered stdin bytes not yet written to master (partial-write remainder). */
    private string $pendingStdin = '';

    /**
     * Run the byte pump until pump conditions trigger: child exits,
     * STDOUT hits EPIPE, or STDIN reaches EOF and the post-EOF grace
     * window elapses.
     *
     * Does NOT block on {@see Child::wait()} when the child is still
     * alive — the caller is responsible for cleanup (kill / PTY close)
     * and the final wait() call. This keeps the supervisor in
     * candy-wish::InProcessTransport in control of the kill-on-stdin-
     * EOF policy: a long-running child (e.g. `/bin/sleep 5`) that
     * doesn't react to VEOF gets SIGHUP'd by the caller, not blocked
     * on inside the pump.
     *
     * @param MasterPty            $master
     * @param resource              $stdinStream  PHP stream resource (e.g. STDIN)
     * @param resource              $stdoutStream PHP stream resource (e.g. STDOUT)
     * @param Child|null            $child        null when no child to monitor (stdin→master only)
     * @param PumpOptions           $opts         pump configuration
     * @return int  the child's exit code if it has already exited
     *              by the time the pump returns; 0 if there is no
     *              child to monitor (stdin→master only); -1 if a
     *              child was supplied but is still running (caller
     *              must kill + wait()).
     * @see portable-pty.Pump.Run()
     */
    public function run(
        MasterPty $master,
        $stdinStream,
        $stdoutStream,
        ?Child $child = null,
        PumpOptions $opts = null,
    ): int {
        $opts ??= new PumpOptions();

        \stream_set_blocking($master->stream(), false);
        \stream_set_blocking($stdinStream, false);

        $this->pump($master, $stdinStream, $stdoutStream, $child, $opts);
        $this->flushMaster($master, $stdoutStream, $child, $opts);

        if ($child === null) {
            return 0;
        }
        if ($child->exited()) {
            return $child->exitCode() ?? 0;
        }
        return -1;
    }

    /**
     * Inner pump loop. Exits when:
     *  - child exits,
     *  - STDOUT hits EPIPE (peer closed read end),
     *  - STDIN reached EOF AND the post-EOF grace window expired
     *    (child got VEOF + had stdinEofGraceSec to notice;
     *    if it's still running we force-close the PTY).
     *
     * @param resource $stdinStream
     * @param resource $stdoutStream
     */
    private function pump(
        MasterPty $master,
        $stdinStream,
        $stdoutStream,
        ?Child $child,
        PumpOptions $opts,
    ): void {
        $masterStream = $master->stream();
        $stdinClosed = false;
        $stdinClosedDeadline = 0.0;

        // Track last known PTY size so we can detect resize on idle
        // ticks and fire onSigwinch. This makes onSigwinch work with
        // SignalForwarder-driven resize without requiring a callback
        // from MasterPty itself.
        $lastKnownCols = 0;
        $lastKnownRows = 0;
        if ($opts->onSigwinch !== null) {
            try {
                $initialSize = $master->size();
                $lastKnownCols = $initialSize['cols'];
                $lastKnownRows = $initialSize['rows'];
            } catch (\Throwable) {
                // size() can fail if the FD is not a TTY; guard silently.
            }
        }

        while (true) {
            if ($child !== null && $child->exited()) {
                return;
            }

            if ($stdinClosed && \microtime(true) > $stdinClosedDeadline) {
                return;
            }

            $r = $stdinClosed ? [$masterStream] : [$stdinStream, $masterStream];
            $w = null;
            $e = null;
            $ready = @\stream_select($r, $w, $e, 0, $opts->selectTimeoutUs);

            if ($ready === false) {
                if (\function_exists('pcntl_signal_dispatch')) {
                    @\pcntl_signal_dispatch();
                    continue;
                }
                return;
            }

            if ($ready === 0) {
                if ($opts->onIdle !== null) {
                    ($opts->onIdle)();
                }
                if ($opts->keepalive !== null) {
                    ($opts->keepalive)();
                }
                // Detect size change — SignalForwarder calls master->resize()
                // when SIGWINCH fires; the kernel stores the new size so the
                // next size() call returns updated dimensions.
                if ($opts->onSigwinch !== null && $lastKnownCols !== 0) {
                    try {
                        $current = $master->size();
                        if ($current['cols'] !== $lastKnownCols || $current['rows'] !== $lastKnownRows) {
                            ($opts->onSigwinch)((int) $current['cols'], (int) $current['rows']);
                            $lastKnownCols = $current['cols'];
                            $lastKnownRows = $current['rows'];
                        }
                    } catch (\Throwable) {
                        // size() can fail; guard silently.
                    }
                }
                continue;
            }

            foreach ($r as $stream) {
                if ($stream === $stdinStream && !$stdinClosed) {
                    if (!$this->pumpStdinToMaster($stdinStream, $master, $opts)) {
                        $stdinClosed = true;
                        $stdinClosedDeadline = \microtime(true) + $opts->stdinEofGraceSec;
                        @\fwrite($masterStream, $opts->veof);
                    }
                } elseif ($stream === $masterStream) {
                    if (!$this->pumpMasterToStdout($master, $stdoutStream, $opts)) {
                        return;
                    }
                }
            }
        }
    }

    /**
     * Drain remaining bytes from master after the pump loop exits.
     *
     * @param resource $stdoutStream
     */
    private function flushMaster(
        MasterPty $master,
        $stdoutStream,
        ?Child $child,
        PumpOptions $opts,
    ): void {
        $flushDeadline = \microtime(true) + $opts->flushDeadlineSec;
        while (\microtime(true) < $flushDeadline) {
            $tail = $master->read($opts->chunkBytes);
            if ($tail === null || $tail === '') {
                if ($child !== null && $child->exited()) {
                    break;
                }
                \usleep(20_000);
                continue;
            }
            @\fwrite($stdoutStream, $tail);
        }
    }

    /**
     * @param resource $stdinStream
     * @return bool false if STDIN reached EOF (caller should handle)
     */
    private function pumpStdinToMaster(
        $stdinStream,
        MasterPty $master,
        PumpOptions $opts,
    ): bool {
        $bytes = @\fread($stdinStream, $opts->chunkBytes);
        if ($bytes === false || $bytes === '') {
            if (\feof($stdinStream)) {
                return false;
            }
            return true;
        }

        // Prepend any buffered remainder from a prior partial write.
        $toWrite = $this->pendingStdin . $bytes;
        $this->pendingStdin = '';

        // Flush loop: handle short writes by looping until all bytes are accepted.
        $written = 0;
        while ($written < \strlen($toWrite)) {
            $n = $master->write(\substr($toWrite, $written));
            if ($n <= 0) {
                // Would-block or error — buffer remainder for next iteration.
                $this->pendingStdin = \substr($toWrite, $written);
                break;
            }
            $written += $n;
        }

        // P6.1 Recorder tap: tee stdin bytes into the recorder after
        // they've been written to master so a write failure leaves the
        // cassette consistent with what the child actually saw. Zero
        // overhead when recorder is null (single null-check per chunk).
        if ($opts->recorder !== null && $written > 0) {
            $opts->recorder->recordInputBytes(\substr($toWrite, 0, $written));
        }
        return true;
    }

    /**
     * @param resource $stdoutStream
     * @return bool false if STDOUT hit EPIPE (caller should exit pump)
     */
    private function pumpMasterToStdout(
        MasterPty $master,
        $stdoutStream,
        PumpOptions $opts,
    ): bool {
        $bytes = $master->read($opts->chunkBytes);
        if ($bytes === null || $bytes === '') {
            return true;
        }
        // P6.1 Recorder tap: record output BEFORE the fwrite to stdout
        // so a partial-write at exit doesn't leave the cassette ahead
        // of what the terminal actually saw — better to over-record
        // than to under-record (a torn frame still replays as bytes;
        // a missing chunk replays as a hole).
        if ($opts->recorder !== null) {
            $opts->recorder->recordOutput($bytes);
        }
        $written = @\fwrite($stdoutStream, $bytes);
        return $written !== false;
    }

    // Design note — SIGWINCH detection lives in the consumer:
    //
    // Real terminal-resize events are detected and propagated by the
    // consumer's {@see SignalForwarder::attachSigwinch} callback, which
    // pipes dimensions into `MasterPty::resize()`. The pump loop fires
    // {@see PumpOptions::$onIdle} on every idle tick — that is the
    // correct hook for keepalive / polling, not `onSigwinch`. The pump
    // itself is reusable outside interactive-session contexts (e.g.
    // piping a non-tty command into a recording).
}
