<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * Cross-session record of every prompt the user typed and sent, oldest
 * first — what the chat box's ↑/↓ recall walks.
 *
 * Mirrors the shell's `~/.bash_history` rather than the session database on
 * purpose: recall is a property of the USER, not of a conversation. A fresh
 * launch opens a new session with an empty transcript, and ↑ still has to
 * reach the last thing typed in the previous one — which the per-session
 * checkpoint rows cannot answer without decoding every session's newest
 * snapshot at startup.
 *
 * One JSON-encoded string per line, so a multi-line prompt (Alt+Enter) round
 * trips intact. Appends take an exclusive lock, so two clients open in two
 * terminals interleave whole lines instead of tearing each other's writes;
 * each client keeps its own in-memory list for the rest of its run, the way
 * two shells do.
 *
 * Created 0600 inside a 0077 umask for the same reason as `session.db`
 * ({@see SessionStore}): prompts routinely carry pasted secrets.
 */
final class PromptHistory
{
    /** Entries kept on disk once the file is compacted. */
    public const DEFAULT_LIMIT = 1000;

    /**
     * @param string $path  The history file; its directory is created on the
     *                      first append if missing.
     * @param int    $limit How many entries survive a compaction. The file may
     *                      grow to twice this before it is rewritten, so the
     *                      rewrite is amortised rather than paid per prompt.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException("Prompt history limit must be at least 1; got {$limit}.");
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Every stored entry, oldest first, newest {@see $limit} only.
     *
     * Tolerant by design: a missing or unreadable file is an empty history,
     * and a line that does not decode to a non-empty string (a torn write, a
     * hand edit) is skipped rather than failing the launch.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $lines = @file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_string($decoded) && trim($decoded) !== '') {
                $entries[] = $decoded;
            }
        }

        return array_slice($entries, -$this->limit);
    }

    /**
     * Record one sent prompt. Blank prompts are ignored; so is an exact repeat
     * of the newest entry already on disk (the same de-duplication `HISTCONTROL=ignoredups`
     * gives a shell), so mashing Enter on a recalled prompt does not bury
     * everything behind it.
     *
     * Best effort: a failure to write never reaches the caller, because losing
     * a recall entry must not cost the user the turn they just sent.
     */
    public function append(string $entry): void
    {
        if (trim($entry) === '') {
            return;
        }

        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return;
        }

        $previousUmask = umask(0077);
        try {
            $dir = dirname($this->path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }

            $handle = @fopen($this->path, 'c+');
            if ($handle === false) {
                return;
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    return;
                }

                $existing = stream_get_contents($handle);
                $lines = $existing === false || $existing === ''
                    ? []
                    : explode("\n", rtrim($existing, "\n"));

                if ($lines !== [] && end($lines) === $encoded) {
                    return;
                }

                $lines[] = $encoded;
                if (count($lines) > 2 * $this->limit) {
                    // Compaction rewrites the whole file under the same lock.
                    $lines = array_slice($lines, -$this->limit);
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, implode("\n", $lines) . "\n");
                } else {
                    fseek($handle, 0, SEEK_END);
                    fwrite($handle, $encoded . "\n");
                }
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } finally {
            umask($previousUmask);
        }
    }
}
