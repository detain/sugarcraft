<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * File-persisted history store.
 *
 * Extends InMemoryHistory with disk persistence.  The history file is
 * loaded on construction (created if absent) and updated on every push().
 * File locking is used to allow concurrent-safe access.
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
        // Touch the file so it exists after construction and set restrictive perms.
        if (!file_exists($this->filePath)) {
            touch($this->filePath);
            chmod($this->filePath, 0600);
        }
        $this->load();
    }

    public function __destruct()
    {
        $this->flush();
    }

    public function __clone()
    {
        // Navigation clones (TextPrompt clones its history per operation) must
        // not re-flush the original's queued writes when they are destroyed —
        // only the caller-owned instance persists its own queue.
        $this->pendingWrites = [];
    }

    /**
     * Append $line to the history file and notify the parent to update its
     * in-memory state. Persistence goes through appendLinesAtomically(), which
     * takes an in-place LOCK_EX append fast-path and falls back to a secure
     * temp + rename rewrite, keeping 0600 permissions either way.
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

        // Sync path also guards against another process having appended the
        // same line since we loaded.
        if ($line === $this->peekLastOnDisk()) {
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

        // Fast path: append in place when the on-disk tail is newline-terminated.
        // The file already exists at 0600 (constructor touch + clear()/rewrite),
        // so an in-place append preserves those perms; re-assert defensively in
        // case it was re-created since construction.
        if (file_exists($this->filePath)) {
            $fp = fopen($this->filePath, 'a+');
            if ($fp !== false) {
                flock($fp, LOCK_EX);
                $stat = fstat($fp);
                $size = $stat !== false ? (int) ($stat['size'] ?? 0) : 0;
                $endsWithNewline = true;
                if ($size > 0) {
                    fseek($fp, -1, SEEK_END);
                    $endsWithNewline = fread($fp, 1) === "\n";
                }
                if ($endsWithNewline) {
                    foreach ($lines as $line) {
                        fwrite($fp, $line . "\n");
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

        $tempFile = @tempnam($this->tempDir, 'hist');
        if ($tempFile === false) {
            return;
        }
        // Restrict perms before writing so history content is never exposed,
        // even briefly, on any platform/umask (tempnam is 0600 on POSIX only).
        chmod($tempFile, 0600);
        $fp = fopen($tempFile, 'w');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        // Copy existing content to temp file.
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
            fwrite($fp, $line . "\n");
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        rename($tempFile, $this->filePath);
        chmod($this->filePath, 0600);
    }

    /**
     * Load any existing history from disk into the in-memory array, removing
     * the trailing newline from each entry.
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
        flock($fp, LOCK_SH);
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed !== '') {
                $lines[] = $trimmed;
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
        flock($fp, LOCK_SH);
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
     * Uses atomic write (temp + rename) to prevent corruption on crash.
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
        // Atomic write via temp file to prevent corruption on crash.
        $tempFile = @tempnam($this->tempDir, 'hist');
        if ($tempFile === false) {
            return;
        }
        chmod($tempFile, 0600);
        file_put_contents($tempFile, '');
        rename($tempFile, $this->filePath);
        // Defensive: if the destination pre-existed with looser perms, the
        // rename inherits the temp file's 0600, but re-assert to be safe.
        chmod($this->filePath, 0600);
    }
}
