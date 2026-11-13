<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * Cross-process mutual exclusion for one stdio connection, plus the state that
 * must outlive whichever process held it last.
 *
 * WHY (the fork-corruption audit): after pcntl_fork() every child holds a
 * copy of the same
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
 * A KILLED OWNER LEAVES ITS FILE BEHIND (the boot-sweep law):
 * {@see destroy()} runs only when the server is stopped, so a TUI that is
 * SIGKILLed, OOM-killed or loses its terminal leaves one file per server in
 * the temp dir, forever. The file name therefore records who owns it —
 * `sugar-mcp-client-lock-<pid-namespace>-<owner-pid>-<random>` — and every
 * {@see new()} first sweeps the files whose owner is gone
 * ({@see sweepStale()}).
 *
 * A STATE WRITE THAT FAILS (a full or read-only temp filesystem) is reported,
 * never assumed: {@see store()} and {@see markPhase()} return false, and a
 * store that fails leaves the file EMPTY, which {@see load()} reads as dirty —
 * so the next holder resynchronises rather than trusting a half-written
 * buffer under a clean phase byte.
 *
 * StoreResult names the lock's reporting door: false means the write did not
 * happen (handle lost, disk refused) — the lock never throws from these
 * methods, it reports; consumers branch, they do not catch.
 *
 * @phpstan-type StoreResult bool
 *
 */
final class ExchangeLock
{
    public const PHASE_CLEAN = 'C';
    public const PHASE_WRITING = 'W';
    public const PHASE_READING = 'R';

    /** Poll slice while another process holds the lock. */
    private const POLL_MICROSECONDS = 2000;

    /** Every lock file's name starts with this; {@see sweepStale()} reads no other. */
    public const FILE_PREFIX = 'sugar-mcp-client-lock-';

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
        // the way tempnam() makes one — exclusive create ('x') retried on the
        // (vanishingly rare) random-suffix collision — with one addition: the
        // mode is SET BEFORE THE PAYLOAD AND VERIFIED, not assumed.
        $path = null;
        $modeRefused = null;
        for ($attempt = 0; $attempt < 8 && $path === null; $attempt++) {
            $candidate = rtrim($dir, '/') . '/' . self::FILE_PREFIX . self::pidNamespace() . '-' . $pid . '-' . bin2hex(random_bytes(6));
            $handle = @fopen($candidate, 'xe');
            if ($handle === false) {
                continue;
            }

            // The file carries the exchange READ BUFFER — server stdout bytes,
            // i.e. tool results. A discarded @chmod that silently failed
            // (exotic filesystem, mount options) would leave a world-readable
            // transcript at the ambient umask, plus a create-to-chmod window.
            // House law (candy-core AtomicJsonFile): chmod before the payload
            // publishes, verify it took, and refuse LOUD rather than run
            // unlocked-or-readable.
            if (@chmod($candidate, 0600) === false || self::modeOf($candidate) !== 0600) {
                fclose($handle);
                @unlink($candidate);
                $modeRefused = $candidate;

                break;
            }

            $written = @fwrite($handle, self::PHASE_CLEAN) === 1;
            fclose($handle);
            if (!$written) {
                @unlink($candidate);
                break;
            }

            $path = $candidate;
        }

        if ($modeRefused !== null) {
            throw new \RuntimeException(
                "MCP server {$label}: the exchange lock file at {$modeRefused} could not be set to mode 0600; it would hold server output readable by every user on the host"
            );
        }

        if ($path === null) {
            throw new \RuntimeException(
                "MCP server {$label}: cannot create the exchange lock file in " . $dir
            );
        }

        return new self($path, $pid);
    }

    /**
     * Former name of {@see new()}, kept as a public-API compatibility shim
     * until any embedder still calling it moves over; the repo's factory rule
     * names the default root `::new()`. (The originally-named downstream
     * caller has ALREADY migrated — the sole caller inside this repo is the
     * alias-resolution test, and this note carries no survivor's name so it
     * cannot go stale again.)
     *
     * @deprecated use {@see new()}
     */
    public static function create(string $label, ?string $dir = null): self
    {
        return self::new($label, $dir);
    }

    /** The permission bits on a path, or null when stat cannot read it. */
    private static function modeOf(string $path): ?int
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === null ? null : ($stat['mode'] & 0777);
    }

    /**
     * Remove the lock files in $dir whose owner process no longer exists.
     *
     * A file is removed only when ALL of these hold, so a lock somebody can
     * still use is never taken away:
     *  - its name is this class' CURRENT shape. Names from before the
     *    owner-in-name law (`tempnam()`'s `sugar-mcp-client-lock-XXXXXX`
     *    residue) say nothing about their owner and are left — including
     *    world-readable transcript residue on a shared /tmp, which is the
     *    accepted cost of never deleting a file this process cannot prove
     *    dead (an operator may remove them by hand);
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

            $handle = @fopen($path, 'r+e');
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
     * lock file safe to remove. Anything uncertain answers false. The
     * signal-0 polarity is the shared one ({@see ProcessLiveness}), so an
     * adjustment to the kernel contract reaches this seat, the id-sequence
     * claim probe, and the transport's forked observer together.
     */
    private static function processIsGone(int $pid): bool
    {
        return !ProcessLiveness::ownerAlive($pid);
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
     * Rewrite the whole state: phase byte plus buffer. This is a FULL
     * truncate-and-write of everything the exchange carries, on every
     * exchange, under the lock: the cost tracks the read buffer's size
     * (bounded by the transport frame cap), a deliberate price for state an
     * external kill can only ever leave whole-or-empty. An append-delta
     * design would need a cross-process offset ledger to stay recoverable —
     * it re-earns nothing the phase byte could not already say.
     *
     * @return StoreResult false when the state did not land whole. The file is then
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
     * @return StoreResult false when the byte was not written — the marker on disk is
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

    /**
     * This process' own descriptor on the lock file, opened on demand.
     *
     * Two laws live here:
     *  - O_CLOEXEC ('e' on every mode): the exchange buffer is this client's
     *    private state; without the flag every subsequently spawned child of
     *    the owner inherits the descriptor (and with it, a handle on another
     *    session's stream state).
     *  - the reopen mode law: 'c+' CREATES under the ambient umask when the
     *    file is gone, so an owner reopening a deleted lock settles the file
     *    at 0600 before trusting it — the same refusal {@see new()} applies at
     *    first creation, because the file will carry server output. A file
     *    that cannot settle (planted foreign ownership) fails LOUD.
     *
     * @return resource|null
     */
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
        $owner = $pid === $this->ownerPid;
        $handle = @fopen($this->path, $owner ? 'c+e' : 'r+e');
        if ($handle === false) {
            return null;
        }

        if ($owner && self::modeOf($this->path) !== 0600) {
            // The create-to-chmod window is real (the file briefly exists at
            // the umask default) but BOUNDED and ALWAYS corrected: chmod now,
            // re-verify, and refuse to run on a file that stays readable to
            // the host. A foreign file planted at this path cannot be
            // chmod'ed by us — that is precisely the case this loud failure
            // is for; its owner keeps ownership, this session gets an error.
            if (@chmod($this->path, 0600) === false || self::modeOf($this->path) !== 0600) {
                fclose($handle);

                throw new \RuntimeException(
                    "the exchange lock file at {$this->path} could not be set to mode 0600; it would hold server output readable by every user on the host"
                );
            }
        }

        $this->handle = $handle;
        $this->handlePid = $pid;

        return $handle;
    }
}
