<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Lang;
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
    /**
     * The subject of a commit recording changes no `/memory` command made (the
     * Memory tool, auto-memory, a hand edit). Stored data in the history
     * repository, not display text, so it stays English in every locale.
     */
    public const OUTSIDE_SUBJECT = 'memory: changes made outside /memory';

    /** `/memory log [count]`. */
    public static function log(?MemoryHistory $history, string $args): string
    {
        if ($history === null) {
            return Lang::t('cmd.memory.no-history');
        }

        $args = trim($args);
        if ($args !== '' && preg_match('/\A[1-9][0-9]{0,5}\z/D', $args) !== 1) {
            return Lang::t('cmd.memory.log-usage', ['max' => MemoryHistory::MAX_LOG_ENTRIES]);
        }
        $limit = $args === '' ? MemoryHistory::DEFAULT_LOG_ENTRIES : (int) $args;

        if (!MemoryHistory::available()) {
            return Lang::t('cmd.memory.no-git');
        }

        try {
            // Whatever changed since the last commit is recorded first, so the
            // list always ends at what is on disk now.
            $history->commit(self::OUTSIDE_SUBJECT);
            $revisions = $history->log($limit);
        } catch (\Throwable $e) {
            return Lang::t('cmd.memory.log-failed', ['error' => Chat::reportField($e->getMessage())]);
        }

        if ($revisions === []) {
            return Lang::t('cmd.memory.empty', ['dir' => Chat::reportField($history->dir())]);
        }

        $lines = [Lang::t('cmd.memory.log-heading', ['count' => \count($revisions)]), ''];
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
            return Lang::t('cmd.memory.no-history');
        }

        $revision = trim($args);
        if ($revision === '' || preg_match('/\s/', $revision) === 1) {
            return Lang::t('cmd.memory.restore-usage');
        }

        if (!MemoryHistory::available()) {
            return Lang::t('cmd.memory.no-git');
        }

        try {
            $committed = $history->restore($revision);
        } catch (\InvalidArgumentException $e) {
            return Lang::t('cmd.memory.restore-refused', ['error' => Chat::reportField($e->getMessage())]);
        } catch (\Throwable $e) {
            return Lang::t('cmd.memory.restore-failed', ['error' => Chat::reportField($e->getMessage())]);
        }

        $shown = Chat::reportField($revision);

        return $committed === null
            ? Lang::t('cmd.memory.unchanged', ['revision' => $shown])
            : Lang::t('cmd.memory.restored', ['revision' => $shown, 'commit' => $committed]);
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
            return Lang::t('cmd.memory.record-failed', ['error' => Chat::reportField($e->getMessage())]);
        }

        return null;
    }
}
