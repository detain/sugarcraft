<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Immutable stash manager state — tracks stash list and selection cursor.
 *
 * Activated by pressing 'S' (capital S) from the main view. Shows list
 * of stashes with cursor navigation, 'a' to apply, 'd' to drop.
 *
 * @readonly
 *
 * Mirrors jesseduffield/lazygit stash panel controllers — list, apply, drop over `git stash list`. */
final readonly class StashManager
{
    /**
     * @param list<StashEntry> $stashes
     * @param int              $cursor    Current cursor index in the stash list
     */
    public function __construct(
        public array $stashes,
        public int $cursor = 0,
    ) {}

    /**
     * Build a StashManager from raw `git stash list` output.
     *
     * The three real shapes git emits (audit #15):
     *   stash@{0}: WIP on main: abc1234 Last commit subject
     *   stash@{0}: index on main: abc1234 Last commit subject
     *   stash@{0}: On main: hand-written stash message   <- NO sha here
     * The old generic `(.+?): ([a-f0-9]+) (.+)` inner match labelled the
     * whole "WIP on main" as the branch and dropped everything else into
     * the else-arm for the `On <branch>: <msg>` form (empty branch/sha).
     *
     * @param list<string> $lines
     * @return list<StashEntry>
     */
    public static function fromGitOutput(array $lines): array
    {
        $stashes = [];
        foreach ($lines as $line) {
            if ($line === '') continue;
            if (preg_match('#^stash@\{(\d+)\}: (?:WIP|index) on (.+?): ([0-9a-f]{4,40}) (.*)$#', $line, $m)) {
                $stashes[] = new StashEntry((int) $m[1], $m[3], $m[2], $m[4]);
            } elseif (preg_match('#^stash@\{(\d+)\}: On (.+?): (.*)$#', $line, $m)) {
                $stashes[] = new StashEntry((int) $m[1], '', $m[2], $m[3]);
            } elseif (preg_match('#^stash@\{(\d+)\}: (.+)$#', $line, $m)) {
                // Unknown decoration — keep it visible rather than dropping the row.
                $stashes[] = new StashEntry((int) $m[1], '', '', $m[2]);
            }
        }
        return $stashes;
    }

    /**
     * Return a new StashManager with the cursor moved by $dir (clamped).
     */
    public function withCursor(int $dir): self
    {
        $count = count($this->stashes);
        if ($count === 0) {
            return new self(stashes: [], cursor: 0);
        }
        $newCursor = max(0, min($count - 1, $this->cursor + $dir));
        return new self(stashes: $this->stashes, cursor: $newCursor);
    }

    /**
     * Return a new StashManager with updated stash list.
     */
    public function withStashes(array $stashes): self
    {
        return new self(stashes: $stashes, cursor: $this->cursor);
    }

    /**
     * Filter stashes using fuzzy search on message and branch fields.
     *
     * Uses Smith-Waterman local alignment to score and rank stash entries
     * by similarity to the query string. Returns a new StashManager with
     * filtered entries sorted by score descending, cursor reset to 0.
     *
     * @param string $query Fuzzy search query
     * @return self New StashManager with fuzzy-filtered stashes
     */
    public function fuzzyFilter(string $query): self
    {
        if ($query === '') {
            return $this;
        }

        $matcher = new SmithWatermanMatcher();

        $candidates = array_map(
            static fn(StashEntry $e) => $e->branch . ' ' . $e->message,
            $this->stashes
        );

        $results = $matcher->matchAll($query, $candidates);

        // Build map from candidate string to MatchResult for quick lookup
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r->haystack] = $r;
        }

        // Walk original stashes, keeping only those with a match, preserving order by score desc
        $matched = [];
        foreach ($this->stashes as $e) {
            $candidate = $e->branch . ' ' . $e->message;
            if (isset($resultMap[$candidate])) {
                $matched[] = ['entry' => $e, 'score' => $resultMap[$candidate]->score];
            }
        }

        // Sort by score descending
        usort($matched, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        $filteredStashes = array_map(static fn(array $r) => $r['entry'], $matched);

        return new self(stashes: $filteredStashes, cursor: 0);
    }

    /**
     * The currently selected stash entry, or null if list is empty.
     */
    public function current(): ?StashEntry
    {
        return $this->stashes[$this->cursor] ?? null;
    }

    /**
     * Number of stashes in the list.
     */
    public function count(): int
    {
        return count($this->stashes);
    }
}
