<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\DiffReview;

use SugarCraft\Code\Host\Commands\Checkpoints;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Workspace\CheckpointDiff;
use SugarCraft\Code\Workspace\WorkspaceCheckpointer;

use function count;
use function is_string;
use function max;
use function min;

/**
 * Builds a {@see DiffReview} from the session store: the file-checkpoint
 * positions in the order `/rewind --files` and `/diff` already count back
 * through ({@see Checkpoints::fileCheckpointPositions()}, newest first), and
 * one {@see CheckpointDiff} for the base the overlay is looking at.
 *
 * The turn view (C-TUI-2.d's per-turn diff) diffs checkpoint k against
 * checkpoint k−1 with {@see CheckpointDiff::between()} — the work a single
 * turn did, frozen, not "what the files look like now". The ordinary view
 * keeps {@see CheckpointDiff::of()}: base against the work tree, exactly what
 * `/diff [n]` answers today.
 *
 * Only positions whose workspace was actually captured count as bases; a
 * refused or lost snapshot has nothing to diff, and the overlay must not step
 * onto a hole and show an empty review for it.
 */
final class DiffReviewSource
{
    /** How far back the base list is read; the store's own cap prunes older. */
    public const MAX_BASES = 1000;

    private function __construct()
    {
    }

    /**
     * The reviewable bases, newest first: stored index and captured workspace.
     *
     * @return list<array{index: int, workspace: array<string, mixed>}>
     */
    public static function bases(EnhancedSessionStore $store, string $sessionId): array
    {
        $bases = [];
        foreach (Checkpoints::fileCheckpointPositions($store, $sessionId, self::MAX_BASES) as $position) {
            $workspace = $position['workspace'];
            if ($workspace !== null && WorkspaceCheckpointer::isCaptured($workspace)) {
                $bases[] = ['index' => $position['index'], 'workspace' => $workspace];
            }
        }

        return $bases;
    }

    public static function baseCount(EnhancedSessionStore $store, string $sessionId): int
    {
        return count(self::bases($store, $sessionId));
    }

    /**
     * The review of $stepsBack checkpoint(s) back, or the reason there is
     * none: no captured checkpoints at all, or (turn view) nothing to diff
     * the oldest base against.
     */
    public static function load(
        EnhancedSessionStore $store,
        string $sessionId,
        string $root,
        int $stepsBack,
        bool $turnOnly = false,
    ): DiffReview|string {
        $positions = self::bases($store, $sessionId);
        if ($positions === []) {
            return 'this session has no file checkpoints to review';
        }
        if ($turnOnly && count($positions) < 2) {
            return 'this session has only one checkpoint; there is no earlier turn to diff against';
        }

        $bound = max(1, $turnOnly ? count($positions) - 1 : count($positions));
        $stepsBack = min(max(1, $stepsBack), $bound);
        $target = $positions[$stepsBack - 1];
        $checkpointer = $store->workspaceCheckpointer($root);
        $diff = $turnOnly
            ? CheckpointDiff::between($checkpointer, $positions[$stepsBack]['workspace'], $target['workspace'])
            : CheckpointDiff::of($checkpointer, $target['workspace']);
        if (is_string($diff)) {
            return $diff;
        }

        return DiffReview::open($diff->files(), $stepsBack, count($positions), $turnOnly, $diff->omittedLines);
    }
}
