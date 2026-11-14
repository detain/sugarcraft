<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Fuzzy substring matcher — implementations score via Smith-Waterman local
 * alignment ({@see \SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher}) or greedy
 * first-occurrence Sahilm matching ({@see \SugarCraft\Fuzzy\Matcher\SahilmMatcher}).
 *
 * Scores character matches (higher for consecutive matches), penalizes
 * gaps and mismatches, and returns candidates sorted by score descending.
 * The key feature: ranked matches WITH scored matched character indices,
 * so UI filter highlighting becomes possible.
 *
 * Mirrors the `Match` (String + Score + MatchedIndexes) shape of the upstream
 * `sahilm/fuzzy` (Go) recorded in docs/MATCHUPS.md, combined with the internal
 * Smith-Waterman port; used by bubble tea-style filter models. (FUZZY-14 /
 * finding sugar-fuzzy-025: this citation once named `Candidate` — a
 * charmbracelet-side type the upstream never exports from sahilm/fuzzy; the
 * Go library's own symbols are `Match`/`Matches`.)
 *
 * MATCHERS ARE NOT INTERCHANGEABLE (finding sugar-fuzzy-015): SahilmMatcher is
 * linear-time, uncapped, and full-query by construction — every query
 * character must appear in the candidate. SmithWatermanMatcher performs local
 * alignment with DoS caps (query 128 / candidate 1000 by default, both
 * constructor-tunable) and happily returns PARTIAL alignments that score the
 * best contiguous-ish subsequence. Swapping one for the other behind this
 * interface changes what a filter box lists: partial-query hits become null on
 * the Sahilm side, and long-candidate hits become invisible past the
 * Smith-Waterman caps. Choose deliberately; see the README's two algorithm
 * sections.
 *
 * @see https://github.com/sahilm/fuzzy
 * @see https://github.com/charmbracelet/bubbletea
 */
interface FuzzyMatcher
{
    /**
     * Match a single candidate against the query.
     *
     * An EMPTY QUERY MATCHES NOTHING: `match('', $anything)` returns null.
     * Filter UIs that want "show all on empty input" must guard the empty
     * query themselves before calling in (finding sugar-fuzzy-007; the
     * complementary canonical shape for non-empty filtering is
     * {@see Matcher\SmithWatermanMatcher::forFiltering()}).
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score (haystack)
     * @return MatchResult|null MatchResult with score + indices, or null if no match
     */
    public function match(string $query, string $candidate): ?MatchResult;

    /**
     * Match a query against an iterable of candidates, returning ranked results.
     *
     * Results are sorted by score descending, then by haystack (candidate)
     * ascending as tiebreak.
     * Only returns candidates with a score >= $minScore (default 1, i.e. > 0).
     * An EMPTY QUERY MATCHES NOTHING: `matchAll('', ...)` returns [].
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (`null` or a negative value = unlimited; see {@see MatchResultSorter::sortAndSlice()})
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return array<MatchResult> Ranked match results
     */
    public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array;

    /**
     * Match a query against an iterable of candidates, yielding ranked results.
     *
     * LAZINESS LIMITATION: ranking is a global property — the top result is
     * unknowable until every candidate has been scored — so implementations
     * MUST drain and sort the whole input before the first `yield`. The
     * generator form exists for streaming consumption of the ranked results
     * and for API symmetry with {@see self::matchAll()}, not for
     * per-candidate laziness; callers wanting true unranked streaming should
     * iterate their own list and call {@see self::match()} directly. Output
     * is identical to {@see self::matchAll()}.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (`null` or a negative value = unlimited; see {@see MatchResultSorter::sortAndSlice()})
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return \Generator<MatchResult> Yields MatchResult in ranked order
     */
    public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator;
}
