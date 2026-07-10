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
     * in-memory state.  Uses atomic write (read existing + write temp + rename)
     * to prevent corruption on crash and ensures 0600 permissions.
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
     * Atomic append: copy existing content plus $lines into a temp file, then
     * rename over the target. Prevents corruption if the process crashes
     * mid-write and keeps 0600 permissions.
     *
     * The temp file is created with tempnam() (unpredictable random name) and
     * chmod 0600 BEFORE any history content is written. History can contain
     * sensitive shell commands, so this closes two holes a predictable name
     * (.history.tmp.<pid>) left open: (1) a world-readable window between
     * create and the post-rename chmod, and (2) a symlink pre-plant — an
     * attacker with write access to $this->tempDir (which defaults to the
     * history file's own directory) could plant a symlink at the predictable
     * path so fopen(...,'w') would follow it and leak/corrupt an arbitrary
     * target. tempnam() creates a fresh regular file with a name the attacker
     * cannot guess and never opens through an existing symlink.
     *
     * @param list<string> $lines
     */
    private function appendLinesAtomically(array $lines): void
    {
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
        // Apply same dedup guard as push() to skip entries already in memory.
        foreach ($lines as $entry) {
            if ($this->inMemoryContains($entry)) {
                continue;
            }
            parent::push($entry);
        }
    }

    /**
     * Peek at the last line already written to the history file without
     * loading the full file.
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
        $last = null;
        while (($line = fgets($fp)) !== false) {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed !== '') {
                $last = $trimmed;
            }
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        return $last;
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
