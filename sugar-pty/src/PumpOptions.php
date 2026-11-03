<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Core\Concerns\Mutable;

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
    use Mutable;

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
     * OPTIONAL caller-bound for the pump loop, microseconds measured from the
     * moment the loop starts. Null (the default) means NO bound — the
     * pump-family contract: a loop built on {@see \SugarCraft\Pty\Posix\PosixPump::run()},
     * {@see \SugarCraft\Pty\Posix\MultiPump::run()} or the ReactPump promise
     * carries no internal deadline; callers MUST bound.
     *
     * When set, the deadline is checked once per loop iteration (PosixPump /
     * ReactPump poll tick), so the loop returns control at most one
     * `selectTimeoutUs` tick late. Expiry is an EXIT CONDITION, not a kill:
     * the child keeps running, the master stays open, the pump instance
     * keeps its buffers — exactly like the stdin-EOF grace expiry, the
     * caller inspects the return contract (-1 = still-running) and decides.
     *
     * E717. The bound exists for non-interactive consumers (supervisors,
     * harnesses, batch record/replay); an interactive PTY session has no
     * legitimate total deadline and must leave this null.
     *
     * @param int<1, max>|null microseconds from loop start; null = unbounded
     */
    public readonly ?int $pumpDeadlineUs;

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
     * Called with new dimensions (cols, rows) when the pump OBSERVES a
     * size change on an idle tick — PosixPump/ReactPump poll
     * {@see MasterPty::size()} between reads. It is a size-diff
     * observer, not a signal hook: the signal itself is consumed by the
     * consumer's SignalForwarder, and a session that opened at 0x0
     * (lastKnownCols === 0) never fires it until a size is established.
     * MultiPump::tick() does not deliver it at all.
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
     * `PosixPump` tees stdin and master-output bytes through it; the
     * `candy-vcr record` CLI consumes the cassettes it writes.
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
     * @param int<1, max>|null               $pumpDeadlineUs
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
        ?int $pumpDeadlineUs = null,
    ) {
        if ($pumpDeadlineUs !== null && $pumpDeadlineUs <= 0) {
            throw new \InvalidArgumentException(
                "pumpDeadlineUs must be > 0 or null (unbounded); got {$pumpDeadlineUs}"
            );
        }
        if ($chunkBytes < 1) {
            throw new \InvalidArgumentException("chunkBytes must be >= 1; got {$chunkBytes}");
        }
        if ($selectTimeoutUs < 1) {
            throw new \InvalidArgumentException("selectTimeoutUs must be >= 1; got {$selectTimeoutUs}");
        }
        if ($flushDeadlineSec < 0.0) {
            throw new \InvalidArgumentException("flushDeadlineSec must be >= 0; got {$flushDeadlineSec}");
        }
        if ($stdinEofGraceSec < 0.0) {
            throw new \InvalidArgumentException("stdinEofGraceSec must be >= 0; got {$stdinEofGraceSec}");
        }
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
        $this->pumpDeadlineUs = $pumpDeadlineUs;
    }

    /**
     * SSH-session-tuned preset — historically the values hardcoded in
     * InProcessTransport. Those values BECAME the class defaults, so this
     * is now an alias for `new self()`: every field equals its
     * `DEFAULT_*` constant. Kept as a named entry point for call sites
     * that mean "pump an interactive SSH session" rather than "whatever
     * the defaults happen to be".
     *
     * @see \SugarCraft\SshServer\Transport\InProcessTransport
     */
    public static function sshDefault(): self
    {
        return new self();
    }

    public function withChunkBytes(int $v): self
    {
        return $this->mutate(['chunkBytes' => $v]);
    }

    public function withSelectTimeoutUs(int $v): self
    {
        return $this->mutate(['selectTimeoutUs' => $v]);
    }

    public function withFlushDeadlineSec(float $v): self
    {
        return $this->mutate(['flushDeadlineSec' => $v]);
    }

    public function withStdinEofGraceSec(float $v): self
    {
        return $this->mutate(['stdinEofGraceSec' => $v]);
    }

    /**
     * Replace the EOF byte (default \x04). Named after the termios
     * spelling of the concept; the backing property is `$veof`.
     */
    public function withVEOF(string $v): self
    {
        return $this->mutate(['veof' => $v]);
    }

    public function withKeepalive(?\Closure $v): self
    {
        return $this->mutate(['keepalive' => $v]);
    }

    public function withOnIdle(?\Closure $v): self
    {
        return $this->mutate(['onIdle' => $v]);
    }

    public function withOnSigwinch(?\Closure $v): self
    {
        return $this->mutate(['onSigwinch' => $v]);
    }

    public function withOnChildExit(?\Closure $v): self
    {
        return $this->mutate(['onChildExit' => $v]);
    }

    /**
     * Attach (or detach) a tee target for stdin / output / resize events.
     * Returns a new {@see PumpOptions} — never mutates `$this`.
     *
     * Consumers chain it like:
     * `new PumpOptions()->withRecorder(\SugarCraft\Vcr\Recorder::open(...))`.
     */
    public function withRecorder(?\SugarCraft\Core\Recorder $v): self
    {
        return $this->mutate(['recorder' => $v]);
    }

    /**
     * Attach (or detach) the optional pump-loop caller-bound (E717).
     * Returns a new {@see PumpOptions} — never mutates `$this`.
     *
     * @param int<1, max>|null $v microseconds from loop start; null = unbounded
     */
    public function withPumpDeadlineUs(?int $v): self
    {
        return $this->mutate(['pumpDeadlineUs' => $v]);
    }
}
