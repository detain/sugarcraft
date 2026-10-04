<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Memory\MemoryHistory;

/**
 * The `/memory log` and `/memory restore` sub-commands, and the commit every
 * other `/memory` change records (roadmap 5.4-2) — the text half;
 * {@see MemoryHistory} owns the repository.
 *
 * Usage:
 *   /memory log [count]        — the history, newest first (default 20, at most 200)
 *   /memory restore <commit>   — the memory directory as it stood at <commit>, as a new commit
 *
 * Every answer is a string for the transcript, never a throw: a missing `git`,
 * a repository store, or a failed call is something to tell the user, not a
 * reason to drop the command. Commit subjects are written by this app but read
 * back from disk, so they go through {@see Chat::reportField()}, like every
 * other on-disk string a report quotes.
 */
final class MemoryHistoryCommand
{
    /** The subject of a commit recording changes no `/memory` command made (the Memory tool, auto-memory, a hand edit). */
    public const OUTSIDE_SUBJECT = 'memory: changes made outside /memory';

    private const NO_HISTORY = 'Memory history covers the home memory directory (~/.sugar-crush/memory) only, '
        . 'and this session has no home memory store.';

    /** `/memory log [count]`. */
    public static function log(?MemoryHistory $history, string $args): string
    {
        if ($history === null) {
            return self::NO_HISTORY;
        }

        $args = trim($args);
        if ($args !== '' && preg_match('/\A[1-9][0-9]{0,5}\z/D', $args) !== 1) {
            return 'Usage: /memory log [count] — count is a whole number of commits, at most '
                . MemoryHistory::MAX_LOG_ENTRIES . '.';
        }
        $limit = $args === '' ? MemoryHistory::DEFAULT_LOG_ENTRIES : (int) $args;

        if (!MemoryHistory::available()) {
            return 'Memory history needs `git` on PATH; none was found.';
        }

        try {
            // Whatever changed since the last commit is recorded first, so the
            // list always ends at what is on disk now.
            $history->commit(self::OUTSIDE_SUBJECT);
            $revisions = $history->log($limit);
        } catch (\Throwable $e) {
            return 'Memory history failed: ' . Chat::reportField($e->getMessage());
        }

        if ($revisions === []) {
            return 'Memory history is empty: nothing has been saved to ' . Chat::reportField($history->dir()) . ' yet.';
        }

        $lines = ['**Memory history** (' . \count($revisions) . ', newest first) — `/memory restore <commit>` puts memory back as it stood at one:', ''];
        foreach ($revisions as $revision) {
            $lines[] = '`' . $revision->shortSha . '`  ' . $revision->committedAt->format('Y-m-d H:i')
                . '  ' . Chat::reportField($revision->subject);
        }

        return implode("\n", $lines);
    }

    /** `/memory restore <commit>`. */
    public static function restore(?MemoryHistory $history, string $args): string
    {
        if ($history === null) {
            return self::NO_HISTORY;
        }

        $revision = trim($args);
        if ($revision === '' || preg_match('/\s/', $revision) === 1) {
            return 'Usage: /memory restore <commit> — a commit id `/memory log` lists.';
        }

        if (!MemoryHistory::available()) {
            return 'Memory history needs `git` on PATH; none was found.';
        }

        try {
            $committed = $history->restore($revision);
        } catch (\InvalidArgumentException $e) {
            return 'Cannot restore: ' . Chat::reportField($e->getMessage()) . '.';
        } catch (\Throwable $e) {
            return 'Memory restore failed: ' . Chat::reportField($e->getMessage());
        }

        $shown = Chat::reportField($revision);

        return $committed === null
            ? "Memory already matches `{$shown}`; nothing changed."
            : "Memory restored to `{$shown}` as commit `{$committed}`. "
                . 'The notes it replaced are still in the history: `/memory log`, then `/memory restore` the commit before this one.';
    }

    /**
     * Commit what changed since the last commit with `$subject`, when there is
     * a history to keep (starting it on first use). Answers a warning for the
     * transcript when the commit failed, and null otherwise — including when
     * there is no `git`, which makes history unavailable rather than broken.
     */
    public static function record(?MemoryHistory $history, string $subject): ?string
    {
        if ($history === null || !MemoryHistory::available()) {
            return null;
        }

        try {
            $history->commit($subject);
        } catch (\Throwable $e) {
            return 'Memory history could not record this change: ' . Chat::reportField($e->getMessage());
        }

        return null;
    }
}
