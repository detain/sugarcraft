<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Thin delegating shim over the sugar-fuzzy SSOT
 * ({@see SmithWatermanMatcher}). sugar-treelist historically shipped a
 * bit-identical copy of the Smith-Waterman scorer; the DP core now lives in
 * sugar-fuzzy, and the default {@see ScoringProfile} is bit-equivalent to the
 * classic constants, so scores and rankings are preserved for inputs within
 * sugar-fuzzy's DoS length caps — apart from the per-codepoint case-fold
 * deviations sugar-fuzzy's CharFold now applies deliberately (see score()).
 *
 * Over-cap input (query past {@see SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH},
 * candidate past the cap given by {@see self::withCandidateCap()}, default
 * {@see SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH} characters) is
 * aligned on its PREFIX, on the same score scale as every other item — so
 * match() ranks a very long item consistently with short ones (the SSOT once
 * switched over-cap input to a Sahilm fallback on a different scale).
 * The trade-off: text that exists only past the candidate cap is not searched.
 *
 * The pass-through knobs (sugar-treelist-015): match() gained a `minScore`
 * threshold and a `limit` (the SSOT's ceiling prune runs behind it — a
 * candidate whose best attainable score cannot reach the threshold never pays
 * for the quadratic alignment), the constructor gained a candidate cap and a
 * strict-mode (full-query coverage) flag, and {@see self::matchGenerator()}
 * mirrors the SSOT's streaming door. score() is unchanged.
 *
 * match() keeps sugar-treelist's own \Stringable-item contract and input-order
 * tiebreak — it is intentionally NOT SmithWatermanMatcher::matchAll(), whose
 * haystack-ascending tiebreak would reorder equal-score items. The tiebreak is
 * carried structurally (each hit keeps its input position through the sort;
 * PHP's usort is unstable), and duplicate strings collapse to one scoring pass
 * because score is a pure function of (query, candidate).
 *
 * Match positions are out of scope: this door reports [item, score] pairs,
 * never per-candidate matched indexes (upstream's Rank{Index, MatchedIndexes}
 * + MatchesForItem filter-aware highlighting leg never landed here). Hosts
 * that want the matched runs styled have the pieces in the sugar-fuzzy
 * sibling — SmithWatermanMatcher hands back MatchResult objects carrying the
 * indexes, and SugarCraft\Fuzzy\Highlighter wraps matched runs in a
 * caller-supplied styler. See "Match positions and highlighting" in the README.
 */
final class FuzzyMatch
{
    private ScoringProfile $profile;

    private SmithWatermanMatcher $matcher;

    private int $maxCandidateLength;

    private bool $strictMode;

    /**
     * @param ScoringProfile|null $profile            Scoring weights (default: canonical)
     * @param int                 $maxCandidateLength Candidate characters that take part in the
     *                                                alignment (DoS cap, >= 1; over-cap candidates
     *                                                are searched on their prefix only)
     * @param bool                $strictMode         Keep only candidates whose alignment covers
     *                                                the WHOLE query in order (SSOT
     *                                                requireFullQuery: `bxz` stops matching
     *                                                `abcdef` on its lone `b`)
     * @throws \InvalidArgumentException If $maxCandidateLength is below 1
     */
    public function __construct(
        ?ScoringProfile $profile = null,
        int $maxCandidateLength = SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH,
        bool $strictMode = false,
    ) {
        if ($maxCandidateLength < 1) {
            throw new \InvalidArgumentException('Candidate cap must be >= 1.');
        }
        $this->profile = $profile ?? ScoringProfile::canonical();
        $this->maxCandidateLength = $maxCandidateLength;
        $this->strictMode = $strictMode;
        $this->buildMatcher();
    }

    /**
     * House factory (sugar-treelist-027): mirrors the constructor's defaults.
     *
     * @param ScoringProfile|null $profile Scoring weights (default: canonical)
     */
    public static function new(
        ?ScoringProfile $profile = null,
        int $maxCandidateLength = SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH,
        bool $strictMode = false,
    ): self {
        return new self($profile, $maxCandidateLength, $strictMode);
    }

    /**
     * Set a custom scoring profile.
     *
     * The candidate cap and strict-mode knobs survive the swap — only the
     * weights change.
     */
    public function withProfile(ScoringProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        $clone->buildMatcher();
        return $clone;
    }

    /**
     * Copy with a different candidate cap (characters searched per item).
     *
     * @throws \InvalidArgumentException If $characters is below 1
     */
    public function withCandidateCap(int $characters): self
    {
        if ($characters < 1) {
            throw new \InvalidArgumentException('Candidate cap must be >= 1.');
        }
        $clone = clone $this;
        $clone->maxCandidateLength = $characters;
        $clone->buildMatcher();
        return $clone;
    }

    /**
     * Copy with full-query coverage required (or not).
     *
     * Strict mode is the SSOT's requireFullQuery door: a candidate only
     * matches when every query character lands in order, so scattered
     * incidental alignments drop out of the result set entirely.
     */
    public function withStrictMode(bool $strict = true): self
    {
        $clone = clone $this;
        $clone->strictMode = $strict;
        $clone->buildMatcher();
        return $clone;
    }

    /** Candidate characters that take part in the alignment. */
    public function candidateCap(): int
    {
        return $this->maxCandidateLength;
    }

    /** Whether only whole-query-covering alignments count as matches. */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    private function buildMatcher(): void
    {
        $this->matcher = SmithWatermanMatcher::new(
            $this->profile,
            maxCandidateLength: $this->maxCandidateLength,
            requireFullQuery: $this->strictMode,
        );
    }

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     *
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously). Delegates to the
     * sugar-fuzzy SSOT; case folding is per code point (CharFold: each code
     * point lowercases individually and compares whole-element, keeping the
     * fold 1:1 with the original string), so scores match sugar-treelist's
     * historical whole-string mb_strtolower scorer on every input except the
     * expansion/context-sensitive folds (U+0130, final sigma) where the old
     * byte-for-byte match desynced match indices — there the deviation is
     * intentional; see sugar-fuzzy's CharFold contract.
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
     * Only returns candidates scoring at least $minScore (the default 1 is
     * the historical > 0 contract — scores are integers). Equal scores keep
     * input order (sugar-treelist's contract, distinct from the SSOT's
     * matchAll() haystack-ascending tiebreak); the position is carried
     * through the sort because PHP's usort is unstable. Zero/negative
     * $minScore never admits a zero-score candidate: the alignment floor
     * keeps unaligned items out of the result set.
     *
     * Scoring runs through the SSOT batch door, so its ceiling prune fires:
     * a candidate whose best attainable score cannot reach $minScore is
     * skipped without paying for the quadratic alignment. Duplicate strings
     * collapse to one score lookup (score is a pure function of the pair).
     *
     * @param string              $query     The search query
     * @param list<\Stringable>   $items     List of Stringable items to score
     * @param int                 $minScore  Minimum score to keep (default 1)
     * @param int|null            $limit     Maximum number of results (null = unlimited)
     * @return list<array{\Stringable, int}> List of [item, score] pairs sorted by score desc
     * @throws \InvalidArgumentException If $limit is negative
     */
    public function match(string $query, array $items, int $minScore = 1, ?int $limit = null): array
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Result limit must be >= 0.');
        }
        if ($query === '' || $items === []) {
            return [];
        }

        $strings = [];
        foreach ($items as $item) {
            $strings[] = (string) $item;
        }

        $scoreByString = [];
        foreach ($this->matcher->matchAll($query, $strings, null, $minScore) as $result) {
            $scoreByString[$result->haystack] = $result->score;
        }

        $hits = [];
        foreach ($items as $index => $item) {
            $score = $scoreByString[(string) $item] ?? null;
            if ($score !== null) {
                $hits[] = [$item, $score, $index];
            }
        }

        usort($hits, static fn(array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);

        if ($limit !== null && count($hits) > $limit) {
            $hits = \array_slice($hits, 0, $limit);
        }

        return array_map(
            static fn(array $hit): array => [$hit[0], $hit[1]],
            $hits,
        );
    }

    /**
     * Streaming form of {@see self::match()} — yields the same ranked pairs.
     *
     * Ranking is a global property, so the generator drains the full scoring
     * pass before its first yield (the SSOT's matchAllGenerator() carries the
     * same limitation and identical output); the generator form is for
     * consuming large ranked results without materialising the whole list.
     *
     * @param string            $query
     * @param list<\Stringable> $items
     * @return \Generator<array{\Stringable, int}>
     */
    public function matchGenerator(string $query, array $items, int $minScore = 1, ?int $limit = null): \Generator
    {
        foreach ($this->match($query, $items, $minScore, $limit) as $hit) {
            yield $hit;
        }
    }
}
