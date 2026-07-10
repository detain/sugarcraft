<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Thin delegating shim over the candy-fuzzy SSOT
 * ({@see SmithWatermanMatcher}). candy-lister historically shipped a
 * bit-identical copy of the Smith-Waterman scorer; the DP core now lives in
 * candy-fuzzy, and the default {@see ScoringProfile} is bit-equivalent to the
 * classic constants, so scores and rankings are preserved byte-for-byte for
 * inputs within candy-fuzzy's DoS length caps.
 *
 * The public API (constructor, withProfile(), score(), match()) is unchanged.
 * match() keeps candy-lister's own \Stringable-item contract and input-order
 * tiebreak — it is intentionally NOT SmithWatermanMatcher::matchAll(), whose
 * haystack-ascending tiebreak would reorder equal-score items.
 *
 * ## CancellationToken support (intended API — requires candy-async)
 * When candy-async cancellation is wired in, match() will accept an optional
 * `?CancellationToken $token = null` parameter. Scoring is batched
 * (e.g., 100 candidates per futureTick) via loop()->futureTick().
 * If cancelled, throws `SugarCraft\Async\OperationCancelledException`.
 */
final class FuzzyMatch
{
    private ScoringProfile $profile;

    private SmithWatermanMatcher $matcher;

    public function __construct(?ScoringProfile $profile = null)
    {
        $this->profile = $profile ?? ScoringProfile::default();
        $this->matcher = SmithWatermanMatcher::new($this->profile);
    }

    /**
     * Set a custom scoring profile.
     */
    public function withProfile(ScoringProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        $clone->matcher = SmithWatermanMatcher::new($profile);
        return $clone;
    }

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     *
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously). Delegates to the
     * candy-fuzzy SSOT; case folding is locale-independent Unicode
     * (mb_strtolower UTF-8) and the result matches candy-lister's historical
     * scorer byte-for-byte.
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score
     * @return int The alignment score (higher = better match)
     */
    public function score(string $query, string $candidate): int
    {
        return $this->matcher->score($query, $candidate);
    }

    /**
     * Filter and rank candidates by fuzzy match score against the query.
     *
     * Returns candidates sorted by score descending (best matches first).
     * Only returns candidates with a score > 0. Equal scores keep input order
     * (stable sort) — candy-lister's contract, distinct from the SSOT's
     * matchAll() haystack-ascending tiebreak.
     *
     * @param string          $query      The search query
     * @param list<\Stringable> $items    List of Stringable items to score
     * @return list<array{\Stringable, int}> List of [item, score] pairs sorted by score desc
     */
    public function match(string $query, array $items): array
    {
        if ($query === '' || $items === []) {
            return [];
        }

        $scored = [];
        foreach ($items as $item) {
            $score = $this->score($query, (string) $item);
            if ($score > 0) {
                $scored[] = [$item, $score];
            }
        }

        // Sort by score descending
        usort($scored, static fn(array $a, array $b) => $b[1] <=> $a[1]);

        return $scored;
    }
}
