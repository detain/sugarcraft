<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;
use SugarCraft\Fuzzy\ScoringProfile;

/**
 * Smith-Waterman-style local alignment fuzzy matcher.
 *
 * Canonical SugarCraft fuzzy matcher. With the default {@see ScoringProfile}
 * (or none) it is bit-equivalent in score and ranking to the historical
 * `SugarCraft\Forms\Fuzzy\FuzzyMatcher` implementation; the scoring can be
 * retuned by injecting a strict/lenient/custom profile.
 *
 * Two scoring paths share one recurrence:
 *  - {@see self::score()} — two-row DP, returns the integer score only. Use it
 *    for ranking/filtering when matched indices are not needed.
 *  - {@see self::match()} — full (n+1)×(m+1) matrix + traceback, additionally
 *    yielding the matched character indices used for highlighting.
 *
 * DoS note: the full-traceback path allocates an O(queryLen × candidateLen)
 * scoring matrix AND traceback matrix. To bound worst-case memory/time on
 * adversarial input, queries/candidates longer than {@see self::$maxQueryLength}
 * / {@see self::$maxCandidateLength} (default 1000) are delegated to the
 * O(1)-memory {@see SahilmMatcher} instead of building the quadratic matrix.
 * Scores from the Sahilm fallback are on a different scale — mixing sub-cap and
 * over-cap candidates in one {@see self::matchAll()} call can therefore rank
 * inconsistently; the fallback exists purely to keep pathological input safe,
 * not to produce comparable scores.
 *
 * @implements FuzzyMatcher
 */
final class SmithWatermanMatcher implements FuzzyMatcher
{
    private readonly ScoringProfile $profile;

    /** Lazily constructed over-cap fallback (see class DoS note). */
    private ?SahilmMatcher $fallback = null;

    /**
     * @param ScoringProfile|null $profile           Scoring weights (default: {@see ScoringProfile::default()})
     * @param int                 $maxQueryLength     Max query characters before delegating to SahilmMatcher (DoS cap)
     * @param int                 $maxCandidateLength Max candidate characters before delegating to SahilmMatcher (DoS cap)
     */
    public function __construct(
        ?ScoringProfile $profile = null,
        private readonly int $maxQueryLength = 1000,
        private readonly int $maxCandidateLength = 1000,
    ) {
        if ($this->maxQueryLength < 1 || $this->maxCandidateLength < 1) {
            throw new \InvalidArgumentException('Length caps must be >= 1');
        }

        $this->profile = $profile ?? ScoringProfile::default();
    }

    /**
     * Named constructor (repo convention).
     */
    public static function new(?ScoringProfile $profile = null): self
    {
        return new self($profile);
    }

    /**
     * The scoring profile in effect.
     */
    public function profile(): ScoringProfile
    {
        return $this->profile;
    }

    /**
     * Match a single candidate against the query.
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score (haystack)
     * @return MatchResult|null MatchResult with score + indices, or null if no match
     */
    public function match(string $query, string $candidate): ?MatchResult
    {
        if ($query === '' || $candidate === '') {
            return null;
        }

        $result = $this->compute($query, $candidate);
        if ($result === null || $result->score <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * Score a candidate WITHOUT allocating the traceback matrix.
     *
     * Two-row Smith-Waterman: computes the alignment score only, skipping the
     * O(queryLen × candidateLen) traceback bookkeeping that {@see self::match()}
     * needs for matched indices. Returns the identical score to
     * `match($query, $candidate)?->score ?? 0` for the same profile — use it
     * for ranking/filtering when highlighting indices are not required.
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score (haystack)
     * @return int The alignment score (higher = better match; 0 = no match)
     */
    public function score(string $query, string $candidate): int
    {
        if ($query === '' || $candidate === '') {
            return 0;
        }

        $queryLen = mb_strlen($query, 'UTF-8');
        $candidateLen = mb_strlen($candidate, 'UTF-8');

        // DoS cap: over-length input avoids the quadratic path (see class note).
        if ($queryLen > $this->maxQueryLength || $candidateLen > $this->maxCandidateLength) {
            return $this->fallback()->match($query, $candidate)?->score ?? 0;
        }

        // Pre-split + lowercase once (equivalent to per-char folding) — keeps the
        // hot loop free of mb_substr/mb_strtolower calls.
        $q = mb_str_split(mb_strtolower($query, 'UTF-8'));
        $c = mb_str_split(mb_strtolower($candidate, 'UTF-8'));

        $matchScore = $this->profile->matchScore;
        $mismatchPenalty = $this->profile->mismatchPenalty;
        $gapOpen = $this->profile->gapOpen;
        $gapExtend = $this->profile->gapExtend;
        $adjacentBonus = $this->profile->adjacentBonus;

        // Two rows instead of the full matrix — O(candidateLen) memory.
        $prevRow = array_fill(0, $candidateLen + 1, 0);
        $currRow = array_fill(0, $candidateLen + 1, 0);

        $maxScore = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            for ($j = 1; $j <= $candidateLen; $j++) {
                $match = $q[$i - 1] === $c[$j - 1] ? $matchScore : $mismatchPenalty;

                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1 && $q[$i - 2] === $c[$j - 2]) {
                    $adjBonus = $adjacentBonus;
                }

                $effectiveMatch = $match + $adjBonus;
                $scoreDiag = $prevRow[$j - 1] + $effectiveMatch;
                $scoreUp = $currRow[$j - 1] + ($currRow[$j - 1] === 0 ? $gapOpen : $gapExtend);
                $scoreLeft = $prevRow[$j] + ($prevRow[$j] === 0 ? $gapOpen : $gapExtend);

                $cell = max(0, $scoreDiag, $scoreUp, $scoreLeft);
                $currRow[$j] = $cell;

                if ($cell > $maxScore) {
                    $maxScore = $cell;
                }
            }
            $temp = $prevRow;
            $prevRow = $currRow;
            $currRow = $temp;
        }

        return $maxScore;
    }

    /**
     * Match a query against an iterable of candidates, returning ranked results.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (null = unlimited)
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return array<MatchResult> Ranked match results
     */
    public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
    {
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $result = $this->compute($query, $candidate, $minScore);
            if ($result !== null && $result->score >= $minScore) {
                $results[] = $result;
            }
        }

        return MatchResultSorter::sortAndSlice($results, $limit);
    }

    /**
     * Match a query against an iterable of candidates, yielding ranked results.
     *
     * LAZINESS LIMITATION: this method returns results in ranked order (score
     * desc, then haystack asc), and ranking is a global property — the top
     * result is unknowable until every candidate has been scored. It therefore
     * MUST drain and sort the whole input before the first `yield`; a generator
     * that yielded per-candidate could only emit *input* order, which would
     * change the documented result ordering. The generator form is retained for
     * API symmetry with {@see self::matchAll()} and for streaming consumption of
     * the ranked results; callers wanting true per-candidate streaming (unranked)
     * should iterate their own list and call {@see self::match()} directly.
     *
     * Output is identical to {@see self::matchAll()}.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (null = unlimited)
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return \Generator<MatchResult> Yields MatchResult in ranked order
     */
    public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
    {
        // Ranking barrier: reuse matchAll so the two paths cannot drift.
        foreach ($this->matchAll($query, $candidates, $limit, $minScore) as $result) {
            yield $result;
        }
    }

    /**
     * Compute match result with traceback for matched indices.
     *
     * With the default profile, bit-equivalent in score and ranking to the
     * historical `SugarCraft\Forms\Fuzzy\FuzzyMatcher`. Uses full Smith-Waterman
     * local alignment with traceback for matched indices.
     *
     * @param int|null $minScore When provided, prune candidates whose maximum
     *                           attainable score cannot reach the threshold —
     *                           avoids allocating the quadratic matrix for a
     *                           candidate that would be filtered out anyway.
     *                           Result-preserving (the pruned score is provably
     *                           below the threshold).
     */
    private function compute(string $query, string $candidate, ?int $minScore = null): ?MatchResult
    {
        $queryLen = mb_strlen($query, 'UTF-8');
        $candidateLen = mb_strlen($candidate, 'UTF-8');

        if ($queryLen === 0 || $candidateLen === 0) {
            return null;
        }

        // DoS cap: over-length input avoids the quadratic path (see class note).
        if ($queryLen > $this->maxQueryLength || $candidateLen > $this->maxCandidateLength) {
            return $this->fallback()->match($query, $candidate);
        }

        // Early-exit prune: the best possible local-alignment score is at most
        // min(queryLen, candidateLen) match/adjacency steps. If even that ceiling
        // is below the caller's threshold, this candidate cannot qualify — skip
        // the matrix entirely. (Never removes a real match: actual <= ceiling.)
        if ($minScore !== null) {
            $perStepCeiling = max(0, $this->profile->matchScore + $this->profile->adjacentBonus);
            $ceiling = min($queryLen, $candidateLen) * $perStepCeiling;
            if ($ceiling < $minScore) {
                return null;
            }
        }

        // Pre-split once — lowercasing the whole string once is equivalent to
        // lowercasing each char; eliminates per-cell mb_substr/mb_strtolower in the hot loop.
        $q = mb_str_split(mb_strtolower($query, 'UTF-8'));
        $c = mb_str_split(mb_strtolower($candidate, 'UTF-8'));

        $matchScore = $this->profile->matchScore;
        $mismatchPenalty = $this->profile->mismatchPenalty;
        $gapOpen = $this->profile->gapOpen;
        $gapExtend = $this->profile->gapExtend;
        $adjacentBonus = $this->profile->adjacentBonus;

        // Build full scoring matrix for traceback
        // Matrix is (queryLen+1) x (candidateLen+1), initialized to 0
        $matrix = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));

        // Track where each score came from: 0=init, 1=diag, 2=up, 3=left
        $traceback = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));

        $maxScore = 0;
        $maxI = 0;
        $maxJ = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            for ($j = 1; $j <= $candidateLen; $j++) {
                $match = $q[$i - 1] === $c[$j - 1]
                    ? $matchScore
                    : $mismatchPenalty;

                // Add adjacent bonus for consecutive character matches in sequence
                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1) {
                    if ($q[$i - 2] === $c[$j - 2]) {
                        $adjBonus = $adjacentBonus;
                    }
                }

                $effectiveMatch = $match + $adjBonus;

                $scoreDiag = $matrix[$i - 1][$j - 1] + $effectiveMatch;
                $scoreUp = $matrix[$i][$j - 1] + ($matrix[$i][$j - 1] === 0 ? $gapOpen : $gapExtend);
                $scoreLeft = $matrix[$i - 1][$j] + ($matrix[$i - 1][$j] === 0 ? $gapOpen : $gapExtend);

                $cell = max(0, $scoreDiag, $scoreUp, $scoreLeft);
                $matrix[$i][$j] = $cell;

                // Track origin for traceback
                if ($cell > 0) {
                    if ($cell === $scoreDiag) {
                        $traceback[$i][$j] = 1; // diag
                    } elseif ($cell === $scoreUp) {
                        $traceback[$i][$j] = 2; // up
                    } else {
                        $traceback[$i][$j] = 3; // left
                    }
                }

                if ($cell > $maxScore) {
                    $maxScore = $cell;
                    $maxI = $i;
                    $maxJ = $j;
                }
            }
        }

        if ($maxScore === 0) {
            return null;
        }

        // Traceback to find matched indices
        $indices = $this->traceback($traceback, $matrix, $maxI, $maxJ);

        return new MatchResult(
            needle: $query,
            haystack: $candidate,
            score: $maxScore,
            matchedIndices: $indices,
        );
    }

    /**
     * Over-cap fallback matcher (lazily constructed, DoS-safe O(1) memory).
     */
    private function fallback(): SahilmMatcher
    {
        return $this->fallback ??= new SahilmMatcher();
    }

    /**
     * Traceback from max score position to get matched character indices.
     *
     * @param array<array<int>> $traceback Origin matrix
     * @param array<array<int>> $matrix    Score matrix
     * @param int             $i          Row of max score
     * @param int             $j          Column of max score
     * @return list<int> Character indices of matched chars
     */
    private function traceback(array $traceback, array $matrix, int $i, int $j): array
    {
        $indices = [];
        $currentI = $i;
        $currentJ = $j;

        while ($currentI > 0 && $currentJ > 0 && $traceback[$currentI][$currentJ] !== 0) {
            $origin = $traceback[$currentI][$currentJ];

            if ($origin === 1) {
                // Diagonal - we have a match at position (currentI-1, currentJ-1) in the original strings
                $indices[] = $currentJ - 1;
                $currentI--;
                $currentJ--;
            } elseif ($origin === 2) {
                // Up - gap in query
                $currentJ--;
            } else {
                // Left - gap in candidate
                $currentI--;
            }
        }

        // Indices are collected in reverse order (from end to start of match)
        return array_reverse($indices);
    }
}
