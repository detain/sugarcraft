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

    /**
     * @param string $filePath Path to the history file.  Created automatically if absent.
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        // Touch the file so it exists after construction.
        if (!file_exists($this->filePath)) {
            touch($this->filePath);
        }
        $this->load();
    }

    /**
     * Append $line to the history file and notify the parent to update its
     * in-memory state.
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

        $fp = fopen($this->filePath, 'a');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $line . "\n");
        flock($fp, LOCK_UN);
        fclose($fp);

        parent::push($line);
    }

    /**
     * Load any existing history from disk into the in-memory array, removing
     * the trailing newline from each entry.
     *
     * The file is oldest-first (append-only), so we read all lines and then
     * process them in reverse order so the newest entry ends up at index 0
     * (newest-first) in the in-memory array.
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
        foreach ($lines as $entry) {
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
     * Return the most recent in-memory entry (newest), or null if empty.
     */
    private function getInMemoryLast(): ?string
    {
        return $this->getLastEntry();
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
     */
    public function clear(): void
    {
        parent::clear();
        file_put_contents($this->filePath, '');
    }
}
