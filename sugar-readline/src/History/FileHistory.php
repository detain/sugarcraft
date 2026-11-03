<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

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
 * skipped (zsh `HIST_IGNORE_ALL_DUPS`-style; the weaker adjacent-only rule
 * GNU/zsh `HIST_IGNORE_DUPS` describe is what InMemoryHistory enforces).
 *
 * On-disk format: one entry per line, with backslash escapes (mirroring
 * bash history-file conventions) applied at the write choke and undone at
 * load — `\\` for a backslash, `\n` / `\r` for embedded newlines and
 * carriage returns. Without escaping an entry containing a raw LF reloads
 * as TWO entries. Files written before escaping existed may therefore
 * decode a literal `\n` sequence into a newline on load (documented
 * consequence of adopting the format).
 *
 * Symlink policy: the history path must be a regular file. A symlink at it
 * is refused at construction and re-checked under the write lock —
 * otherwise pointing the history file at, say, `~/.bashrc` would append
 * attacker-chosen lines into it and chmod it to 0600.
 *
 * Write failures (lock or temp file unavailable) throw RuntimeException;
 * queued entries are then lost loudly rather than silently. The automatic
 * flush() on destruct downgrades to an error_log line — throwing from a
 * destructor is fatal territory.
 */
final class FileHistory extends InMemoryHistory
{
    private string $filePath;
    private string $tempDir;
    private bool $deferWrites;

    /** @var list<string> Entries pushed but not yet persisted (deferred mode only). */
    private array $pendingWrites = [];

    /**
     * @param string $filePath    Path to the history file.  Created automatically if absent.
     * @param int    $maxHistory  Maximum number of entries to retain. 0 = unlimited.
     * @param string $tempDir     Directory for temporary files during atomic writes.
     * @param bool   $deferWrites When true, push() only queues the entry in memory;
     *                            disk I/O happens in one batched atomic write on
     *                            flush() (called automatically on destruct).
     *                            Trade-off: submit/keystroke latency stays free of
     *                            blocking file I/O, but queued entries are lost if
     *                            the process dies before flush (e.g. SIGKILL).
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
                throw new \RuntimeException('sugar-readline: cannot create history file ' . $this->filePath);
            }
        }
        if (!chmod($this->filePath, 0600)) {
            throw new \RuntimeException('sugar-readline: cannot set 0600 on history file ' . $this->filePath);
        }
        $this->load();
    }

    /**
     * Fail if the history path is (or has become) a symlink. Every open in
     * this class follows the path, so the check runs at construction and
     * again once the write lock is held.
     */
    private function refuseSymlink(): void
    {
        \clearstatcache(true, $this->filePath);
        if (\is_link($this->filePath)) {
            throw new \RuntimeException('sugar-readline: refusing symlinked history file ' . $this->filePath);
        }
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
     * DISK must stay bounded too, so every push rewrites the file as the
     * merged, deduplicated, cap-trimmed view of file + memory
     * (mergeAndBoundOnDisk()) — otherwise the in-memory eviction would leave
     * the file growing without limit.
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
     *                           never a silent drop.
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
            parent::push($line);
            return;
        }

        if ($this->maxHistory > 0) {
            // Capped mode: the file mirrors the bounded memory (merged with
            // whatever another process may have appended since we loaded).
            parent::push($line);
            $this->mergeAndBoundOnDisk();
            return;
        }

        // Sync path also guards against another process having appended the
        // same line since we loaded. peekLastOnDisk() answers with the raw
        // (escaped) tail line, so compare against the escaped candidate.
        if ($this->peekLastOnDisk() === self::escapeLine($line)) {
            return;
        }

        $this->appendLinesAtomically([$line]);

        parent::push($line);
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
            return;
        }
        $pending = $this->pendingWrites;
        $this->pendingWrites = [];
        if ($this->maxHistory > 0) {
            // The queued entries already live in memory (push() updated it);
            // the bounded rewrite persists the whole retained view.
            $this->mergeAndBoundOnDisk();
            return;
        }
        $this->appendLinesAtomically($pending);
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
     * rewrite that copies existing content, normalizes a missing trailing
     * newline, then renames over the target. The temp file is created with
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
                $fp = fopen($this->filePath, 'a+');
                if ($fp !== false) {
                    if (!flock($fp, LOCK_EX)) {
                        fclose($fp);
                        throw new \RuntimeException('sugar-readline: cannot acquire append lock on ' . $this->filePath);
                    }
                    $stat = fstat($fp);
                    $size = $stat !== false ? (int) ($stat['size'] ?? 0) : 0;
                    $endsWithNewline = true;
                    if ($size > 0) {
                        fseek($fp, -1, SEEK_END);
                        $endsWithNewline = fread($fp, 1) === "\n";
                    }
                    if ($endsWithNewline) {
                        foreach ($lines as $line) {
                            fwrite($fp, self::escapeLine($line) . "\n");
                        }
                        fflush($fp);
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        chmod($this->filePath, 0600);
                        return;
                    }
                    // Missing trailing newline — release and fall through to the
                    // normalizing rewrite below.
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
            throw new \RuntimeException('sugar-readline: cannot create temp file for history rewrite in ' . $this->tempDir);
        }
        // Restrict perms before writing so history content is never exposed,
        // even briefly, on any platform/umask (tempnam is 0600 on POSIX only).
        chmod($tempFile, 0600);
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot open temp file for history rewrite: ' . $tempFile);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot lock temp file for history rewrite: ' . $tempFile);
        }
        // Copy existing content to temp file (already in escaped on-disk form).
        if (file_exists($this->filePath)) {
            $existing = file_get_contents($this->filePath);
            if ($existing !== false && $existing !== '') {
                fwrite($fp, $existing);
                // Ensure file ends with newline before appending.
                if (substr($existing, -1) !== "\n") {
                    fwrite($fp, "\n");
                }
            }
        }
        foreach ($lines as $line) {
            fwrite($fp, self::escapeLine($line) . "\n");
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        // rename() can fail when tempDir and the history file sit on
        // different filesystems (EXDEV — tempnam() falls back to the system
        // temp dir when its first choice is unwritable) or the destination
        // mount is read-only. The write is then refused loudly, never half-
        // done: the temp file is cleaned up before the throw.
        if (!@rename($tempFile, $this->filePath)) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot replace history file ' . $this->filePath);
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
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('sugar-readline: cannot open history write lock ' . $lockPath);
        }
        chmod($lockPath, 0600); // hygiene only — the inode never holds content
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('sugar-readline: cannot acquire history write lock ' . $lockPath);
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
     */
    private function mergeAndBoundOnDisk(): void
    {
        $this->withWriteLock(function (): void {
            // Existing file lines (oldest-first, decoded).
            $existing = [];
            if (file_exists($this->filePath)) {
                $raw = file_get_contents($this->filePath);
                if ($raw !== false && $raw !== '') {
                    foreach (explode("\n", $raw) as $fileLine) {
                        $decoded = self::unescapeLine(rtrim($fileLine, "\r"));
                        if ($decoded !== '') {
                            $existing[] = $decoded;
                        }
                    }
                }
            }

            // Memory is newest-first; oldest-first for the merged list.
            $mine = array_reverse($this->history);

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
            throw new \RuntimeException('sugar-readline: cannot create temp file for history rewrite in ' . $this->tempDir);
        }
        chmod($tempFile, 0600);
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot open temp file for history rewrite: ' . $tempFile);
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot lock temp file for history rewrite: ' . $tempFile);
        }
        foreach ($entries as $entry) {
            fwrite($fp, self::escapeLine($entry) . "\n");
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if (!@rename($tempFile, $this->filePath)) {
            @unlink($tempFile);
            throw new \RuntimeException('sugar-readline: cannot replace history file ' . $this->filePath);
        }
        // A symlink planted at the destination between rename and chmod would
        // send the chmod to its target; refuse rather than follow.
        if (!is_link($this->filePath)) {
            chmod($this->filePath, 0600);
        }
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

        $fp = fopen($this->filePath, 'r');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            throw new \RuntimeException('sugar-readline: cannot acquire history read lock on ' . $this->filePath);
        }
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $decoded = self::unescapeLine(rtrim($line, "\r\n"));
            if ($decoded !== '') {
                $lines[] = $decoded;
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        // File is oldest→newest; parent::push() prepends (newest-first), so
        // iterating in normal (oldest→newest) order ends up with newest at [0].
        //
        // Apply the same dedup guard as push(), but back the membership test
        // with an O(1) hash set instead of inMemoryContains()'s in_array()
        // scan — otherwise load() is O(n²) in the number of file lines. The set
        // must mirror $this->history EXACTLY, including maxHistory eviction:
        // when a push overflows the cap, parent::push() drops the oldest (tail)
        // entry, which must also leave the set so a later duplicate of an
        // evicted line is re-admitted precisely as an in_array() over the live
        // (post-eviction) history would be.
        $seen = [];
        foreach ($lines as $entry) {
            if (isset($seen[$entry])) {
                continue;
            }
            $sizeBefore = \count($this->history);
            // $entry is guaranteed absent from history here (not in $seen), so
            // parent::push() always prepends: the count grows by one unless the
            // cap evicted the previous tail, in which case it stays equal.
            $tailBefore = $sizeBefore > 0 ? $this->history[$sizeBefore - 1] : null;
            parent::push($entry);
            $seen[$entry] = true;
            if (\count($this->history) === $sizeBefore && $tailBefore !== null) {
                unset($seen[$tailBefore]);
            }
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

        $fp = fopen($this->filePath, 'r');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            throw new \RuntimeException('sugar-readline: cannot acquire history read lock on ' . $this->filePath);
        }
        $stat = fstat($fp);
        $size = $stat !== false ? (int) ($stat['size'] ?? 0) : 0;

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
        flock($fp, LOCK_UN);
        fclose($fp);

        return ($result === null || $result === '') ? null : $result;
    }

    /**
     * Check if the in-memory history already contains $line.
     */
    private function inMemoryContains(string $line): bool
    {
        return \in_array($line, $this->history, true);
    }

    /**
     * Clear all entries from both in-memory history and the persistence file.
     * Uses an atomic write (temp + rename), so a reader never observes a
     * half-erased file. No fsync — a kernel crash can still lose the most
     * recent writes (and the rename), like every other persistence path in
     * this class; the guarantee is against torn reads, not durability.
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
        parent::clear();
        // Drop queued-but-unwritten entries too — they were cleared with the rest.
        $this->pendingWrites = [];
        $this->withWriteLock(function (): void {
            // Atomic write (temp + rename) so a reader never sees a half-erased
            // file; no fsync, so a kernel crash can still lose recent writes.
            $tempFile = @tempnam($this->tempDir, 'hist');
            if ($tempFile === false) {
                throw new \RuntimeException('sugar-readline: cannot create temp file for history clear in ' . $this->tempDir);
            }
            chmod($tempFile, 0600);
            file_put_contents($tempFile, '');
            if (!@rename($tempFile, $this->filePath)) {
                @unlink($tempFile);
                throw new \RuntimeException('sugar-readline: cannot replace history file ' . $this->filePath);
            }
            // Defensive: if the destination pre-existed with looser perms, the
            // rename inherits the temp file's 0600, but re-assert to be safe.
            chmod($this->filePath, 0600);
        });
    }
}
