<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Cross-process mutual exclusion for one {@see LspConnection}, plus the state
 * that must outlive whichever process held it last (audit B7).
 *
 * WHY: sugar-crush runs every turn in a pcntl_fork()ed child and sub-agents in
 * further forks, and every fork inherits the language server's stdio pipes.
 * Unique request ids ({@see \SugarCraft\Mcp\RequestIdSequence}) keep two
 * processes from answering to the same id, but two processes exchanging at
 * once still interleave their frames on stdin and race on stdout — and the
 * loser of a read consumes and throws away a frame meant for its sibling.
 * Serialising each WHOLE exchange (write the request, read through its
 * response) is what makes one shared server safe.
 *
 * This is the LSP twin of {@see \SugarCraft\Mcp\ExchangeLock} (audit B1/AG-1)
 * rather than a reuse of it, because `Content-Length` framing needs state that
 * newline framing does not: NDJSON recovers from a half-written line with a
 * leading "\n", but a half-written LSP frame has promised the server bytes that
 * never came, and the only repair is to send exactly the bytes still owed —
 * so the frame being written, and how much of it went out, must be recorded
 * where the next holder can find them. It also keeps the shared journal of
 * server notifications ({@see appendNote()}).
 *
 * DISPOSITION (campaign re-verify lane A2, 2026-10-08): the fold audit asked
 * once more whether this belongs inside the library lock, and the answer is
 * recorded as a deliberate SKIP — a merge would force the library's
 * single-file phase-byte lock to grow a general file-set abstraction (lock +
 * state + frame + journal with per-file replacement laws) to carry a shape
 * only LSP framing needs, for zero user benefit. The shared laws above are
 * restated here rather than factored into a base so each class can be read
 * whole; changing one law means changing both, which is the accepted cost of
 * the divergence.
 *
 * THE SAME LAWS AS THE MCP LOCK:
 *  - flock() belongs to the open file DESCRIPTION, and a handle inherited
 *    across fork shares the parent's — so each process opens the lock path
 *    afresh ({@see handle()}), never using an inherited handle.
 *  - only the owner (the process that created the lock, i.e. ran
 *    {@see LspConnection::connect()}) may create or remove the files; a
 *    non-owner finding the lock file gone fails rather than resurrecting it.
 *  - the wait polls LOCK_NB so it honours the caller's deadline and gives up
 *    as soon as the server is gone.
 *
 * FILES. `<path>` carries only the flock. `<path>.state` holds the encoded
 * {@see LspExchangeState}; `<path>.frame` the frame being written;
 * `<path>.notes` the notification journal. The three data files are replaced
 * by write-to-temp + rename(), so a holder SIGKILLed mid-store leaves the old
 * contents or the new ones, never a torn mix — a guarantee an in-place
 * rewrite of the locked file itself could not give (and the lock file cannot
 * be renamed over, or the flock would move to a different inode).
 *
 * A KILLED OWNER LEAVES ITS FILES BEHIND (audit B8, the LSP twin of R14):
 * {@see destroy()} runs only when the server is stopped, so a TUI that is
 * SIGKILLed, OOM-killed or loses its terminal leaves the lock and its three
 * sidecars per server in the temp dir, forever. The lock's name therefore
 * records who owns it — `sugar-crush-lsp-lock-<pid-namespace>-<owner-pid>-<random>`
 * — and every {@see new()} first sweeps the sets whose owner is gone
 * ({@see sweepStale()}).
 *
 * A STATE WRITE THAT FAILS (a full or read-only temp filesystem) is reported,
 * never assumed: {@see store()} and {@see storeFrame()} return false. The
 * rename keeps the old contents whole, so the failure cannot tear a file — but
 * the old contents are then STALE, and a caller that acted as if its phase or
 * frame record had landed would leave the next holder trusting a stream this
 * one may die half-way into. A state file that is missing outright loads as
 * dirty for the same reason (see {@see load()}). A journal entry that does
 * not land is reported the same way: {@see appendNote()} returns null.
 */
final class LspExchangeLock
{
    /** Poll slice while another process holds the lock. */
    private const POLL_MICROSECONDS = 2000;

    /**
     * Notification journal bounds. Diagnostics are last-writer-wins per URI, so
     * a process that falls further behind than this loses only stale
     * intermediate states; the bound keeps a chatty server from making every
     * journal rewrite expensive.
     */
    private const NOTE_LIMIT = 64;

    private const NOTE_BYTES = 262144;

    /** Every lock file's name starts with this; {@see sweepStale()} reads no other. */
    public const FILE_PREFIX = 'sugar-crush-lsp-lock-';

    /** The errno kill(pid, 0) reports for a pid no process has. */
    private const ERRNO_ESRCH = 3;

    /** @var resource|null this process' own handle, never an inherited one */
    private $handle = null;

    /** The pid {@see $handle} was opened in. */
    private int $handlePid = 0;

    private function __construct(
        public readonly string $path,
        private readonly int $ownerPid,
    ) {
    }

    /**
     * Create the lock for a connection the CURRENT process owns, after
     * sweeping the sets dead owners left in the same directory.
     *
     * @param string|null $dir where the files go (null → the system temp dir;
     *        tests pass a private one)
     *
     * @throws \RuntimeException when no temp file can be created — a shared
     *         connection without exclusion is the defect this class closes, so
     *         it is refused rather than silently run unlocked
     */
    public static function new(string $label, ?string $dir = null): self
    {
        $dir = rtrim($dir ?? sys_get_temp_dir(), '/');
        $pid = (int) getmypid();

        // Boot sweep: the sets of owners that died without destroy().
        self::sweepStale($dir);

        // tempnam() cannot carry the owner in the name, so the file is made
        // the way tempnam() makes one — exclusive create ('x'), mode 0600,
        // retried on the (vanishingly rare) random-suffix collision.
        $path = null;
        for ($attempt = 0; $attempt < 8 && $path === null; $attempt++) {
            $candidate = $dir . '/' . self::FILE_PREFIX . self::pidNamespace() . '-' . $pid . '-' . bin2hex(random_bytes(6));
            $handle = @fopen($candidate, 'x');
            if ($handle === false) {
                continue;
            }

            fclose($handle);
            @chmod($candidate, 0600);
            $path = $candidate;
        }

        if ($path === null) {
            throw new \RuntimeException(
                "LSP server {$label}: cannot create the exchange lock file in " . $dir
            );
        }

        $lock = new self($path, $pid);
        if (!$lock->replace($lock->statePath(), LspExchangeState::new()->encode())) {
            @unlink($path);

            throw new \RuntimeException(
                "LSP server {$label}: cannot create the exchange state file in " . $dir
            );
        }

        return $lock;
    }

    /**
     * Former name of {@see new()}, kept so callers that still use it keep
     * working; the repo's factory rule names the default root `::new()`, as
     * the sugar-mcp twin {@see \SugarCraft\Mcp\ExchangeLock::new()} now does.
     *
     * @deprecated use {@see new()}
     */
    public static function create(string $label, ?string $dir = null): self
    {
        return self::new($label, $dir);
    }

    /**
     * Remove the lock sets in $dir whose owner process no longer exists.
     *
     * A lock — and with it its `.state`, `.frame`, `.notes` and any write temp
     * a killed holder stranded — is removed only when ALL of these hold, so a
     * set somebody can still use is never taken away:
     *  - its name is this class' current shape. Pre-B8 names (`tempnam()`'s
     *    `sugar-crush-lsp-lock-XXXXXX`) say nothing about their owner and are
     *    left;
     *  - it was made in THIS pid namespace. A pid read from another namespace
     *    (a container sharing /tmp) names a different process, or none — a
     *    live owner there would look dead from here;
     *  - its owner pid is gone: kill(pid, 0) fails with ESRCH. EPERM means a
     *    live process of another user. A reused pid keeps the set — a leak,
     *    never a wrong removal — and so does a host that cannot tell;
     *  - nobody holds its flock right now. A forked child of the dead owner
     *    may still be mid-exchange on the server the owner started; it is
     *    left alone, and the set goes on a later sweep. Everything is
     *    unlinked while the sweep holds the flock, sidecars first and the
     *    lock last, so a sweep killed half-way leaves a lock that the next
     *    one still finds. A process that was already waiting on the lock
     *    when the name went refuses its exchange ({@see acquire()} checks the
     *    name still leads to the inode it locked), the same answer a stopped
     *    server gives.
     *
     * A sidecar whose lock is already gone (a sweep that died between the two
     * unlinks) is removed on the same owner and namespace terms: with no lock
     * file, no process can open the set again.
     *
     * Best-effort by design: a file another sweeper removed first, or one
     * that cannot be opened, is skipped and never throws — the sweep must
     * never be the reason a language server fails to start.
     *
     * @return int how many files were removed
     */
    public static function sweepStale(?string $dir = null): int
    {
        $dir = rtrim($dir ?? sys_get_temp_dir(), '/');
        $paths = @glob($dir . '/' . self::FILE_PREFIX . '*', GLOB_NOSORT);
        if ($paths === false || $paths === []) {
            return 0;
        }

        // One listing serves both the locks and their sidecars: a second glob
        // per lock would read a pattern built from $dir, whose own `[` or `*`
        // would then be taken as wildcards.
        $locks = [];
        $sidecars = [];
        $pattern = '/^' . preg_quote(self::FILE_PREFIX, '/') . '(\d+)-(\d+)-[0-9a-f]+(\..+)?$/';
        foreach ($paths as $path) {
            if (preg_match($pattern, basename($path), $m) !== 1) {
                continue;
            }

            $suffix = $m[3] ?? '';
            if ($suffix === '') {
                $locks[$path] = [$m[1], (int) $m[2]];
            } else {
                $sidecars[substr($path, 0, -strlen($suffix))][] = $path;
            }
        }

        $namespace = self::pidNamespace();
        $self = (int) getmypid();
        $abandoned = static fn (string $ns, int $owner): bool => $ns === $namespace
            && $owner > 0
            && $owner !== $self
            && self::processIsGone($owner);
        $removed = 0;

        foreach ($locks as $path => [$ns, $owner]) {
            if (!$abandoned($ns, $owner)) {
                continue;
            }

            $handle = @fopen($path, 'r+');
            if ($handle === false) {
                continue;
            }

            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                foreach ([...($sidecars[$path] ?? []), $path] as $file) {
                    if (@unlink($file)) {
                        $removed++;
                    }
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            unset($sidecars[$path]);
        }

        foreach ($sidecars as $lockPath => $files) {
            if (isset($locks[$lockPath]) || file_exists($lockPath)) {
                continue;
            }

            preg_match($pattern, basename($lockPath), $m);
            if (!$abandoned($m[1], (int) $m[2])) {
                continue;
            }

            foreach ($files as $file) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * Whether no process with this pid exists — the one answer that makes a
     * lock set safe to remove. Anything uncertain answers false.
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
        // nothing can, and the set is kept.
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
     * wait honours $deadline (`microtime(true)` seconds, null = none) and gives
     * up the moment the server is gone.
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
                if ($this->stillNamed($handle)) {
                    return true;
                }

                // The name went while this process waited: a stale-lock sweep
                // (or the owner's destroy()) unlinked it — and the sidecars
                // the state lives in — under the flock this wait just won.
                // Exchanging now would start from no state on a stream the
                // last holder may have left mid-frame.
                $this->release();

                return false;
            }

            if ($wouldBlock !== 1) {
                return false;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return false;
            }

            if (!$serverAlive()) {
                return false;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Unlock AND close. The handle lives only for the length of one exchange:
     * a descriptor kept open between exchanges would be inherited by every
     * process this one later spawns (a Bash tool, a setsid'd grandchild), and
     * a flock is only released when the LAST descriptor on its description
     * closes — so a holder SIGKILLed mid-exchange would leave the lock held
     * for as long as any such grandchild lived.
     */
    public function release(): void
    {
        if ($this->handle !== null && $this->handlePid === (int) getmypid()) {
            flock($this->handle, LOCK_UN);
        }

        $this->close();
    }

    /**
     * The state the previous holder left. Also safe WITHOUT the lock (a
     * predicate reading only the {@see LspExchangeState::$broken} latch),
     * because stores are atomic renames: an unlocked reader sees one whole
     * state or another.
     *
     * A MISSING or unreadable state file reads as a holder that died
     * mid-read ({@see LspExchangeState::PHASE_READING}), not as a fresh
     * connection: {@see new()} always writes one before it returns, and stores
     * replace it by rename, so a missing file is one removed under the
     * connection (a temp-dir cleaner, a hand) and the stream position it
     * recorded is unknown. Dirty costs the next reader a resynchronisation;
     * fresh could hand it a stdout that starts mid-frame — the same choice
     * {@see LspExchangeState::decode()} makes for a file it cannot read.
     */
    public function load(): LspExchangeState
    {
        $raw = @file_get_contents($this->statePath());

        return $raw === false
            ? LspExchangeState::new()->withPhase(LspExchangeState::PHASE_READING)
            : LspExchangeState::decode($raw);
    }

    /**
     * Replace the shared state.
     *
     * @return bool false when it did not land — the file then still holds the
     *         previous state, whole but stale, and callers must not act as if
     *         this one were recorded
     */
    public function store(LspExchangeState $state): bool
    {
        return $this->replace($this->statePath(), $state->encode());
    }

    /**
     * Record the bytes of the frame about to be written (see {@see LspExchangeState}).
     *
     * @return bool false when the record did not land (see {@see store()})
     */
    public function storeFrame(string $frame): bool
    {
        return $this->replace($this->framePath(), $frame);
    }

    public function loadFrame(): string
    {
        return (string) @file_get_contents($this->framePath());
    }

    /**
     * Journal one server notification and return its sequence number.
     *
     * The journal's own counter is authoritative; the copy in
     * {@see LspExchangeState::$noteSeq} is only the hint that lets a process
     * skip reading this file when nothing is new.
     *
     * A JOURNAL WRITE THAT FAILS is reported like a failed {@see store()}:
     * this returns null, and the file still holds the previous journal. The
     * number it would have returned was handed out anyway before, and that
     * was worse than losing the one entry: published as the
     * {@see LspExchangeState::$noteSeq} hint it told every other process an
     * entry existed that the journal never got, they advanced past it — and
     * the NEXT append, counting from the file, minted that same number again
     * for a real entry, which they then skipped as already seen.
     *
     * @param array<mixed>|null $params
     *
     * @return int|null null when the entry did not land — callers must not
     *         publish or record a sequence number for it
     */
    public function appendNote(string $method, ?array $params): ?int
    {
        [$seq, $notes] = $this->journal();
        $seq++;
        $notes[] = ['seq' => $seq, 'method' => $method, 'params' => $params];

        $notes = array_slice($notes, -self::NOTE_LIMIT);
        $encoded = serialize(['seq' => $seq, 'notes' => $notes]);
        while (count($notes) > 1 && strlen($encoded) > self::NOTE_BYTES) {
            array_shift($notes);
            $encoded = serialize(['seq' => $seq, 'notes' => $notes]);
        }

        return $this->replace($this->notesPath(), $encoded) ? $seq : null;
    }

    /**
     * Journal entries newer than $seen, oldest first.
     *
     * @return list<array{seq: int, method: string, params: array<mixed>|null}>
     */
    public function notesAfter(int $seen): array
    {
        [, $notes] = $this->journal();

        return array_values(array_filter($notes, static fn (array $note): bool => $note['seq'] > $seen));
    }

    /** Close THIS process' handle; the files and every other process' handle stay. */
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

    /** Close, and — in the owner only — remove every file. */
    public function destroy(): void
    {
        $this->close();

        if ((int) getmypid() === $this->ownerPid) {
            // The glob also takes any `<file>.<pid>.<rand>` temp a holder was
            // killed between writing and renaming.
            foreach ([...(glob($this->path . '.*') ?: []), $this->path] as $file) {
                @unlink($file);
            }
        }
    }

    private function statePath(): string
    {
        return $this->path . '.state';
    }

    private function framePath(): string
    {
        return $this->path . '.frame';
    }

    private function notesPath(): string
    {
        return $this->path . '.notes';
    }

    /** @return array{0: int, 1: list<array{seq: int, method: string, params: array<mixed>|null}>} */
    private function journal(): array
    {
        $raw = @file_get_contents($this->notesPath());
        $data = $raw === false || $raw === '' ? false : @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data) || !is_int($data['seq'] ?? null) || !is_array($data['notes'] ?? null)) {
            return [0, []];
        }

        $notes = [];
        foreach ($data['notes'] as $note) {
            if (is_array($note) && is_int($note['seq'] ?? null) && is_string($note['method'] ?? null)) {
                $notes[] = [
                    'seq' => $note['seq'],
                    'method' => $note['method'],
                    'params' => is_array($note['params'] ?? null) ? $note['params'] : null,
                ];
            }
        }

        return [$data['seq'], $notes];
    }

    /**
     * Atomically replace $target. A non-owner whose owner has already removed
     * the lock file writes nothing: the connection is gone, and a file written
     * now would be an orphan in the temp dir.
     */
    private function replace(string $target, string $contents): bool
    {
        if ((int) getmypid() !== $this->ownerPid && !is_file($this->path)) {
            return false;
        }

        $temp = $target . '.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $contents) !== strlen($contents) || !@rename($temp, $target)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * Whether {@see $path} still leads to the inode $handle has open.
     *
     * @param resource $handle
     */
    private function stillNamed($handle): bool
    {
        clearstatcache(true, $this->path);
        $named = @stat($this->path);
        $held = @fstat($handle);

        return $named !== false && $held !== false
            && $named['ino'] === $held['ino'] && $named['dev'] === $held['dev'];
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
