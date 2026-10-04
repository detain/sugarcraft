<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

/**
 * Bounded garbage collection for {@see ShadowRepo} directories (item 3.A-2,
 * the W2 hand-off from 3.A-1).
 *
 * WHY IT IS NEEDED. A shadow repository is created with `gc.auto=0` and every
 * snapshot in it is a parentless commit, so when a checkpoint's ref is dropped
 * (pruned past the per-session cap, a discarded redo step, a deleted session)
 * its objects become unreachable and stay on disk: nothing in a shadow ever
 * runs git's own housekeeping. A user's real repository is never touched here
 * — git's own `gc --auto` serves it, and the snapshot objects there are the
 * user's repository's business.
 *
 * HOW IT IS BOUNDED, because it runs on the turn's Cmd (right after a shadow
 * capture) and when sessions are deleted:
 *  - at most once per {@see INTERVAL_SECONDS} per shadow, measured by a stamp
 *    file inside it that is written BEFORE git runs, so a gc that is killed
 *    or fails is not retried on every turn after it;
 *  - only when `git count-objects` reports at least {@see LOOSE_OBJECT_THRESHOLD}
 *    loose objects — one cheap process otherwise, the same estimate git's own
 *    `gc --auto` makes;
 *  - in the foreground (never detached, so no child outlives the call) under
 *    {@see BUDGET_SECONDS} of wall clock, killed past it. A killed gc leaves
 *    only temporary pack files, which the next gc removes; git writes a pack
 *    before it deletes what the pack replaces.
 *  - unreachable objects younger than {@see PRUNE_EXPIRY} are kept, so a
 *    snapshot another process has written but not yet pinned is never pruned
 *    out from under it.
 */
final class ShadowGarbageCollector
{
    /** File inside the shadow git dir whose mtime records the last run. */
    public const STAMP_FILE = 'sugar-crush-gc';

    public const INTERVAL_SECONDS = 86400;

    public const LOOSE_OBJECT_THRESHOLD = 1000;

    public const BUDGET_SECONDS = 10.0;

    public const PRUNE_EXPIRY = '1.day.ago';

    public const STATUS_COLLECTED = 'collected';
    public const STATUS_NOT_DUE = 'not-due';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /**
     * Collect the shadow repository at $gitDir if it is due.
     *
     * @return array{status: string, reason: string}
     */
    public static function collectIfDue(
        string $gitDir,
        int $looseObjectThreshold = self::LOOSE_OBJECT_THRESHOLD,
        string $pruneExpiry = self::PRUNE_EXPIRY,
        ?int $now = null,
    ): array {
        // A shadow carries the root marker ShadowRepo::ensure() writes; a
        // directory without one is not ours to collect.
        if (!is_file($gitDir . '/HEAD') || !is_file($gitDir . '/' . ShadowRepo::ROOT_MARKER)) {
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'not a shadow repository: ' . $gitDir];
        }
        if (!GitRunner::available()) {
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'git is not installed'];
        }

        $now ??= time();
        $stamp = $gitDir . '/' . self::STAMP_FILE;
        clearstatcache(true, $stamp);
        $last = is_file($stamp) ? @filemtime($stamp) : false;
        if ($last !== false && $now - $last < self::INTERVAL_SECONDS) {
            return ['status' => self::STATUS_NOT_DUE, 'reason' => 'collected less than a day ago'];
        }
        // Stamped first: a gc killed at its budget is not re-run every turn.
        @touch($stamp, $now);

        $git = GitRunner::new($gitDir)
            ->withGitDir($gitDir)
            ->withDeadline(microtime(true) + self::BUDGET_SECONDS);

        $count = $git->run('count-objects', '-v');
        if (!$count['ok']) {
            return ['status' => self::STATUS_FAILED, 'reason' => 'git count-objects failed'];
        }
        $loose = preg_match('/^count:\s*(\d+)/m', $count['stdout'], $m) === 1 ? (int) $m[1] : 0;
        if ($loose < $looseObjectThreshold) {
            return ['status' => self::STATUS_NOT_DUE, 'reason' => $loose . ' loose objects'];
        }

        $gc = $git->run('gc', '--quiet', '--prune=' . $pruneExpiry);
        if (!$gc['ok']) {
            return ['status' => self::STATUS_FAILED, 'reason' => $gc['timedOut'] ? 'git gc timed out' : 'git gc failed: ' . trim($gc['stderr'])];
        }

        return ['status' => self::STATUS_COLLECTED, 'reason' => ''];
    }
}
