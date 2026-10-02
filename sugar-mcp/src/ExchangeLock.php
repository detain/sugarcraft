<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * Cross-process mutual exclusion for one stdio connection, plus the state that
 * must outlive whichever process held it last.
 *
 * WHY (audit AG-1): after pcntl_fork() every child holds a copy of the same
 * stdin/stdout pipes. Two children exchanging at once interleave their request
 * lines and race to read one stdout — each takes whatever line arrives first.
 * Unique ids alone turn that silent swap into lost replies (the reader that
 * wins discards a line it does not own); serialising each WHOLE exchange
 * (write the request, read through its response) is what makes sharing work.
 *
 * WHY A FILE, AND WHY EACH PROCESS OPENS ITS OWN HANDLE: flock() locks belong
 * to the open file DESCRIPTION, and a handle inherited across fork shares the
 * parent's description — a child calling flock() on it would "acquire" the very
 * lock its parent holds, excluding nothing. So the PATH is created once, at
 * connection start, and every process opens it afresh on first use (and again
 * if it finds itself in a new pid). Only the owner may create or unlink the
 * file; a non-owner that finds it gone fails the exchange instead of
 * resurrecting an orphan in the temp dir for a server that has been stopped.
 *
 * THE FILE CARRIES STATE, read and written only under the lock:
 *  - byte 0, the PHASE: C (clean, between exchanges), W (a request is being
 *    written), R (its response is being read). A holder that dies — SIGKILL on
 *    Esc, a watchdog, a deadline kill — releases the flock with its fds, but
 *    leaves W or R behind, and that is how the next holder learns the stream
 *    may hold half a line.
 *  - bytes 1.., the READ BUFFER: stdout bytes the last holder read past the end
 *    of its response. A process-private buffer would strand them — the next
 *    exchange may run in another process, which would then start reading in
 *    the middle of a line.
 */
final class ExchangeLock
{
    public const PHASE_CLEAN = 'C';
    public const PHASE_WRITING = 'W';
    public const PHASE_READING = 'R';

    /** Poll slice while another process holds the lock. */
    private const POLL_MICROSECONDS = 2000;

    /** @var resource|null this process' own handle, never an inherited one */
    private $handle = null;

    /** The pid {@see $handle} was opened in. */
    private int $handlePid = 0;

    private function __construct(
        public readonly string $path,
        private readonly int $ownerPid,
    ) {}

    /**
     * Create the lock file for a connection the CURRENT process owns.
     *
     * @throws \RuntimeException when no temp file can be created — a shared
     *         connection without exclusion is the defect this class closes, so
     *         it is refused rather than silently run unlocked
     */
    public static function create(string $label): self
    {
        $path = @tempnam(sys_get_temp_dir(), 'sugar-mcp-lock-');
        if ($path === false || @file_put_contents($path, self::PHASE_CLEAN) === false) {
            throw new \RuntimeException(
                "MCP server {$label}: cannot create the exchange lock file in " . sys_get_temp_dir()
            );
        }

        return new self($path, (int) getmypid());
    }

    /**
     * Wait for the exclusive lock. Polls LOCK_NB rather than blocking so the
     * wait honours $deadline (hrtime seconds, null = no deadline, matching the
     * no-deadline tool-call policy) and gives up the moment the server is gone.
     *
     * @param \Closure(): bool $serverAlive
     */
    public function acquire(?float $deadline, \Closure $serverAlive): bool
    {
        $handle = $this->handle();
        if ($handle === null) {
            return false;
        }

        while (true) {
            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return true;
            }

            if ($wouldBlock !== 1) {
                return false;
            }

            if ($deadline !== null && hrtime(true) / 1_000_000_000.0 >= $deadline) {
                return false;
            }

            if (!$serverAlive()) {
                return false;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The phase and buffer the previous holder left. A missing or empty file
     * reads as clean.
     *
     * @return array{0: string, 1: string} [phase, buffer]
     */
    public function load(): array
    {
        $handle = $this->handle();
        if ($handle === null) {
            return [self::PHASE_CLEAN, ''];
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || $raw === '') {
            return [self::PHASE_CLEAN, ''];
        }

        return [$raw[0], (string) substr($raw, 1)];
    }

    /** Rewrite the whole state: phase byte plus buffer. */
    public function store(string $phase, string $buffer): void
    {
        $handle = $this->handle();
        if ($handle === null) {
            return;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $phase . $buffer);
        fflush($handle);
    }

    /** Overwrite the phase byte alone, leaving the stored buffer as it is. */
    public function markPhase(string $phase): void
    {
        $handle = $this->handle();
        if ($handle === null) {
            return;
        }

        rewind($handle);
        fwrite($handle, $phase);
        fflush($handle);
    }

    public function release(): void
    {
        if ($this->handle !== null && $this->handlePid === (int) getmypid()) {
            flock($this->handle, LOCK_UN);
        }
    }

    /** Close THIS process' handle; the file and every other process' handle stay. */
    public function close(): void
    {
        if (is_resource($this->handle) && $this->handlePid === (int) getmypid()) {
            fclose($this->handle);
        }

        // An inherited handle is dropped, never unlocked: LOCK_UN on a shared
        // description would release a lock the parent may be holding.
        $this->handle = null;
        $this->handlePid = 0;
    }

    /** Close, and — in the owner only — remove the file. */
    public function destroy(): void
    {
        $this->close();

        if ((int) getmypid() === $this->ownerPid) {
            @unlink($this->path);
        }
    }

    /** @return resource|null */
    private function handle()
    {
        $pid = (int) getmypid();

        if ($this->handle !== null && $this->handlePid === $pid && is_resource($this->handle)) {
            return $this->handle;
        }

        // A handle from another pid is the parent's description (see the class
        // doc-block): forget it without touching it.
        $this->handle = null;

        // Only the owner may (re)create the file; a non-owner meeting a missing
        // file means the owner already stopped the server.
        $handle = @fopen($this->path, $pid === $this->ownerPid ? 'c+' : 'r+');
        if ($handle === false) {
            return null;
        }

        $this->handle = $handle;
        $this->handlePid = $pid;

        return $handle;
    }
}
