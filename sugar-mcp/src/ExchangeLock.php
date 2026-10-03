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
 *
 * A KILLED OWNER LEAVES ITS FILE BEHIND (audit R14, residual of AG-1/B1):
 * {@see destroy()} runs only when the server is stopped, so a TUI that is
 * SIGKILLed, OOM-killed or loses its terminal leaves one file per server in
 * the temp dir, forever. The file name therefore records who owns it —
 * `sugar-mcp-lock-<pid-namespace>-<owner-pid>-<random>` — and every
 * {@see new()} first sweeps the files whose owner is gone
 * ({@see sweepStale()}).
 *
 * A STATE WRITE THAT FAILS (a full or read-only temp filesystem) is reported,
 * never assumed: {@see store()} and {@see markPhase()} return false, and a
 * store that fails leaves the file EMPTY, which {@see load()} reads as dirty —
 * so the next holder resynchronises rather than trusting a half-written
 * buffer under a clean phase byte.
 */
final class ExchangeLock
{
    public const PHASE_CLEAN = 'C';
    public const PHASE_WRITING = 'W';
    public const PHASE_READING = 'R';

    /** Poll slice while another process holds the lock. */
    private const POLL_MICROSECONDS = 2000;

    /** Every lock file's name starts with this; {@see sweepStale()} reads no other. */
    public const FILE_PREFIX = 'sugar-mcp-lock-';

    /** The errno kill(pid, 0) reports for a pid no process has. */
    private const ERRNO_ESRCH = 3;

    /** @var resource|null this process' own handle, never an inherited one */
    private $handle = null;

    /** The pid {@see $handle} was opened in. */
    private int $handlePid = 0;

    private function __construct(
        public readonly string $path,
        private readonly int $ownerPid,
    ) {}

    /**
     * Create the lock file for a connection the CURRENT process owns, after
     * sweeping the files dead owners left in the same directory.
     *
     * @param string|null $dir where the file goes (null → the system temp dir;
     *        tests pass a private one)
     *
     * @throws \RuntimeException when no temp file can be created — a shared
     *         connection without exclusion is the defect this class closes, so
     *         it is refused rather than silently run unlocked
     */
    public static function new(string $label, ?string $dir = null): self
    {
        $dir ??= sys_get_temp_dir();
        $pid = (int) getmypid();

        // Boot sweep: the files of owners that died without destroy().
        self::sweepStale($dir);

        // tempnam() cannot carry the owner in the name, so the file is made
        // the way tempnam() makes one — exclusive create ('x'), mode 0600,
        // retried on the (vanishingly rare) random-suffix collision.
        $path = null;
        for ($attempt = 0; $attempt < 8 && $path === null; $attempt++) {
            $candidate = rtrim($dir, '/') . '/' . self::FILE_PREFIX . self::pidNamespace() . '-' . $pid . '-' . bin2hex(random_bytes(6));
            $handle = @fopen($candidate, 'x');
            if ($handle === false) {
                continue;
            }

            @chmod($candidate, 0600);
            $written = @fwrite($handle, self::PHASE_CLEAN) === 1;
            fclose($handle);
            if (!$written) {
                @unlink($candidate);
                break;
            }

            $path = $candidate;
        }

        if ($path === null) {
            throw new \RuntimeException(
                "MCP server {$label}: cannot create the exchange lock file in " . $dir
            );
        }

        return new self($path, $pid);
    }

    /**
     * Former name of {@see new()}, kept so embedders that still call it (the
     * sugar-crush ClaudeCodeMcpClient) keep working until they move over; the
     * repo's factory rule names the default root `::new()`.
     *
     * @deprecated use {@see new()}
     */
    public static function create(string $label, ?string $dir = null): self
    {
        return self::new($label, $dir);
    }

    /**
     * Remove the lock files in $dir whose owner process no longer exists.
     *
     * A file is removed only when ALL of these hold, so a lock somebody can
     * still use is never taken away:
     *  - its name is this class' current shape. Pre-R14 names (`tempnam()`'s
     *    `sugar-mcp-lock-XXXXXX`) say nothing about their owner and are left;
     *  - it was made in THIS pid namespace. A pid read from another namespace
     *    (a container sharing /tmp) names a different process, or none — a
     *    live owner there would look dead from here;
     *  - its owner pid is gone: kill(pid, 0) fails with ESRCH. EPERM means a
     *    live process of another user. A reused pid keeps the file — a leak,
     *    never a wrong removal — and so does a host without the posix
     *    extension, which cannot tell;
     *  - nobody holds its flock right now. A forked child of the dead owner
     *    may still be mid-exchange on the server the owner started; it is
     *    left alone, and the file goes on a later sweep. A child that already
     *    opened the file keeps its handle after the unlink (fds outlive
     *    names); one that opens it only afterwards fails its exchange, the
     *    same answer a stopped server gives.
     *
     * Best-effort by design: a file another sweeper removed first, or one
     * that cannot be opened, is skipped and never throws — the sweep must
     * never be the reason a server fails to start.
     *
     * @return int how many files were removed
     */
    public static function sweepStale(?string $dir = null): int
    {
        $dir ??= sys_get_temp_dir();
        $paths = @glob(rtrim($dir, '/') . '/' . self::FILE_PREFIX . '*', GLOB_NOSORT);
        if ($paths === false || $paths === []) {
            return 0;
        }

        $namespace = self::pidNamespace();
        $self = (int) getmypid();
        $removed = 0;

        foreach ($paths as $path) {
            if (preg_match('/^' . preg_quote(self::FILE_PREFIX, '/') . '(\d+)-(\d+)-[0-9a-f]+$/', basename($path), $m) !== 1) {
                continue;
            }

            $owner = (int) $m[2];
            if ($m[1] !== $namespace || $owner <= 0 || $owner === $self || !self::processIsGone($owner)) {
                continue;
            }

            $handle = @fopen($path, 'r+');
            if ($handle === false) {
                continue;
            }

            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                // Unlinked while held, so no child can be half-way into an
                // exchange on it at the moment the name goes.
                if (@unlink($path)) {
                    $removed++;
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }

        return $removed;
    }

    /**
     * Whether no process with this pid exists — the one answer that makes a
     * lock file safe to remove. Anything uncertain answers false.
     */
    private static function processIsGone(int $pid): bool
    {
        if (function_exists('posix_kill') && function_exists('posix_get_last_error')) {
            if (@posix_kill($pid, 0)) {
                return false;
            }

            // ESRCH alone means "no such process"; EPERM is a live process
            // of another user, and any other errno is not an answer.
            return posix_get_last_error() === self::ERRNO_ESRCH;
        }

        // Without posix, /proc can still say "absent" on Linux; elsewhere
        // nothing can, and the file is kept.
        return is_dir('/proc/self') && !file_exists("/proc/{$pid}");
    }

    /**
     * The inode of this process' pid namespace (Linux), or '0' where there is
     * no such notion — pids are then compared host-wide, as they are.
     */
    private static function pidNamespace(): string
    {
        $link = @readlink('/proc/self/ns/pid');

        return is_string($link) && preg_match('/\[(\d+)\]/', $link, $m) === 1 ? $m[1] : '0';
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
     * The phase and buffer the previous holder left.
     *
     * An unreadable or EMPTY file reads as {@see PHASE_READING}, not clean:
     * {@see new()} always writes a phase byte, so an empty file is a store
     * that failed (or a file recreated under the owner), and its stream state
     * is unknown. Dirty costs the next reader one skipped fragment; clean
     * could hand it a stream that starts mid-line. A handle that cannot be
     * opened at all reads as clean only because no exchange can run on it —
     * {@see acquire()} fails first.
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
            return [self::PHASE_READING, ''];
        }

        return [$raw[0], (string) substr($raw, 1)];
    }

    /**
     * Rewrite the whole state: phase byte plus buffer.
     *
     * @return bool false when the state did not land whole. The file is then
     *         truncated to empty (best-effort), which {@see load()} reads as
     *         dirty — a partial write would otherwise leave a clean phase byte
     *         in front of a truncated buffer.
     */
    public function store(string $phase, string $buffer): bool
    {
        $handle = $this->handle();
        if ($handle === null) {
            return false;
        }

        $stored = @ftruncate($handle, 0)
            && rewind($handle)
            && self::writeAll($handle, $phase . $buffer)
            && fflush($handle);

        if (!$stored) {
            @ftruncate($handle, 0);
        }

        return $stored;
    }

    /**
     * Overwrite the phase byte alone, leaving the stored buffer as it is.
     *
     * @return bool false when the byte was not written — the marker on disk is
     *         then whatever the previous write left, and callers must not act
     *         as if this phase were recorded
     */
    public function markPhase(string $phase): bool
    {
        $handle = $this->handle();
        if ($handle === null) {
            return false;
        }

        return rewind($handle)
            && self::writeAll($handle, $phase)
            && fflush($handle);
    }

    /**
     * fwrite() until $bytes are all out; false on an error or a zero-length
     * write (a full filesystem reports either, depending on the stream).
     *
     * @param resource $handle
     */
    private static function writeAll($handle, string $bytes): bool
    {
        while ($bytes !== '') {
            $written = @fwrite($handle, $bytes);
            if ($written === false || $written === 0) {
                return false;
            }

            $bytes = (string) substr($bytes, $written);
        }

        return true;
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
