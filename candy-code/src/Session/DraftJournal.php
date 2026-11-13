<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session;

/**
 * One-slot marker that a launch ended (or Ctrl+C cleared the box) with an
 * UNSENT draft, so the next launch can say so in one line.
 *
 * The draft's text itself is journaled into {@see PromptHistory} (that is the
 * restore file: Up recalls it across sessions); this marker only records that
 * it happened and how long it was, so a Ctrl+C never silently eats a draft
 * and the user is told where it went. Created 0600 for the same reason as the
 * history file: drafts carry pasted secrets, and the marker carries only a
 * character count, never the text.
 *
 * Best effort throughout — a failed write must never block a quit.
 */
final class DraftJournal
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Record that a draft of $chars characters was journaled. */
    public function mark(int $chars): void
    {
        if ($chars < 1) {
            return;
        }

        $previousUmask = umask(0077);
        try {
            $dir = dirname($this->path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }
            @file_put_contents($this->path, (string) json_encode(['chars' => $chars]), LOCK_EX);
        } finally {
            umask($previousUmask);
        }
    }

    /** Forget the marker: the user moved on (sent a prompt). */
    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * Read and consume the marker: the journaled draft's length, or null when
     * the last session ended clean (or the file is unreadable/torn).
     */
    public function take(): ?int
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        @unlink($this->path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $chars = is_array($decoded) ? ($decoded['chars'] ?? null) : null;

        return is_int($chars) && $chars > 0 ? $chars : null;
    }
}
