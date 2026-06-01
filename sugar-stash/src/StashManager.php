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
 */
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
     * Each line looks like:
     *   stash@{0}: WIP on main: abc1234 Last commit message
     *
     * @return list<StashEntry>
     */
    public static function fromGitOutput(array $lines): array
    {
        $stashes = [];
        foreach ($lines as $i => $line) {
            if ($line === '') continue;
            // Parse "stash@{n}: WIP on branch: sha message"
            if (preg_match('#^stash@\{(\d+)\}: (.+)$#', $line, $m)) {
                $index = (int) $m[1];
                $rest = $m[2];
                // Try to extract SHA and message
                if (preg_match('#^(.+?): ([a-f0-9]+) (.+)$#', $rest, $mm)) {
                    $branch = $mm[1];
                    $sha = $mm[2];
                    $message = $mm[3];
                } else {
                    $branch = '';
                    $sha = '';
                    $message = $rest;
                }
                $stashes[] = new StashEntry($index, $sha, $branch, $message);
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
