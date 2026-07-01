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

    /**
     * @param string $filePath    Path to the history file.  Created automatically if absent.
     * @param int    $maxHistory  Maximum number of entries to retain. 0 = unlimited.
     * @param string $tempDir     Directory for temporary files during atomic writes.
     */
    public function __construct(string $filePath, int $maxHistory = 0, string $tempDir = '')
    {
        parent::__construct($maxHistory);
        $this->filePath = $filePath;
        $this->tempDir = $tempDir !== '' ? $tempDir : \dirname($filePath);
        // Touch the file so it exists after construction and set restrictive perms.
        if (!file_exists($this->filePath)) {
            touch($this->filePath);
            chmod($this->filePath, 0600);
        }
        $this->load();
    }

    /**
     * Append $line to the history file and notify the parent to update its
     * in-memory state.  Uses atomic write (read existing + write temp + rename)
     * to prevent corruption on crash and ensures 0600 permissions.
     */
    public function push(string $line): void
    {
        if ($line === '') {
            return;
        }
        // Skip duplicate of either the on-disk last line OR any in-memory
        // entry (handles the case where the same line was added earlier in
        // this session but isn't yet the "last" entry).
        $lastOnDisk = $this->peekLastOnDisk();
        if ($line === $lastOnDisk || $this->inMemoryContains($line)) {
            return;
        }

        // Atomic write: read existing content, append new line to temp file,
        // then rename to target. This prevents corruption if the process
        // crashes during write and ensures no data loss.
        $tempFile = $this->tempDir . '/.history.tmp.' . getmypid();
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
        fwrite($fp, $line . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        rename($tempFile, $this->filePath);
        chmod($this->filePath, 0600);

        parent::push($line);
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
     */
    public function clear(): void
    {
        parent::clear();
        // Atomic write via temp file to prevent corruption on crash.
        $tempFile = $this->tempDir . '/.history.tmp.' . getmypid();
        file_put_contents($tempFile, '');
        chmod($tempFile, 0600);
        rename($tempFile, $this->filePath);
    }
}
