<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtyException;

/**
 * The ConPTY master end: the pseudo console plus the two pipes that carry
 * its side of the byte stream.
 *
 * ## Ownership
 *
 * This object owns the HPCON handle AND all four pipe handles, which is
 * why {@see ConPtySystem::open()} hands them over only on success and why
 * {@see close()} releases exactly that set. A close that forgot a pipe
 * would leave one pair pinned per session — the leak the primitives'
 * docblock warns about, and this class is the only place it can be kept.
 *
 * ## Why read() polls
 *
 * PeekNamedPipe is the only ConPTY-side primitive that answers "is there
 * output yet" without blocking, and there is no PHP stream resource to
 * hand stream_select (see {@see stream()}). The poll loop preserves the
 * contract's meanings exactly — null on timeout, empty string at EOF —
 * with a per-operation ceiling supplied by the caller's $timeout, never a
 * blanket one (a null $timeout blocks until data or EOF, mirroring the
 * POSIX master).
 *
 * @see ConPtyApi — the primitives
 * @see portable-pty.conpty::ConPty::read — same poll-over-peek design
 */
final class ConPtyMasterPty implements MasterPty
{
    /**
     * Microseconds between PeekNamedPipe polls while a read still has
     * budget left. Small enough that a bounded read with a 0.05 s timeout
     * observes data that lands mid-window; large enough that the poll
     * never saturates the pipe-check API.
     */
    public const POLL_INTERVAL_US = 1000;

    /** @var array{read: int, write: int} parent→child pipe (master holds the write end) */
    private readonly array $inputPipe;

    /** @var array{read: int, write: int} child→parent pipe (master holds the read end) */
    private readonly array $outputPipe;

    private int $cols;

    private int $rows;

    private bool $closed = false;

    /**
     * @param array{read: int, write: int} $inputPipe
     * @param array{read: int, write: int} $outputPipe
     */
    public function __construct(
        private readonly ConPtyApi $api,
        private readonly int $pseudoConsole,
        array $inputPipe,
        array $outputPipe,
        int $cols,
        int $rows,
    ) {
        $this->inputPipe = $inputPipe;
        $this->outputPipe = $outputPipe;
        $this->cols = $cols;
        $this->rows = $rows;
    }

    /**
     * Package seam: the slave adapter builds its CreateProcess call
     * through the same binding, and the job/attribute handles only make
     * sense next to this pseudo console.
     */
    public function api(): ConPtyApi
    {
        return $this->api;
    }

    /**
     * Package seam: the HPCON handle for {@see ConPtySlavePty::spawn()}
     * and for {@see ConPtySlavePty::path()}'s label.
     */
    public function pseudoConsole(): int
    {
        return $this->pseudoConsole;
    }

    /**
     * Read up to $len bytes of child output.
     *
     * null means "no data arrived before $timeout expired"; '' means the
     * output pipe is gone (the pseudo console closed it, which is what a
     * dead child's final flush leaves behind) — exactly the POSIX master's
     * meanings, so one loop shape works against either backend. The default
     * timeout is a finite 0.05s so the bare shape cannot hang; null = the
     * backend's own mode-governed wait.
     */
    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
    {
        $this->assertOpen();

        if ($len <= 0) {
            throw new \InvalidArgumentException(\sprintf('read length must be > 0; got %d', $len));
        }

        if ($timeout !== null && $timeout < 0) {
            throw new \InvalidArgumentException(\sprintf('timeout must be >= 0; got %s', (string) $timeout));
        }

        $deadline = $timeout === null ? null : \microtime(true) + $timeout;

        while (true) {
            $peek = $this->api->peekNamedPipe($this->outputPipe['read']);

            if ($peek === null) {
                return '';
            }

            if ($peek['available'] > 0) {
                $bytes = $this->api->readFile($this->outputPipe['read'], $len);

                // false is a transient read failure (ERROR_BAD_PIPE on a
                // concurrent write), not EOF: report "nothing yet" and let
                // the caller's timeout decide the rest.
                return $bytes === false ? null : $bytes;
            }

            if ($deadline !== null && \microtime(true) >= $deadline) {
                return null;
            }

            \usleep(self::POLL_INTERVAL_US);
        }
    }

    /**
     * Write to the child's input side of the pseudo console.
     */
    public function write(string $bytes): int
    {
        $this->assertOpen();

        $written = $this->api->writeFile($this->inputPipe['write'], $bytes);

        if ($written === false) {
            throw new PtyException('ConPTY master write failed: the input pipe is no longer writable');
        }

        return $written;
    }

    /**
     * Forward the resize into the pseudo console (its ResizePseudoConsole
     * leg), then keep the cache {@see size()} serves from.
     */
    public function resize(int $cols, int $rows): void
    {
        $this->assertOpen();

        $this->api->resizePseudoConsole($this->pseudoConsole, $cols, $rows);
        $this->cols = $cols;
        $this->rows = $rows;
    }

    /**
     * The last geometry set.
     *
     * ConPTY offers no size-query surface (GetPseudoConsoleSize is not
     * bound), so the cache the constructor seeded and resize() refreshes
     * IS the answer — including xpix/ypix, which pseudo consoles do not
     * track at all and report as 0.
     *
     * @return array shape with keys cols, rows, xpix, ypix — dimensions are
     *               fixed at construction from CreatePseudoConsole (no
     *               console re-query).
     */
    public function size(): array
    {
        return ['cols' => $this->cols, 'rows' => $this->rows, 'xpix' => 0, 'ypix' => 0];
    }

    /**
     * There is no PHP stream behind a ConPTY master — the pipes are Win32
     * handles the FFI binding holds, not stream resources — so this throws
     * rather than pretending. Consequence callers must know: PosixPump,
     * ReactPump and MultiPump stay POSIX-only; pump a ConPTY master with
     * the bounded-timeout {@see read()} loop instead.
     */
    public function stream(): mixed
    {
        throw new PtyException(
            'a ConPTY master has no PHP stream resource; pump it with read($len, $timeout) polling',
        );
    }

    /**
     * Same reason as {@see stream()}: a Win32 handle is not a file
     * descriptor, and handing back a number that cannot cross the boundary
     * would be a lie.
     */
    public function fd(): int
    {
        throw new PtyException('a ConPTY master has no POSIX file descriptor (Win32 pipe handles are not fds)');
    }

    /**
     * Idempotent, total release: pseudo console first (its close is what
     * breaks the child's console connection), then all four pipe handles.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->api->closePseudoConsole($this->pseudoConsole);
        $this->api->closeHandle($this->inputPipe['read']);
        $this->api->closeHandle($this->inputPipe['write']);
        $this->api->closeHandle($this->outputPipe['read']);
        $this->api->closeHandle($this->outputPipe['write']);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PtyException('the ConPTY master is closed');
        }
    }
}
