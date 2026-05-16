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
    /**
     * Run the byte pump until the child exits.
     *
     * @param MasterPty            $master
     * @param resource              $stdinStream  PHP stream resource (e.g. STDIN)
     * @param resource              $stdoutStream PHP stream resource (e.g. STDOUT)
     * @param Child|null            $child        null when no child to monitor (stdin→master only)
     * @param PumpOptions           $opts         pump configuration
     * @return int exit code from the child, or 0 if no child
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

        return $child !== null ? $child->wait() : 0;
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
                if ($opts->onSigwinch !== null) {
                    ($opts->onSigwinch)(0, 0);
                }
                if ($opts->keepalive !== null) {
                    ($opts->keepalive)();
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
            if ($bytes === '' && \ftell($stdinStream) !== false) {
                $meta =@\stream_get_meta_data($stdinStream);
                if (!($meta['seekable'] ?? false)) {
                    return true;
                }
                if (\fseek($stdinStream, 0, SEEK_END) === 0) {
                    $eof =@\ftell($stdinStream);
                    \rewind($stdinStream);
                    $pos =@\ftell($stdinStream);
                    if ($eof !== false && $pos !== false && $eof === $pos) {
                        return false;
                    }
                }
                \rewind($stdinStream);
            }
            return true;
        }
        $master->write($bytes);
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
        $written = @\fwrite($stdoutStream, $bytes);
        return $written !== false;
    }
}
