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
     * @param int<1, max>                    $chunkBytes
     * @param int<1, max>                    $selectTimeoutUs
     * @param float<0, max>                  $flushDeadlineSec
     * @param float<0, max>                  $stdinEofGraceSec
     * @param (\Closure(): void)|null       $keepalive
     * @param (\Closure(int, int): void)|null $onSigwinch
     * @param (\Closure(int): void)|null     $onChildExit
     */
    public function __construct(
        int $chunkBytes = self::DEFAULT_CHUNK_BYTES,
        int $selectTimeoutUs = self::DEFAULT_SELECT_TIMEOUT_US,
        float $flushDeadlineSec = self::DEFAULT_FLUSH_DEADLINE_SEC,
        float $stdinEofGraceSec = self::DEFAULT_STDIN_EOF_GRACE_SEC,
        string $veof = self::DEFAULT_VEOF,
        ?\Closure $keepalive = null,
        ?\Closure $onSigwinch = null,
        ?\Closure $onChildExit = null,
    ) {
        $this->chunkBytes = $chunkBytes;
        $this->selectTimeoutUs = $selectTimeoutUs;
        $this->flushDeadlineSec = $flushDeadlineSec;
        $this->stdinEofGraceSec = $stdinEofGraceSec;
        $this->veof = $veof;
        $this->keepalive = $keepalive;
        $this->onSigwinch = $onSigwinch;
        $this->onChildExit = $onChildExit;
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $v,
            onChildExit: $this->onChildExit,
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
            onSigwinch: $this->onSigwinch,
            onChildExit: $v,
        );
    }
}
