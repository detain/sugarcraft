<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

use SugarCraft\Readline\Lang;

/**
 * File-persisted history store.
 *
 * Extends InMemoryHistory with disk persistence.  The history file is
 * loaded on construction (created if absent) and updated on every push().
 *
 * Concurrency: every write — both the in-place append fast-path and the
 * temp + rename rewrite — runs under an exclusive lock on a sidecar file
 * `<history>.lock`, so two processes appending and/or rewriting the same
 * history cannot lose each other's lines. (The rewrite previously raced:
 * it copied existing content and renamed over the target without holding
 * any lock on the shared path.) The sidecar is never written to; only its
 * inode is locked, which avoids the unlink/rename window a lock-file
 * rotation dance would introduce.
 *
 * Permissions: the history file and its sidecar are kept at 0600. An
 * existing file whose mode cannot be tightened (e.g. foreign-owned) is a
 * construction error — history can contain secrets.
 *
 * Deduplication is stricter than HistoryInterface's "differs from the last
 * entry" floor: a line already present anywhere in the retained history is
 * skipped (zsh `HIST_IGNORE_ALL_DUPS`-style). The weaker adjacent-only rule
 * that GNU (hist_ignore_dups) and zsh (`HIST_IGNORE_DUPS`) describe is what
 * InMemoryHistory enforces. The
 * cross-process tail guard for the append path runs INSIDE the write-lock
 * critical section, not off the pre-append peek (finding 013), so a racing
 * duplicate can no longer survive concurrent append — including the batch
 * flush() of deferred writes.
 *
 * On-disk format: an optional `#sugar-readline-history/v2` first line (the
 * version marker; see below) then one entry per line, with backslash escapes
 * (mirroring bash history-file conventions) applied at the write choke and
 * undone at load — `\\` for a backslash, `\n` / `\r` for embedded newlines
 * and carriage returns. Without escaping an entry containing a raw LF
 * reloads as TWO entries. Files written before escaping existed may
 * therefore decode a literal `\n` sequence into a newline on load — the
 * one decode trade-off the escape format introduces. The marker line itself
 * is skipped by every decoder; it is written by the bounded full rewrite
 * and NEVER by the append paths — a markerless file decodes identically
 * (the v2 escape rule is the only rule so far), so adoption is a
 * trajectory, not a migration: each store gains the marker the first time
 * a cap-merge rewrites it whole.
 *
 * Entry view: entries()/count() (inherited, finding 014) expose this
 * process's retained window — the file as loaded plus pushes since, capped
 * by maxHistory. Another process appending after our load is visible to the
 * dedupe/merge machinery under the write lock, not to the in-memory view
 * until the next load; that asymmetry is deliberate (loading per keystroke
 * would re-read the world for an autosuggest hint).
 *
 * Symlink policy: the history path must be a regular file. A symlink at it
 * is refused at construction, re-checked under the write lock, and — on
 * every locked open — proven inert afterwards: the handle is fstat'd for
 * regular-file type and inode-matched against the path's lstat, so a swap
 * planted between check and open cannot be written through. The lock
 * sidecar <path>.lock gets the same refusal before its first open and is
 * mode-set via fchmod on the handle, never via a followable path chmod.
 * The lock-free readers (load(), peekLastOnDisk()) refuse a visible
 * symlink but cannot close the check-to-open race — their exposure is a
 * read-through disclosure of one inode swap behind, which is the lesser
 * half of the old write-side hazard and is the deliberate asymmetry.
 *
 * Write failures (lock or temp file unavailable) throw RuntimeException;
 * the loud contract is kept by a persist-then-swap ordering: push(),
 * flush() and clear() commit the disk first and only then swap the
 * in-memory view, so a caller who catches the failure is left with memory
 * agreeing with the file (and a retryable queue), never a store that
 * contradicts its own disk (finding 063). The automatic flush() on
 * destruct downgrades to an error_log line — throwing from a destructor
 * is fatal territory.
 *
 * Durability (finding 064): every write that carries content fsyncs the
 * bytes before the lock is released (where the PHP build exposes fsync),
 * and every decode of file content — load(), the bounded merge, and the
 * duplicate guard's tail reads — treats an unterminated final line as a
 * crash fragment and drops it rather than accepting it as an entry. The
 * rename swap itself is not journaled from PHP: a crash landing exactly
 * across the switch can roll the file back to its previous version,
 * never surface a torn line as history.
 *
 * A failed LOCK_SH on the read paths (finding 068) means the filesystem
 * cannot do advisory locks at all - an active writer makes the blocking
 * call wait, not fail - so load() and the duplicate guard's peek degrade
 * to an unlocked read with a once-per-store error_log disclosure instead
 * of denying a view the rename-atomic content makes safe. Writers stay
 * fail-closed: they cannot serialize without the lock.
 */
final class FileHistory extends InMemoryHistory
{
    /**
     * First-line format marker written by the bounded full rewrite
     * (finding 069). Its presence declares "v2 escape rules apply and
     * future rules can be dispatched on it"; its absence is likewise
     * unambiguous - every file predates the marker, and the v2 rule is the
     * only rule so far.
     */
    private const FORMAT_HEADER = '#sugar-readline-history/v2';

    private string $filePath;
    private string $tempDir;
    private bool $deferWrites;

    /** @var list<string> Entries pushed but not yet persisted (deferred mode only). */
    private array $pendingWrites = [];

    /**
     * Resident membership set mirroring $this->history exactly (finding 062):
     * $entry => true for every retained line. load(), push() and clear() all
     * keep it in step through pushResident(), including maxHistory eviction —
     * the same law the loader's local set already followed, now resident so
     * the per-push membership test is O(1) instead of an in_array() scan.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    /** @var int Entries evicted from memory since the last bounded trim rewrite (finding 016). */
    private int $trimOverflow = 0;

    /** @var bool The read-lock degradation of finding 068 has been disclosed (once per store). */
    private bool $readLockDegraded = false;

    /**
     * @param string $filePath    Path to the history file.  Created automatically if absent.
     * @param int    $maxHistory  Maximum number of entries to retain. 0 = unlimited;
     *                            negative throws (fail-fast, finding 032).
     * @param string $tempDir     Directory for temporary files during atomic writes.
     * @param bool   $deferWrites When true, push() only queues the entry in memory;
     *                            disk I/O happens in one batched atomic write on
     *                            flush() (called automatically on destruct).
     *                            Trade-off: submit/keystroke latency stays free of
     *                            blocking file I/O, but queued entries are lost if
     *                            the process dies before flush (e.g. SIGKILL).
     *
     * WARNING (finding 020): the positional order is (path, maxHistory,
     * tempDir, deferWrites) - maxHistory comes BEFORE tempDir. Both
     * middle parameters are defaults of different types, so a swap
     * compiles and misbehaves silently; prefer named arguments.
     */
    public function __construct(
        string $filePath,
        int $maxHistory = 0,
        string $tempDir = '',
        bool $deferWrites = false,
    ) {
        parent::__construct($maxHistory);
        $this->filePath = $filePath;
        $this->tempDir = $tempDir !== '' ? $tempDir : \dirname($filePath);
        $this->deferWrites = $deferWrites;
        // A symlinked history path would make every touch/chmod/append below
        // operate on the TARGET — arbitrary file append + permission rewrite.
        // Refuse it before touching anything.
        $this->refuseSymlink();
        // Create the file if absent; in every case the mode must end at 0600 —
        // history can contain secrets, so a pre-existing file left loose by an
        // older tool or a permissive umask is tightened here, or the foreign
        // ownership is refused loudly (chmod() returns false without a
        // warning; touch() can warn, hence @).
        if (!file_exists($this->filePath)) {
            if (@touch($this->filePath) === false) {
                throw new \RuntimeException('sugar-readline: ' . Lang::t('history.create_failed', ['path' => $this->filePath]));
            }
        }
        if (!chmod($this->filePath, 0600)) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.chmod_failed', ['path' => $this->filePath]));
        }
        $this->load();
    }

    /**
     * Graceful-degradation entry point for host startup (finding 020).
     *
     * The constructor is loud BY DESIGN: a symlinked path, an unwritable
     * directory or a foreign-owned file are conditions the library must
     * refuse (see the class docblock). That is the right contract for a
     * caller that asked for file history and means it - and the wrong one
     * for an application that only wants persistence when it can have it.
     * tryOpen() turns one failed open into one logged line and a null
     * result, so the classic host idiom stays total:
     *
     *     $history = $preferFile
     *         ? (FileHistory::tryOpen($path) ?? new InMemoryHistory())
     *         : new InMemoryHistory();
     *
     * Parameter order mirrors the constructor - maxHistory BEFORE
     * tempDir; the same arg-swap warning applies (both are strings with
     * defaults, so a swap is silent).
     */
    public static function tryOpen(
        string $filePath,
        int $maxHistory = 0,
        string $tempDir = '',
        bool $deferWrites = false,
    ): ?self {
        try {
            return new self($filePath, $maxHistory, $tempDir, $deferWrites);
        } catch (\Throwable $e) {
            \error_log('sugar-readline: FileHistory::tryOpen declined ' . $filePath . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The path this history file was opened over. Lets a host report
     * WHERE persistence failed (after a null tryOpen) or where a loaded
     * session's history actually lives, without catching and parsing an
     * exception message out of a constructor call.
     */
    public function path(): string
    {
        return $this->filePath;
    }

    /**
     * Fail if the history path is (or has become) a symlink. Every open in
     * this class follows the path, so the check runs at construction and
     * again once the write lock is held.
     */
    private function refuseSymlink(): void
    {
        $this->refuseSymlinkAt($this->filePath, 'history file');
    }

    /**
     * Symlink refusal for any FileHistory-touched path — the history file
     * itself and the <path>.lock sidecar both get checked BEFORE the first
     * open, so neither fopen nor chmod ever acts through a planted link.
     */
    private function refuseSymlinkAt(string $path, string $what): void
    {
        \clearstatcache(true, $path);
        if (\is_link($path)) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.symlink_refused', ['what' => $what, 'path' => $path]));
        }
    }

    /**
     * Open the history file and prove the handle is the checked path.
     *
     * refuseSymlinkAt ran under the write lock, but check and open are not
     * one syscall: a directory-writer can swap the path to a symlink in the
     * window. After opening we verify (a) the fd resolves to a regular file
     * — a fifo or device behind a swap can never be written through — and
     * (b) the path's own lstat inode equals the fd's inode, which fails
     * exactly when the current path component is a symlink (lstat sees the
     * link, fstat sees the followed target). Legitimate rewrites create a
     * new inode via temp+rename, but those all serialize under the same
     * write lock we already hold, so the identity check cannot false-fire
     * here — do NOT use this helper on the lock-free read paths.
     *
     * @return resource
     */
    private function openVerified(string $mode)
    {
        $fp = \fopen($this->filePath, $mode);
        if ($fp === false) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.open_failed', ['path' => $this->filePath]));
        }
        $stat = \fstat($fp);
        // (mode & S_IFMT) === S_IFREG via octal literals: this PHP build
        // ships no S_IF* constants (checked at lane time).
        if ($stat === false || (((int) $stat['mode']) & 0170000) !== 0100000) {
            \fclose($fp);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.not_regular', ['path' => $this->filePath]));
        }
        \clearstatcache(true, $this->filePath);
        $link = \lstat($this->filePath);
        if ($link === false || (int) $link['ino'] !== (int) $stat['ino']) {
            \fclose($fp);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.changed_under_us', ['path' => $this->filePath]));
        }
        return $fp;
    }

    public function __destruct()
    {
        // Throwing from a destructor is fatal territory (it fires during
        // shutdown / GC), so the loud auto-flush downgrades to an error_log
        // line. Callers who want the exception should call flush() directly.
        try {
            $this->flush();
        } catch (\Throwable $e) {
            error_log('sugar-readline: history flush failed: ' . $e->getMessage());
        }
    }

    public function __clone()
    {
        // Navigation clones (TextPrompt clones its history per operation) must
        // not re-flush the original's queued writes when they are destroyed —
        // only the caller-owned instance persists its own queue.
        $this->pendingWrites = [];
    }

    /**
     * Append $line to the history and update the in-memory state.
     *
     * With maxHistory = 0 (unlimited) persistence is an O(appended) in-place
     * append under the sidecar write lock, falling back to a secure temp +
     * rename rewrite (see appendLinesAtomically()). With a cap set, the
     * DISK must stay bounded too — but not by rewriting the whole file on
     * every Enter (finding 016, measured 10.5ms/push at 3k): each push still
     * takes the O(appended) append path, and the merged, deduplicated,
     * cap-trimmed rewrite (mergeAndBoundOnDisk()) fires once the file has
     * grown a whole eviction window past the cap, so the file is bounded
     * within 2x maxHistory and the cost amortizes to one rewrite per
     * maxHistory pushes. flush() forces the bounded view immediately.
     *
     * Entries are escaped on write (see class docblock); the cross-process
     * duplicate guard compares the ESCAPED form against the raw file tail.
     *
     * Dedup skips any line already retained in memory — stricter than
     * HistoryInterface's "differs from the last entry" floor (see class
     * docblock).
     *
     * @throws \RuntimeException when the file cannot be persisted (write lock
     *                           or temp file unavailable) — failure is loud,
     *                           never a silent drop, and persist-then-swap
     *                           (finding 063) means a caught throw leaves
     *                           memory, file and trim checkpoint exactly as
     *                           they were: the push simply did not happen.
     *
     * In deferred mode the entry is only queued; see flush().
     */
    public function push(string $line): void
    {
        if ($line === '') {
            return;
        }
        // Skip any in-memory duplicate (handles the case where the same line
        // was added earlier in this session but isn't the "last" entry).
        if ($this->inMemoryContains($line)) {
            return;
        }

        if ($this->deferWrites) {
            // Memory is a superset of what we loaded from disk, so the check
            // above suffices — no per-push file I/O in deferred mode.
            $this->pendingWrites[] = $line;
            $this->pushResident($line);
            return;
        }

        // Sync paths screen first against another process having appended
        // the same line since we loaded (raw escaped tail vs escaped
        // candidate). This peek is a lock-free fast path only; the
        // authoritative re-check runs inside appendLinesAtomically's
        // write-lock section (finding 013).
        $screened = $this->peekLastOnDisk() !== self::escapeLine($line);

        if ($this->maxHistory > 0) {
            // Capped mode (finding 016): the O(1) append carries each entry;
            // the full read-merge-dedupe-rewrite runs only once the file has
            // grown a whole eviction window past the cap — one rewrite per
            // maxHistory pushes instead of one per Enter. Between trims the
            // file is a bounded-superset (at most 2x cap lines); flush()
            // forces the bounded view.
            //
            // Persist-then-swap (finding 063): disk work commits BEFORE the
            // memory mutation, so a caller who catches a write failure sees
            // memory agreeing with the file, never a store contradicting its
            // own disk. The merge arm therefore carries the pushed line as a
            // pending-newest view (memory is not yet swapped), and the trim
            // checkpoint counter only advances over the successful commit.
            $evicts = $this->wouldEvictOnPush();
            if ($evicts && $this->trimOverflow + 1 >= $this->maxHistory) {
                // The trim checkpoint absorbs this push's eviction in the
                // rewrite it already committed (merge resets the counter),
                // so only the append arm advances it past a successful
                // commit.
                $this->mergeAndBoundOnDisk($line);
            } else {
                if ($screened) {
                    $this->appendLinesAtomically([$line]);
                }
                if ($evicts) {
                    $this->trimOverflow++;
                }
            }
            $this->pushResident($line);
            return;
        }

        if ($screened) {
            $this->appendLinesAtomically([$line]);
        }

        $this->pushResident($line);
    }

    /**
     * Set the retained-entry cap and honor it on DISK immediately when the
     * new bound can shrink the persisted view (finding 065 — the inherited
     * memory-only trim let another process's next read resurrect entries
     * the live session had just discarded, because the file is the shared
     * truth and a cap must reach it at set time, not at next push time).
     *
     * The bounding rewrite is the same bounded merge flush() checkpoints
     * with; it commits BEFORE memory moves (persist-then-swap, finding
     * 063): when the rewrite throws, the cap, the resident list and the
     * cursor all stay exactly as they were. Growing the cap never rewrites
     * the file — an oversized cap has nothing to bound, and the ordinary
     * eviction rhythm picks up from there.
     *
     * @throws \InvalidArgumentException When the cap is negative.
     */
    public function setMaxHistory(int $limit): self
    {
        $cap = self::checkedCap($limit);
        $oldCap = $this->maxHistory;
        // When can the file hold more than the new cap?
        $bound = $cap > 0 && ($oldCap === 0 || $cap < $oldCap || \count($this->history) > $cap);
        if (!$bound) {
            $this->maxHistory = $cap;
            $this->trimToCap();
            return $this;
        }
        $this->maxHistory = $cap;
        try {
            // The merge reads this->maxHistory for its bound and covers the
            // pending queue through the resident view; a successful commit
            // resets the deferred-trim checkpoint.
            $this->mergeAndBoundOnDisk();
            $this->pendingWrites = [];
        } catch (\Throwable $e) {
            $this->maxHistory = $oldCap;
            throw $e;
        }
        $this->trimToCap();
        return $this;
    }

    /**
     * Would the next landing push evict the oldest entry? Evaluated BEFORE
     * the mutation (persist-then-swap, finding 063): a push lands whenever
     * push() reaches this point (the empty/dup guards above return early),
     * so eviction is exactly "window already full".
     */
    private function wouldEvictOnPush(): bool
    {
        return $this->maxHistory > 0 && \count($this->history) >= $this->maxHistory;
    }

    /**
     * Prepend $entry to the parent's memory and mirror the resident set,
     * dropping the line maxHistory eviction discarded (the loader's law,
     * finding 062): the set must equal $this->history at every step, so a
     * re-push of an evicted line is re-admitted exactly as an in_array()
     * over the live window would be.
     *
     * @return string|null the entry eviction removed from the window, if any
     */
    private function pushResident(string $entry): ?string
    {
        $sizeBefore = \count($this->history);
        $tailBefore = $sizeBefore > 0 ? $this->history[$sizeBefore - 1] : null;

        parent::push($entry);

        $this->seen[$entry] = true;

        if (\count($this->history) === $sizeBefore && $tailBefore !== null) {
            unset($this->seen[$tailBefore]);
            return $tailBefore;
        }

        return null;
    }

    /**
     * Persist any queued entries in a single atomic write.
     *
     * No-op when nothing is pending, so calling it eagerly (e.g. after each
     * submit, or from a shutdown handler) is cheap.
     */
    public function flush(): void
    {
        if ($this->pendingWrites === []) {
            // Capped sync may be sitting on a deferred trim (finding 016);
            // a flush is the documented persistence checkpoint, so deliver
            // the bounded view now.
            if ($this->maxHistory > 0 && $this->trimOverflow > 0) {
                $this->mergeAndBoundOnDisk();
            }
            return;
        }
        $pending = $this->pendingWrites;
        if ($this->maxHistory > 0) {
            // The queued entries already live in memory (push() updated it);
            // the bounded rewrite persists the whole retained view. The
            // queue drains only after the commit succeeds (finding 063) —
            // a caught failure leaves the batch retryable, not dissolved.
            $this->mergeAndBoundOnDisk();
            $this->pendingWrites = [];
            return;
        }
        $this->appendLinesAtomically($pending);
        $this->pendingWrites = [];
    }

    /**
     * Append $lines to the history file, keeping 0600 permissions.
     *
     * Fast path: when the file already ends with a newline (or is empty), the
     * entries are appended in place under LOCK_EX — O(appended) rather than the
     * O(file) read-and-rewrite the fallback pays on every push(). The trailing
     * byte is inspected under the SAME exclusive lock as the write, so a
     * concurrent appender cannot slip a non-terminated tail past the check.
     *
     * Fallback (also the create-from-absent path): a secure temp + rename
     * rewrite that copies existing content, DROPS an unterminated crash
     * fragment at the tail (finding 064 — the old code terminated it and
     * thereby promoted garbage into an entry), then renames over the target.
     * The temp file is created with
     * tempnam() (unpredictable random name) and chmod 0600 BEFORE any history
     * content is written — history can contain sensitive shell commands, so
     * this closes two holes a predictable name (.history.tmp.<pid>) left open:
     * (1) a world-readable window between create and the post-rename chmod, and
     * (2) a symlink pre-plant — an attacker with write access to $this->tempDir
     * (which defaults to the history file's own directory) could plant a symlink
     * at the predictable path so fopen(...,'w') would follow it and leak/corrupt
     * an arbitrary target. tempnam() creates a fresh regular file with a name
     * the attacker cannot guess and never opens through an existing symlink.
     *
     * Both paths produce byte-identical output for a given prior file state, so
     * the fast path is a pure performance optimization over the rewrite.
     *
     * @param list<string> $lines
     */
    private function appendLinesAtomically(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $this->withWriteLock(function () use ($lines): void {
            // Fast path: append in place when the on-disk tail is newline-terminated.
            // The file already exists at 0600 (constructor chmod + clear()/rewrite),
            // so an in-place append preserves those perms; re-assert defensively in
            // case it was re-created since construction.
            if (file_exists($this->filePath)) {
                $fp = $this->openVerified('a+');
                {
                    if (!flock($fp, LOCK_EX)) {
                        fclose($fp);
                        throw new \RuntimeException('sugar-readline: ' . Lang::t('history.append_lock_failed', ['path' => $this->filePath]));
                    }
                    $stat = fstat($fp);
                    $size = $stat !== false ? (int) ($stat['size'] ?? 0) : 0;
                    $endsWithNewline = true;
                    if ($size > 0) {
                        fseek($fp, -1, SEEK_END);
                        $endsWithNewline = fread($fp, 1) === "\n";
                    }
                    if ($endsWithNewline) {
                        // Finding 013: peek-and-append is ONE critical section.
                        // push()'s outer peek is lock-free and races any writer
                        // landing between it and this lock — re-verify the tail
                        // HERE, under the same LOCK_EX that guards the write,
                        // and drop a leading entry that became a duplicate in
                        // the window. Same blank-skipping tail semantics as the
                        // outer peek, so the two checks cannot disagree.
                        $tail = $size > 0 ? self::tailLineOfStream($fp) : null;
                        if ($tail !== null && $tail === self::escapeLine($lines[0])) {
                            array_shift($lines);
                        }
                        if ($lines !== []) {
                            fseek($fp, 0, SEEK_END);
                            foreach ($lines as $line) {
                                fwrite($fp, self::escapeLine($line) . "\n");
                            }
                            fflush($fp);
                            // Durability before unlock (finding 064): the
                            // submit pace makes the sync cheap, and without
                            // it a crash could leave the torn tail the
                            // readers now refuse anyway.
                            self::syncHandle($fp);
                        }
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        chmod($this->filePath, 0600);
                        return;
                    }
                    // Missing trailing newline — release and fall through to
                    // the rewrite below, which DROPS the torn tail fragment
                    // instead of terminating it (finding 064).
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            }

            $this->rewriteLocked($lines);
        });
    }

    /**
     * Temp + rename rewrite appending $lines to the existing content. The
     * caller holds the sidecar write lock, so the copy-read and the rename
     * cannot interleave with another writer.
     *
     * @param list<string> $lines
     */
    private function rewriteLocked(array $lines): void
    {
        $tempFile = @tempnam($this->tempDir, 'hist');
        if ($tempFile === false) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_create_failed', ['dir' => $this->tempDir]));
        }
        // Restrict perms before writing so history content is never exposed,
        // even briefly, on any platform/umask (tempnam is 0600 on POSIX only).
        chmod($tempFile, 0600);
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_open_failed', ['path' => $tempFile]));
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_lock_failed', ['path' => $tempFile]));
        }
        // Copy existing content to temp file (already in escaped on-disk form).
        if (file_exists($this->filePath)) {
            $rp = $this->openVerified('r');
            $existing = stream_get_contents($rp);
            fclose($rp);
            if ($existing !== false && $existing !== '') {
                // Torn-tail drop (finding 064): an unterminated final line is
                // a crash fragment, not an entry. The old normalization gave
                // it a newline and re-permanentized the garbage — it now
                // leaves the rewrite entirely.
                if (!str_ends_with($existing, "\n")) {
                    $cut = strrpos($existing, "\n");
                    $existing = $cut === false ? '' : substr($existing, 0, $cut + 1);
                }
            }
            if ($existing !== false && $existing !== '') {
                // Finding 013, rewrite arm: the tail is re-checked against the
                // content read under the sidecar lock, exactly like the append
                // fast path re-checks under LOCK_EX. The temp file is discarded
                // untouched when that leaves nothing to append.
                if ($lines !== [] && self::tailLineOfContent($existing) === self::escapeLine($lines[0])) {
                    array_shift($lines);
                }
                if ($lines === []) {
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    @unlink($tempFile);
                    return;
                }
                fwrite($fp, $existing);
            }
        }
        foreach ($lines as $line) {
            fwrite($fp, self::escapeLine($line) . "\n");
        }
        fflush($fp);
        self::syncHandle($fp); // durability before the rename swap (finding 064)
        flock($fp, LOCK_UN);
        fclose($fp);
        // rename() can fail when tempDir and the history file sit on
        // different filesystems (EXDEV — tempnam() falls back to the system
        // temp dir when its first choice is unwritable) or the destination
        // mount is read-only. The write is then refused loudly, never half-
        // done: the temp file is cleaned up before the throw.
        if (!@rename($tempFile, $this->filePath)) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.replace_failed', ['path' => $this->filePath]));
        }
        chmod($this->filePath, 0600);
    }

    /**
     * Serialize every writer of this history file — in-process and across
     * processes — on an exclusive lock over a sidecar `<path>.lock`. The
     * rename swap replaces the target's inode, so a lock taken on the target
     * itself cannot order writers across a rewrite; the sidecar's inode is
     * never renamed, making it the stable rendezvous. The sidecar carries no
     * payload (only its inode is locked), so it is never unlinked and a
     * symlink pre-planted at its path cannot redirect any write through it.
     *
     * The history path itself is re-checked for a symlink here: the lock has
     * to be held for the check to mean anything for the writers this class
     * serializes.
     */
    private function withWriteLock(\Closure $work): void
    {
        $lockPath = $this->filePath . '.lock';
        // The sidecar is attacker-visible too: refuse a planted symlink
        // BEFORE the first open, and set the mode on the HANDLE — a path
        // chmod would flip a swap target's permissions (finding 012: the
        // old fopen('c') + chmod pair followed links and bricked victims).
        $this->refuseSymlinkAt($lockPath, 'history lock');
        // fchmod() is unavailable on this PHP build, so the sidecar is born
        // owner-only under a 0077 umask instead of being chmod'd after the
        // fact — creation under the mask never acts through a path.
        $umask = \umask(0077);
        $lock = @fopen($lockPath, 'c');
        \umask($umask);
        if ($lock === false) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.lock_open_failed', ['path' => $lockPath]));
        }
        // Identity proof before any path-based chmod: lstat names the path
        // itself (a symlink carries its own inode), so an inode match means
        // the path still resolves to exactly the handle we opened — the
        // chmod cannot flip a swapped-in victim's mode. Pre-0077-era
        // sidecars are migrated here; freshly born ones re-assert. (This
        // build's linkinfo() returns the parent directory's inode — a trap;
        // lstat is the primitive that behaves.)
        \clearstatcache(true, $lockPath);
        $lockStat = \fstat($lock);
        $pathStat = \lstat($lockPath);
        if ($lockStat === false || $pathStat === false || (int) $pathStat['ino'] !== (int) $lockStat['ino']) {
            \fclose($lock);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.lock_changed', ['path' => $lockPath]));
        }
        \chmod($lockPath, 0600); // hygiene only — the inode never holds content
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.lock_acquire_failed', ['path' => $lockPath]));
        }
        try {
            $this->refuseSymlink();
            $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Cap-bound persistence: rewrite the file as the deduplicated union of
     * what is on disk (possibly extended by a concurrent process since we
     * loaded) and what memory retains, trimmed to maxHistory entries and
     * written oldest-first. The file is thereby bounded too — in-memory
     * eviction alone left it growing without limit.
     *
     * A push that fires the checkpoint passes its line as $pendingNewest
     * (persist-then-swap, finding 063): memory has not been swapped yet, so
     * the merged view must carry it as the newest entry — and have it claim
     * the memory-recency slot against any stale copy in the file.
     *
     * @param string|null $pendingNewest an entry to treat as the newest
     *                                   retained line without mutating memory
     */
    private function mergeAndBoundOnDisk(?string $pendingNewest = null): void
    {
        $this->withWriteLock(function () use ($pendingNewest): void {
            // Existing file lines (oldest-first, decoded).
            $existing = [];
            if (file_exists($this->filePath)) {
                $rp = $this->openVerified('r');
                $raw = stream_get_contents($rp);
                fclose($rp);
                if ($raw !== false && $raw !== '') {
                    $fileLines = explode("\n", $raw);
                    if (!str_ends_with($raw, "\n")) {
                        // Torn-tail law (finding 064): the unterminated
                        // fragment must not enter the merged rewrite — that
                        // would re-permanentize crash garbage as an entry.
                        array_pop($fileLines);
                    }
                    if ($fileLines !== [] && $fileLines[0] === self::FORMAT_HEADER) {
                        // Format marker (finding 069): metadata, never an
                        // entry — and never re-merged as one.
                        array_shift($fileLines);
                    }
                    foreach ($fileLines as $fileLine) {
                        $decoded = self::unescapeLine(rtrim($fileLine, "\r"));
                        if ($decoded !== '') {
                            $existing[] = $decoded;
                        }
                    }
                }
            }

            // Memory is newest-first; oldest-first for the merged list.
            $mine = array_reverse($this->history);
            if ($pendingNewest !== null) {
                $mine[] = $pendingNewest;
            }

            // Deferred-trim law (finding 016): for anything the memory still
            // retains, MEMORY recency is the truth — a line re-pushed after
            // eviction must trim by its fresh position, not its stale file
            // slot. File-order recency governs only the residue memory no
            // longer holds (foreign appends, evicted heads).
            $retained = array_flip($mine);
            $existing = array_values(array_filter(
                $existing,
                static fn (string $entry): bool => !isset($retained[$entry]),
            ));

            $merged = [];
            $seen = [];
            foreach ([...$existing, ...$mine] as $entry) {
                if (isset($seen[$entry])) {
                    continue;
                }
                $seen[$entry] = true;
                $merged[] = $entry;
            }
            if ($this->maxHistory > 0 && count($merged) > $this->maxHistory) {
                // Keep the NEWEST maxHistory entries (list is oldest-first).
                $merged = array_slice($merged, -$this->maxHistory);
            }

            $this->writeAllLocked($merged);
            $this->trimOverflow = 0;
        });
    }

    /**
     * Replace the file's content with $entries (oldest-first, raw form;
     * escaped on write) via temp + rename. Caller holds the sidecar lock.
     *
     * @param list<string> $entries
     */
    private function writeAllLocked(array $entries): void
    {
        $tempFile = @tempnam($this->tempDir, 'hist');
        if ($tempFile === false) {
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_create_failed', ['dir' => $this->tempDir]));
        }
        chmod($tempFile, 0600);
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_open_failed', ['path' => $tempFile]));
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.temp_lock_failed', ['path' => $tempFile]));
        }
        // Format stamp (finding 069): a full rewrite declares the format it
        // wrote. Append paths never add this line - adoption is a trajectory.
        fwrite($fp, self::FORMAT_HEADER . "\n");
        foreach ($entries as $entry) {
            fwrite($fp, self::escapeLine($entry) . "\n");
        }
        fflush($fp);
        self::syncHandle($fp); // durability before the rename swap (finding 064)
        flock($fp, LOCK_UN);
        fclose($fp);
        if (!@rename($tempFile, $this->filePath)) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: ' . Lang::t('history.replace_failed', ['path' => $this->filePath]));
        }
        // A symlink planted at the destination between rename and chmod would
        // send the chmod to its target; refuse rather than follow.
        if (!is_link($this->filePath)) {
            chmod($this->filePath, 0600);
        }
    }

    /**
     * Push written bytes to the storage layer before the lock that guards
     * them is released (finding 064). Guarded on function_exists: the call
     * is the durability seam — builds without fsync() (or mocks that hide
     * it) degrade to the fflush-then-unlock behaviour, never fatal.
     *
     * @param resource $fp
     */
    private static function syncHandle($fp): void
    {
        if (\function_exists('fsync')) {
            @\fsync($fp);
        }
    }

    /**
     * Disclose the read-lock degradation exactly once per store (finding
     * 068). error_log, not an exception: the constructor path that first
     * trips this cannot deny the caller a view the file is safe to give,
     * and a per-read notice would turn a filesystem limitation into
     * output spam. The flag makes the repeat cost a bool test.
     */
    private function noteReadLockDegraded(): void
    {
        if ($this->readLockDegraded) {
            return;
        }
        $this->readLockDegraded = true;
        error_log('sugar-readline: history read lock unavailable on ' . $this->filePath . '; continuing with unlocked reads (disclosed once per store)');
    }

    /** Encode one entry for the history file (backslash escapes, bash-style). */
    private static function escapeLine(string $line): string
    {
        return str_replace(['\\', "\n", "\r"], ['\\\\', '\\n', '\\r'], $line);
    }

    /** Decode one raw file line back into the stored entry. */
    private static function unescapeLine(string $line): string
    {
        $out = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch !== '\\') {
                $out .= $ch;
                continue;
            }
            $next = $i + 1 < $len ? $line[$i + 1] : '';
            if ($next === '\\') {
                $out .= '\\';
            } elseif ($next === 'n') {
                $out .= "\n";
            } elseif ($next === 'r') {
                $out .= "\r";
            } else {
                // Unknown (or dangling) escape: keep verbatim — legacy files
                // written before escaping existed may hold bare backslashes.
                $out .= '\\' . $next;
            }
            $i++;
        }
        return $out;
    }

    /**
     * Load any existing history from disk into the in-memory array, undoing
     * the on-disk escaping and the trailing newline of each entry.
     *
     * The file is oldest-first (append-only), so we read all lines and then
     * process them in reverse order so the newest entry ends up at index 0
     * (newest-first) in the in-memory array.
     *
     * Applies the same dedup rule as push(): skips entries already present
     * in memory to ensure non-adjacent duplicates are collapsed consistently.
     */
    private function load(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        // Read parity (finding 061): every entry point refuses a planted
        // symlink before touching it — disclosure through an unguarded
        // reader is still a leak. No inode identity check here: lock-free
        // readers legitimately observe temp+rename rewrites.
        $this->refuseSymlink();

        $fp = fopen($this->filePath, 'r');
        if ($fp === false) {
            return;
        }
        // Degrade-not-deny (finding 068): a LOCK_SH that FAILS means the
        // filesystem cannot do advisory locks at all — an active writer makes
        // this blocking call WAIT, not fail. rename-atomic content is safe to
        // read unlocked (at worst a stale version, never a torn one), so the
        // reader discloses once and proceeds; writers keep fail-closed because
        // they cannot serialize without the lock.
        $locked = flock($fp, LOCK_SH);
        if (!$locked) {
            $this->noteReadLockDegraded();
        }
        $lines = [];
        $atFirst = true;
        while (($line = fgets($fp)) !== false) {
            if (!str_ends_with($line, "\n")) {
                // Torn-tail law (finding 064): an unterminated final line is
                // a crash fragment — every writer path terminates, so it is
                // dropped rather than decoded into a bogus entry.
                break;
            }
            // Format marker (finding 069): a first line equal to the v2
            // header is metadata, never an entry.
            $isHeader = $atFirst && rtrim($line, "\r\n") === self::FORMAT_HEADER;
            $atFirst = false;
            if ($isHeader) {
                continue;
            }
            $decoded = self::unescapeLine(rtrim($line, "\r\n"));
            if ($decoded !== '') {
                $lines[] = $decoded;
            }
        }
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        // File is oldest→newest; parent::push() prepends (newest-first), so
        // iterating in normal (oldest→newest) order ends up with newest at [0].
        //
        // The dedup guard rides the resident hash set ($this->seen) — O(1)
        // membership, so load() never pays the O(n²) in_array() scan the old
        // per-push test would have caused over the file's line count.
        foreach ($lines as $entry) {
            // Law now enforced by pushResident(): the resident set mirrors
            // $this->history exactly, eviction included.
            if (isset($this->seen[$entry])) {
                continue;
            }
            $this->pushResident($entry);
        }
    }

    /**
     * Peek at the last non-empty line already written to the history file.
     *
     * Reads the file back-to-front in bounded chunks and stops as soon as the
     * final content line is fully in the buffer, so the per-push dup guard is
     * O(tail) rather than the O(file) full scan it used to run. Matches the old
     * scan semantics: trailing blank/newline-only lines are ignored and the
     * result is the last line with content (or null when the file is
     * missing/empty/all-blank).
     */
    private function peekLastOnDisk(): ?string
    {
        if (!file_exists($this->filePath)) {
            return null;
        }

        // Read parity with load() — see finding 061 note there.
        $this->refuseSymlink();

        $fp = fopen($this->filePath, 'r');
        if ($fp === false) {
            return null;
        }
        // Degrade-not-deny (finding 068) — see load() for the law; a failed
        // LOCK_SH here likewise means a filesystem that cannot lock, so the
        // tail peek proceeds unlocked after the once-per-store disclosure.
        $locked = flock($fp, LOCK_SH);
        if (!$locked) {
            $this->noteReadLockDegraded();
        }
        $result = self::tailLineOfStream($fp);
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        return $result;
    }

    /**
     * Last content line of an open history stream, read back-to-front in
     * bounded chunks — O(tail), and an arbitrarily long final line is still
     * resolved by extending the window. Blank/newline-only trailing lines are
     * skipped; null when the file holds no content line. The CALLER holds
     * whatever lock makes the answer meaningful (LOCK_SH in peek, or unlocked
      * under the finding-068 read degradation, LOCK_EX in
     * the append fast path — one implementation, no drift, finding 013).
     *
     * @param resource $fp
     */
    private static function tailLineOfStream($fp): ?string
    {
        $stat = fstat($fp);
        $size = $stat !== false ? (int) ($stat['size'] ?? 0) : 0;

        // Torn-tail law (finding 064): an unterminated final line is a crash
        // fragment, never the duplicate-guard's answer — and it must not
        // screen out the append of the very entry the crash was writing.
        // Re-aim the window at the end of the last TERMINATED line.
        if ($size > 0) {
            fseek($fp, -1, SEEK_END);
            if (fread($fp, 1) !== "\n") {
                $size = self::lastNewlineOffset($fp, $size) + 1;
            }
        }

        $chunkSize = 4096;
        $tail = '';
        $pos = $size;
        $result = null;
        while ($pos > 0) {
            $read = (int) min($chunkSize, $pos);
            $pos -= $read;
            fseek($fp, $pos, SEEK_SET);
            $tail = fread($fp, $read) . $tail;
            // Strip trailing CR/LF so a file ending in one or more newlines does
            // not yield an empty last line — mirrors the blank-skipping scan.
            $trimmedTail = rtrim($tail, "\r\n");
            if ($trimmedTail === '') {
                // Only blank/newline bytes seen so far — keep reading backwards.
                continue;
            }
            $nlPos = strrpos($trimmedTail, "\n");
            if ($nlPos !== false) {
                // Final newline delimits the last content line entirely in-buffer.
                $result = substr($trimmedTail, $nlPos + 1);
                break;
            }
            if ($pos === 0) {
                // Reached the start of the file: the whole trimmed tail is the
                // single (last) content line.
                $result = $trimmedTail;
                break;
            }
            // No delimiter yet and more file remains: the last line is longer
            // than the buffer so far — read another chunk further back.
        }

        return ($result === null || $result === '') ? null : $result;
    }

    /**
     * Position of the LAST newline at or before $endPos (searched
     * back-to-front in bounded chunks), or -1 when the file holds no
     * terminated line at all. Companion of the torn-tail law in
     * {@see tailLineOfStream()} (finding 064).
     *
     * @param resource $fp
     */
    private static function lastNewlineOffset($fp, int $endPos): int
    {
        $chunkSize = 4096;
        $pos = $endPos;
        while ($pos > 0) {
            $read = (int) min($chunkSize, $pos);
            $pos -= $read;
            fseek($fp, $pos, SEEK_SET);
            $buf = (string) fread($fp, $read);
            $nl = strrpos($buf, "\n");
            if ($nl !== false) {
                return $pos + $nl;
            }
        }
        return -1;
    }

    /**
     * Same blank-skipping tail semantics as {@see tailLineOfStream()}, from a
     * content string already in hand (the rewrite path reads the whole file
     * under the sidecar lock anyway — no second windowed pass needed).
     */
    private static function tailLineOfContent(string $content): ?string
    {
        // Torn-tail law (finding 064): an unterminated final line is a crash
        // fragment — the last TERMINATED line is the guard's answer.
        if ($content !== '' && !str_ends_with($content, "\n")) {
            $cut = strrpos($content, "\n");
            $content = $cut === false ? '' : substr($content, 0, $cut + 1);
        }
        $trimmed = rtrim($content, "\r\n");
        if ($trimmed === '') {
            return null;
        }
        $nlPos = strrpos($trimmed, "\n");
        $tail = $nlPos === false ? $trimmed : substr($trimmed, $nlPos + 1);

        return $tail === '' ? null : $tail;
    }

    /**
     * Check if the in-memory history already contains $line — O(1) against
     * the resident set mirroring the live window (finding 062; used to be an
     * in_array() scan, which made unlimited-history sessions quadratic).
     */
    private function inMemoryContains(string $line): bool
    {
        return isset($this->seen[$line]);
    }

    /**
     * Clear all entries from both in-memory history and the persistence file.
     * Uses an atomic write (temp + rename), so a reader never observes a
     * half-erased file. The empty rewrite carries no content bytes to fsync,
     * so a kernel crash can still resurrect the previous entries — as on the
     * other paths (finding 064), the guarantee is against torn reads, not
     * against the loss of the swap itself.
     *
     * The temp file is created with tempnam() (unpredictable random name) and
     * chmod 0600 BEFORE writing, then renamed over the target. A predictable
     * name (.history.tmp.<pid>) let an attacker pre-plant a symlink at that
     * path so file_put_contents(...,'') would truncate the symlink's TARGET —
     * an arbitrary-file truncation. tempnam() defeats that: the name cannot be
     * guessed and it never writes through an existing symlink.
     */
    public function clear(): void
    {
        $this->withWriteLock(function (): void {
            // Atomic write (temp + rename) so a reader never sees a half-erased
            // file; the empty write carries no content bytes to fsync, so a
            // kernel crash can still resurrect the previous entries.
            // The memory swap (finding 063) rides AFTER this commit: a caught
            // lock/replace failure leaves entries, queue and file all intact —
            // a half-clear is exactly the divergence persist-then-swap kills.
            $tempFile = @tempnam($this->tempDir, 'hist');
            if ($tempFile === false) {
                throw new \RuntimeException('sugar-readline: ' . Lang::t('history.clear_temp_failed', ['dir' => $this->tempDir]));
            }
            chmod($tempFile, 0600);
            file_put_contents($tempFile, '');
            if (!@rename($tempFile, $this->filePath)) {
                @unlink($tempFile);
                throw new \RuntimeException('sugar-readline: ' . Lang::t('history.replace_failed', ['path' => $this->filePath]));
            }
            // Defensive: if the destination pre-existed with looser perms, the
            // rename inherits the temp file's 0600, but re-assert to be safe.
            chmod($this->filePath, 0600);
        });
        parent::clear();
        // Drop queued-but-unwritten entries too — they were cleared with the rest.
        $this->pendingWrites = [];
        // The resident set mirrors the (now empty) window; the deferred trim
        // counted against a file we just erased.
        $this->seen = [];
        $this->trimOverflow = 0;
    }
}
