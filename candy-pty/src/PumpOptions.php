<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Readonly value object holding pump configuration for the PTY byte pump.
 *
 * Mirrors charmbracelet/bubbletea/pty.PumpOptions for Go parity.
 *
 * Defaults are pulled verbatim from the SSH-tested values in
 * InProcessTransport so there is zero behavioural drift on migration.
 */
final class PumpOptions
{
    /** Bytes per fread/fwrite chunk in the pump loop. */
    public const DEFAULT_CHUNK_BYTES = 4096;

    /**
     * stream_select poll interval — 50 ms keeps CPU near zero on idle
     * while staying snappy under typing-rate I/O.
     *
     * Mirrors InProcessTransport::PUMP_TIMEOUT_USEC.
     */
    public const DEFAULT_SELECT_TIMEOUT_US = 50000;

    /**
     * Final-flush window after the pump loop exits, before closing
     * the PTY. Drains any tail bytes the kernel buffered on the master
     * after the child exited or stdin EOF'd.
     *
     * Mirrors InProcessTransport::FLUSH_DEADLINE_SEC.
     */
    public const DEFAULT_FLUSH_DEADLINE_SEC = 0.5;

    /**
     * Grace window after STDIN EOF for the child to notice (via VEOF /
     * SIGHUP) and exit on its own before we force-close the PTY (which
     * delivers SIGHUP via lost-ctty). 300 ms is comfortable for shells
     * / cat / well-behaved children; daemon-style children (sleep,
     * non-tty-aware loops) get force-closed at the deadline.
     *
     * Mirrors InProcessTransport::STDIN_EOF_GRACE_SEC.
     */
    public const DEFAULT_STDIN_EOF_GRACE_SEC = 0.3;

    /**
     * VEOF char (Ctrl+D, 0x04). In cooked-mode PTY this signals EOF
     * on the slave's stdin to the inner child.
     *
     * Mirrors InProcessTransport::VEOF.
     */
    public const DEFAULT_VEOF = "\x04";

    /** @param int<1, max> bytes per read from stdin/master */
    public readonly int $chunkBytes;

    /** @param int<1, max> stream_select timeout in microseconds */
    public readonly int $selectTimeoutUs;

    /** @param float<0, max> max time to wait for a write to complete */
    public readonly float $flushDeadlineSec;

    /** @param float<0, max> grace period after stdin closes before stopping pump */
    public readonly float $stdinEofGraceSec;

    /** VEOF character for isatty detection */
    public readonly string $veof;

    /**
     * Called each pump loop iteration when idle (stream_select timed out).
     * Null when no callback is registered.
     *
     * @var (\Closure(): void)|null
     */
    public readonly \Closure|null $keepalive;

    /**
     * Called on each idle tick (stream_select returned 0). Use for
     * keepalive, polling, or any periodic housekeeping. Distinct from
     * {@see onSigwinch} which carries real terminal-dimension values
     * and is driven by the consumer's {@see SignalForwarder} callback.
     *
     * @var (\Closure(): void)|null
     */
    public readonly \Closure|null $onIdle;

    /**
     * Called on SIGWINCH with new dimensions (cols, rows).
     * Null when no callback is registered.
     *
     * @var (\Closure(int, int): void)|null
     */
    public readonly \Closure|null $onSigwinch;

    /**
     * Called when the child process exits.
     * Null when no callback is registered.
     *
     * @var (\Closure(int): void)|null
     */
    public readonly \Closure|null $onChildExit;

    /**
     * Optional tee target for stdin / master-output / resize events
     * so a higher-level CLI (candy-vcr's RecordCommand, debug taps,
     * crash bundlers) can capture a real PTY session without changing
     * pump semantics.
     *
     * Null = no recording overhead — every recorder call in
     * {@see \SugarCraft\Pty\Posix\PosixPump} is guarded behind a null
     * check so the pump loop is byte-identical to the pre-recorder
     * path when this is unset.
     *
     * Wired in plan step P6.1 ("PosixPump Recorder tap") and feeds
     * P6.5's `candy-vcr record` CLI.
     *
     * @see \SugarCraft\Core\Recorder  the interface contract.
     */
    public readonly ?\SugarCraft\Core\Recorder $recorder;

    /**
     * @param int<1, max>                    $chunkBytes
     * @param int<1, max>                    $selectTimeoutUs
     * @param float<0, max>                  $flushDeadlineSec
     * @param float<0, max>                  $stdinEofGraceSec
     * @param (\Closure(): void)|null       $keepalive
     * @param (\Closure(): void)|null       $onIdle
     * @param (\Closure(int, int): void)|null $onSigwinch
     * @param (\Closure(int): void)|null     $onChildExit
     * @param \SugarCraft\Core\Recorder|null $recorder
     */
    public function __construct(
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
        int $selectTimeoutUs = self::DEFAULT_SELECT_TIMEOUT_US,
        float $flushDeadlineSec = self::DEFAULT_FLUSH_DEADLINE_SEC,
        float $stdinEofGraceSec = self::DEFAULT_STDIN_EOF_GRACE_SEC,
        string $veof = self::DEFAULT_VEOF,
        ?\Closure $keepalive = null,
        ?\Closure $onIdle = null,
        ?\Closure $onSigwinch = null,
        ?\Closure $onChildExit = null,
        ?\SugarCraft\Core\Recorder $recorder = null,
    ) {
        $this->chunkBytes = $chunkBytes;
        $this->selectTimeoutUs = $selectTimeoutUs;
        $this->flushDeadlineSec = $flushDeadlineSec;
        $this->stdinEofGraceSec = $stdinEofGraceSec;
        $this->veof = $veof;
        $this->keepalive = $keepalive;
        $this->onIdle = $onIdle;
        $this->onSigwinch = $onSigwinch;
        $this->onChildExit = $onChildExit;
        $this->recorder = $recorder;
    }

    /**
     * SSH-session-tuned preset — matches values previously hardcoded
     * in InProcessTransport for SSH session behaviour.
     *
     * @see \SugarCraft\Wish\Transport\InProcessTransport
     */
    public static function sshDefault(): self
    {
        return new self(
            chunkBytes: self::DEFAULT_CHUNK_BYTES,
            selectTimeoutUs: self::DEFAULT_SELECT_TIMEOUT_US,
            flushDeadlineSec: self::DEFAULT_FLUSH_DEADLINE_SEC,
            stdinEofGraceSec: self::DEFAULT_STDIN_EOF_GRACE_SEC,
            veof: self::DEFAULT_VEOF,
        );
    }

    public function withChunkBytes(int $v): self
    {
        return new self(
            chunkBytes: $v,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withSelectTimeoutUs(int $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $v,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withFlushDeadlineSec(float $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $v,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withStdinEofGraceSec(float $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $v,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withVEOF(string $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $v,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withKeepalive(?\Closure $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $v,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withOnIdle(?\Closure $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $v,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withOnSigwinch(?\Closure $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $v,
            onChildExit: $this->onChildExit,
            recorder: $this->recorder,
        );
    }

    public function withOnChildExit(?\Closure $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $v,
            recorder: $this->recorder,
        );
    }

    /**
     * Attach (or detach) a tee target for stdin / output / resize events.
     * Returns a new {@see PumpOptions} — never mutates `$this`.
     *
     * Wired in plan step P6.1; consumers chain like:
     * `new PumpOptions()->withRecorder(\SugarCraft\Vcr\Recorder::open(...))`.
     */
    public function withRecorder(?\SugarCraft\Core\Recorder $v): self
    {
        return new self(
            chunkBytes: $this->chunkBytes,
            selectTimeoutUs: $this->selectTimeoutUs,
            flushDeadlineSec: $this->flushDeadlineSec,
            stdinEofGraceSec: $this->stdinEofGraceSec,
            veof: $this->veof,
            keepalive: $this->keepalive,
            onIdle: $this->onIdle,
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
            recorder: $v,
        );
    }
}
