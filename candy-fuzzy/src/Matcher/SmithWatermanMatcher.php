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
 * Canonical SugarCraft fuzzy matcher. With the canonical {@see ScoringProfile}
 * (or none) it is bit-equivalent in score and ranking to the historical
 * `SugarCraft\Forms\Fuzzy\FuzzyMatcher` implementation; the scoring can be
 * retuned by injecting a strict/lenient/custom profile.
 *
 * Two scoring paths share one recurrence ({@see self::align()}):
 *  - {@see self::score()} — two-row DP, returns the integer score only. Use it
 *    for ranking/filtering when matched indices are not needed.
 *  - {@see self::match()} — the same two-row DP plus a compact traceback
 *    (one byte per cell), additionally yielding the matched character indices
 *    used for highlighting.
 *
 * LOCAL ALIGNMENT: by default a candidate matches as soon as ANY run of the
 * query aligns with it — `match('bxz', 'abcdef')` scores 3 on the "b" alone.
 * That is the algorithm, not a defect, but a type-to-filter picker usually
 * wants every typed character to land: enable {@see self::withRequireFullQuery()}
 * (or pass `requireFullQuery: true`) to keep exactly the candidates that contain
 * the query as an in-order subsequence. A survivor reports an alignment that
 * covers EVERY query character — spread-out matches included (`gst` matches
 * `git status` at [0, 4, 5], even though plain mode's best run is just "st").
 *
 * DoS note: an alignment costs O(queryLen × candidateLen) time and a
 * traceback of the same many bytes. To bound worst-case cost on adversarial
 * input, only the first {@see self::maxQueryLength()} query characters and the
 * first {@see self::maxCandidateLength()} candidate characters (defaults
 * {@see self::DEFAULT_MAX_QUERY_LENGTH} / {@see self::DEFAULT_MAX_CANDIDATE_LENGTH})
 * take part in the alignment. Over-cap input is TRUNCATED, never handed to a
 * different algorithm, so the match contract and score scale are identical on
 * both sides of the cap and the returned indices stay valid in the ORIGINAL
 * haystack (they all fall inside the aligned prefix). The consequence callers
 * must know: text past the candidate cap is not searched, so a candidate whose
 * only alignment lies beyond it does not match — growing a candidate at its
 * END never drops a match, but content that exists only past the cap is
 * invisible. Under {@see self::withRequireFullQuery()} a query longer than the
 * query cap can never fully align and therefore matches nothing.
 *
 * @implements FuzzyMatcher
 */
final class SmithWatermanMatcher implements FuzzyMatcher
{
    /**
     * Default query cap. A fuzzy query is typed text; 128 characters is far past
     * any real filter input, while a pasted paragraph would otherwise cost one
     * alignment row per character, per candidate, on every keystroke.
     */
    public const DEFAULT_MAX_QUERY_LENGTH = 128;

    /** Default candidate cap — the prefix of each candidate that is searched. */
    public const DEFAULT_MAX_CANDIDATE_LENGTH = 1000;

    private readonly ScoringProfile $profile;

    /**
     * @param ScoringProfile|null $profile            Scoring weights (default: {@see ScoringProfile::canonical()})
     * @param int                 $maxQueryLength     Query characters that take part in the alignment (DoS cap, >= 1)
     * @param int                 $maxCandidateLength Candidate characters that take part in the alignment (DoS cap, >= 1)
     * @param bool                $requireFullQuery   Keep only candidates containing the query in order, scored by
     *                                                the best alignment that covers every query char
     * @throws \InvalidArgumentException If a cap is below 1
     */
    public function __construct(
        ?ScoringProfile $profile = null,
        private readonly int $maxQueryLength = self::DEFAULT_MAX_QUERY_LENGTH,
        private readonly int $maxCandidateLength = self::DEFAULT_MAX_CANDIDATE_LENGTH,
        private readonly bool $requireFullQuery = false,
    ) {
        if ($this->maxQueryLength < 1 || $this->maxCandidateLength < 1) {
            throw new \InvalidArgumentException('Length caps must be >= 1');
        }

        $this->profile = $profile ?? ScoringProfile::canonical();
    }

    /**
     * Named constructor (repo convention). Accepts every constructor option,
     * with the same defaults, so callers never have to drop to `new self(...)`
     * to tune the DoS caps or the full-query mode.
     *
     * @throws \InvalidArgumentException If a cap is below 1
     */
    public static function new(
        ?ScoringProfile $profile = null,
        int $maxQueryLength = self::DEFAULT_MAX_QUERY_LENGTH,
        int $maxCandidateLength = self::DEFAULT_MAX_CANDIDATE_LENGTH,
        bool $requireFullQuery = false,
    ): self {
        return new self($profile, $maxQueryLength, $maxCandidateLength, $requireFullQuery);
    }

    /**
     * The scoring profile in effect.
     */
    public function profile(): ScoringProfile
    {
        return $this->profile;
    }

    /** Query characters that take part in the alignment. */
    public function maxQueryLength(): int
    {
        return $this->maxQueryLength;
    }

    /** Candidate characters (a prefix) that take part in the alignment. */
    public function maxCandidateLength(): int
    {
        return $this->maxCandidateLength;
    }

    /** Whether only alignments covering every query character count as matches. */
    public function requiresFullQuery(): bool
    {
        return $this->requireFullQuery;
    }

    /**
     * Copy of this matcher with full-query coverage required (or not).
     *
     * When enabled, {@see self::match()}, {@see self::matchAll()},
     * {@see self::matchAllGenerator()} and {@see self::score()} keep a
     * candidate iff the query is an in-order subsequence of it (case-folded,
     * within the caps) — `bxz` no longer "matches" `abcdef` on its `b`, while
     * `gst` still matches `git status`. Every survivor reports one matched
     * index per query character:
     *  - when plain mode's best local alignment already covers the whole
     *    query, the result (score and indices) is identical to plain mode;
     *  - otherwise a shorter adjacent run outscored the spread-out covering
     *    path, and the result is the best alignment FORCED to cover the query
     *    (same step weights, no restart inside the path, so every skipped
     *    candidate char costs gapExtend as on any unclamped align() path),
     *    exact (the highest-scoring in-order placement), floored at score 1
     *    so a gap-heavy in-order match ranks last instead of vanishing.
     */
    public function withRequireFullQuery(bool $require = true): self
    {
        return new self($this->profile, $this->maxQueryLength, $this->maxCandidateLength, $require);
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
     * Score a candidate WITHOUT building the traceback.
     *
     * Two-row Smith-Waterman: computes the alignment score only, skipping the
     * traceback bookkeeping that {@see self::match()} needs for matched indices.
     * Returns the identical score to `match($query, $candidate)?->score ?? 0`
     * for the same matcher — use it for ranking/filtering when highlighting
     * indices are not required. Under {@see self::withRequireFullQuery()} the
     * coverage check needs the traceback, so this delegates to `match()`.
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

        if ($this->requireFullQuery) {
            return $this->match($query, $candidate)?->score ?? 0;
        }

        $prepared = $this->prepare($query, $candidate);
        if ($prepared === null) {
            return 0;
        }

        return $this->align($prepared[0], $prepared[1], false)[0];
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
     * With the canonical profile, bit-equivalent in score and ranking to the
     * historical `SugarCraft\Forms\Fuzzy\FuzzyMatcher`. Uses full Smith-Waterman
     * local alignment with traceback for matched indices.
     *
     * @param int|null $minScore When provided, prune candidates whose maximum
     *                           attainable score cannot reach the threshold —
     *                           avoids running the quadratic alignment for a
     *                           candidate that would be filtered out anyway.
     *                           Result-preserving: the ceiling only prunes when
     *                           it provably dominates every alignment step
     *                           (see {@see self::ceilingPruneIsSound()});
     *                           profiles whose shape breaks that bound skip
     *                           the prune and pay full alignment cost instead.
     */
    private function compute(string $query, string $candidate, ?int $minScore = null): ?MatchResult
    {
        $prepared = $this->prepare($query, $candidate);
        if ($prepared === null) {
            return null;
        }
        [$q, $c] = $prepared;

        // Early-exit prune: the best possible local-alignment score is at most
        // min(queryLen, candidateLen) match/adjacency steps. If even that ceiling
        // is below the caller's threshold, this candidate cannot qualify — skip
        // the alignment entirely. (Never removes a real match: actual <= ceiling,
        // but only under the sign conditions ceilingPruneIsSound() checks.)
        if ($minScore !== null && $this->ceilingPruneIsSound()) {
            $perStepCeiling = max(0, $this->profile->matchScore + $this->profile->adjacentBonus);
            $ceiling = min(count($q), count($c)) * $perStepCeiling;
            // A full-coverage match is floored at 1 (see alignFullQuery()).
            if ($this->requireFullQuery) {
                $ceiling = max(1, $ceiling);
            }
            if ($ceiling < $minScore) {
                return null;
            }
        }

        [$maxScore, $maxI, $maxJ, $trace] = $this->align($q, $c, true);

        $indices = $maxScore > 0 ? $this->traceback($trace, $q, $c, $maxI, $maxJ) : [];

        // Full-query mode: every query char must land. prepare() already
        // refused an over-cap query and any pair where the query is not an
        // in-order subsequence of the candidate, so $q is the WHOLE query and
        // a covering alignment exists. When the unconstrained best already
        // covers it, keep it untouched (scores identical to plain mode);
        // otherwise a shorter adjacent run outscored the spread-out covering
        // path ('gst' in 'git status' picks "st") — find the best covering
        // alignment instead of dropping an in-order match.
        if ($this->requireFullQuery && count($indices) !== count($q)) {
            [$maxScore, $indices] = $this->alignFullQuery($q, $c);
        }

        if ($maxScore <= 0) {
            return null;
        }

        return new MatchResult(
            needle: $query,
            haystack: $candidate,
            score: $maxScore,
            matchedIndices: $indices,
        );
    }

    /**
     * Fold, cap and pre-screen one query/candidate pair.
     *
     * Returns the folded per-code-point arrays — 1:1 with ORIGINAL code points,
     * so every alignment index names the same char the caller sees (whole-string
     * lowercasing expands U+0130 and desyncs the index space; see CharFold) —
     * truncated to the DoS caps, or null when the pair provably cannot match.
     *
     * @return array{0: list<string>, 1: list<string>}|null
     */
    private function prepare(string $query, string $candidate): ?array
    {
        $queryLen = mb_strlen($query, 'UTF-8');
        $candidateLen = mb_strlen($candidate, 'UTF-8');

        if ($queryLen === 0 || $candidateLen === 0) {
            return null;
        }

        // Under full-query mode an over-cap query can never be fully covered.
        if ($this->requireFullQuery && $queryLen > $this->maxQueryLength) {
            return null;
        }

        // DoS cap: truncate rather than switch algorithms (see class note).
        // A prefix keeps every reported index valid in the original haystack.
        if ($queryLen > $this->maxQueryLength) {
            $query = mb_substr($query, 0, $this->maxQueryLength, 'UTF-8');
        }
        if ($candidateLen > $this->maxCandidateLength) {
            $candidate = mb_substr($candidate, 0, $this->maxCandidateLength, 'UTF-8');
        }

        $q = CharFold::foldSplit($query);
        $c = CharFold::foldSplit($candidate);

        // Every matched index is a distinct candidate column, so a query longer
        // than the searched candidate prefix can never be fully covered.
        if ($this->requireFullQuery && count($q) > count($c)) {
            return null;
        }

        // Linear pre-screens before the quadratic alignment. Full-query mode
        // needs the query to be an in-order subsequence of the candidate —
        // exactly the pairs a covering alignment exists for, under ANY profile
        // (coverage counts only genuine char matches). Otherwise, when no step
        // type other than a char match can be positive, a pair sharing no
        // folded char scores 0 everywhere.
        if ($this->requireFullQuery) {
            if (!self::isSubsequence($q, $c)) {
                return null;
            }
        } elseif ($this->noMatchMeansZero()) {
            if (array_intersect_key(array_flip($q), array_flip($c)) === []) {
                return null;
            }
        }

        return [$q, $c];
    }

    /**
     * The shared Smith-Waterman recurrence.
     *
     * Keeps only two score rows (O(candidateLen) memory). With `$withTraceback`
     * it also records each cell's origin as one byte per cell — '0' init,
     * '1' diag, '2' up, '3' left — one string per row, instead of the nested
     * PHP int matrices this used to allocate (~40 bytes per cell). Tie-break
     * (diag > up > left) and first-maximum selection are unchanged, so scores
     * and indices are bit-equivalent to the historical full-matrix version.
     *
     * @param list<string> $q Folded query
     * @param list<string> $c Folded candidate
     * @return array{0: int, 1: int, 2: int, 3: list<string>} [maxScore, maxI, maxJ, traceRows]
     */
    private function align(array $q, array $c, bool $withTraceback): array
    {
        $queryLen = count($q);
        $candidateLen = count($c);

        $matchScore = $this->profile->matchScore;
        $mismatchPenalty = $this->profile->mismatchPenalty;
        $gapOpen = $this->profile->gapOpen;
        $gapExtend = $this->profile->gapExtend;
        $adjacentBonus = $this->profile->adjacentBonus;

        // Score rows are 0-based by candidate char: $prevRow[$k] is column $k + 1
        // (column 0 is the implicit all-zero border).
        $prevRow = array_fill(0, $candidateLen, 0);
        $trace = $withTraceback ? [str_repeat('0', $candidateLen + 1)] : [];

        $maxScore = 0;
        $maxI = 0;
        $maxJ = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            $qChar = $q[$i - 1];
            // Sentinels false/true never equal each other or a folded char, so the
            // first row and first column get no adjacency bonus without a branch.
            $qPrev = $i > 1 ? $q[$i - 2] : false;
            $cPrev = true;
            $currRow = [];
            $traceRow = '0';
            $left = 0; // current row, previous column
            $diagBase = 0; // previous row, previous column

            foreach ($c as $k => $cChar) {
                $match = $qChar === $cChar ? $matchScore : $mismatchPenalty;

                // Adjacent bonus for a consecutive in-sequence match.
                if ($match > 0 && $qPrev === $cPrev) {
                    $match += $adjacentBonus;
                }

                $above = $prevRow[$k];
                $cell = $diagBase + $match;
                $origin = '1';
                $scoreUp = $left + ($left === 0 ? $gapOpen : $gapExtend);
                if ($scoreUp > $cell) {
                    $cell = $scoreUp;
                    $origin = '2';
                }
                $scoreLeft = $above + ($above === 0 ? $gapOpen : $gapExtend);
                if ($scoreLeft > $cell) {
                    $cell = $scoreLeft;
                    $origin = '3';
                }
                if ($cell <= 0) {
                    $cell = 0;
                    $origin = '0';
                }

                $currRow[] = $cell;
                $left = $cell;
                $diagBase = $above;
                $cPrev = $cChar;
                if ($withTraceback) {
                    $traceRow .= $origin;
                }

                if ($cell > $maxScore) {
                    $maxScore = $cell;
                    $maxI = $i;
                    $maxJ = $k + 1;
                }
            }

            $prevRow = $currRow;
            if ($withTraceback) {
                $trace[] = $traceRow;
            }
        }

        return [$maxScore, $maxI, $maxJ, $trace];
    }

    /**
     * Best alignment forced to cover EVERY query character (full-query mode).
     *
     * Semi-global variant of {@see self::align()}: local on the candidate
     * (the path may start and end at any column) but global on the query —
     * every query row is consumed by a diagonal step onto an EQUAL folded
     * char, in order, so a query-char gap or a mismatch diagonal is not a
     * move here. The adjacency bonus uses align()'s rule. Every candidate
     * char skipped between two matches costs gapExtend — what align() charges
     * any skip on a path it did not clamp, because a 0 cell in align() IS
     * the clamped "nothing aligned yet" state and gapOpen is only ever paid
     * leaving it. Here the path is never in that state after its first
     * match, so a running total that merely sums to 0 mid-path is not a
     * restart and must not pay gapOpen. That also keeps every step monotone
     * in the predecessor's value, which is what makes keeping only the
     * per-cell maximum exact: borrowing align()'s value-dependent
     * `=== 0 ? gapOpen : gapExtend` test let a 0-valued predecessor beat a
     * slightly negative one that paid less for the next skip, so the DP
     * returned a sub-optimal covering path. A covering path therefore scores
     * here exactly what align() gives it wherever align() did not clamp it.
     * The path runs from its first matched query char to its last; nothing
     * before or after is scored (skips are only taken between matches).
     * There is no zero clamp inside the path: clamping would restart the
     * alignment and drop the query chars before it. Tie-break: diagonal over
     * skip; first maximum (leftmost end column).
     *
     * The score is floored at 1: a covering path whose gaps cost more than its
     * matches earn is still an in-order match of every typed char, so it is
     * listed — ranked below every positively-scored candidate — instead of
     * vanishing.
     *
     * Callers guarantee $q is a subsequence of $c (see {@see self::prepare()}),
     * so a covering path exists.
     *
     * @param list<string> $q Folded query (whole, under the query cap)
     * @param list<string> $c Folded candidate prefix
     * @return array{0: int, 1: list<int>} [score >= 1, matched indices (one per query char)]
     */
    private function alignFullQuery(array $q, array $c): array
    {
        $queryLen = count($q);

        $matchScore = $this->profile->matchScore;
        $gapExtend = $this->profile->gapExtend;
        $adjacentBonus = $this->profile->adjacentBonus;

        // null = no covering path of the first $i query chars ends here.
        $prevRow = [];
        $trace = [str_repeat('0', count($c) + 1)];

        for ($i = 1; $i <= $queryLen; $i++) {
            $qChar = $q[$i - 1];
            $qPrev = $i > 1 ? $q[$i - 2] : false;
            $cPrev = true;
            $currRow = [];
            $traceRow = '0';
            $left = null;
            // Row 1 may start at any column: its diagonal base is always 0.
            $diagBase = $i === 1 ? 0 : null;

            foreach ($c as $k => $cChar) {
                $cell = null;
                $origin = '0';

                if ($diagBase !== null && $qChar === $cChar) {
                    $step = $matchScore;
                    if ($step > 0 && $qPrev === $cPrev) {
                        $step += $adjacentBonus;
                    }
                    $cell = $diagBase + $step;
                    $origin = '1';
                }
                // The last row takes no skip: a covering path ends ON its last
                // matched query char, so trailing candidate chars are never
                // charged (or, under a positive gapExtend, credited).
                if ($left !== null && $i < $queryLen) {
                    $skip = $left + $gapExtend;
                    if ($cell === null || $skip > $cell) {
                        $cell = $skip;
                        $origin = '2';
                    }
                }

                $currRow[] = $cell;
                $traceRow .= $origin;
                $left = $cell;
                $diagBase = $i === 1 ? 0 : $prevRow[$k];
                $cPrev = $cChar;
            }

            $prevRow = $currRow;
            $trace[] = $traceRow;
        }

        $best = null;
        $bestJ = 0;
        foreach ($prevRow as $k => $cell) {
            if ($cell !== null && ($best === null || $cell > $best)) {
                $best = $cell;
                $bestJ = $k + 1;
            }
        }

        if ($best === null) {
            // Unreachable when the caller honoured the subsequence contract.
            throw new \LogicException('alignFullQuery() requires the query to be a subsequence of the candidate');
        }

        return [max(1, $best), $this->traceback($trace, $q, $c, $queryLen, $bestJ)];
    }

    /**
     * Whether $q occurs in $c in order (greedy scan, O(len)).
     *
     * @param list<string> $q
     * @param list<string> $c
     */
    private static function isSubsequence(array $q, array $c): bool
    {
        $queryLen = count($q);
        $i = 0;
        foreach ($c as $cChar) {
            if ($cChar === $q[$i] && ++$i === $queryLen) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a pair with no equal folded char provably scores 0: no step but
     * a genuine char match may be positive (adjacency only fires on a positive
     * base step, so it cannot lift a mismatch here).
     */
    private function noMatchMeansZero(): bool
    {
        return $this->profile->mismatchPenalty <= 0
            && $this->profile->gapOpen <= 0
            && $this->profile->gapExtend <= 0;
    }

    /**
     * Whether the early-exit ceiling `min(len) * max(0, matchScore + adjacentBonus)`
     * provably dominates every alignment step.
     *
     * It does when adjacency can only ADD (adjacentBonus >= 0) and every other
     * step type is non-positive (mismatch/gap penalties <= 0): then any path of
     * k <= min(queryLen, candidateLen) diagonal steps scores at most
     * k * max(0, matchScore + adjacentBonus). Any other public profile shape
     * breaks the bound — e.g. matchScore 3 with adjacentBonus -5 makes the best
     * step a BARE match (3 > 3-5), so the naive ceiling would collapse and
     * prune real matches. Unsound shapes skip the prune; correctness over the
     * matrix-allocation shortcut.
     */
    private function ceilingPruneIsSound(): bool
    {
        return $this->profile->adjacentBonus >= 0
            && $this->profile->mismatchPenalty <= 0
            && $this->profile->gapOpen <= 0
            && $this->profile->gapExtend <= 0;
    }

    /**
     * Traceback from max score position to get matched character indices.
     *
     * Emits an index only for diagonal steps whose chars genuinely match.
     * Under profiles where a mismatch diagonal can win a cell (e.g.
     * mismatchPenalty >= 0), the alignment PATH may cross such a step — the
     * score keeps counting it, but highlighting a char the query never asked
     * for would lie, so matchedIndices become a strict subset of the path
     * there. Canonical profiles (negative mismatch) provably never hit this.
     *
     * @param list<string>    $traceback Origin rows from {@see self::align()} (one byte per cell)
     * @param list<string>      $q         Folded query (1:1 with query code points)
     * @param list<string>      $c         Folded candidate (1:1 with haystack code points)
     * @param int             $i          Row of max score
     * @param int             $j          Column of max score
     * @return list<int> Character indices of matched chars, in ORIGINAL haystack space
     */
    private function traceback(array $traceback, array $q, array $c, int $i, int $j): array
    {
        $indices = [];
        $currentI = $i;
        $currentJ = $j;

        while ($currentI > 0 && $currentJ > 0 && $traceback[$currentI][$currentJ] !== '0') {
            $origin = $traceback[$currentI][$currentJ];

            if ($origin === '1') {
                // Diagonal — a real char match at ($currentI-1, $currentJ-1) of the
                // ORIGINAL strings (folded arrays are 1:1 with code points).
                if ($q[$currentI - 1] === $c[$currentJ - 1]) {
                    $indices[] = $currentJ - 1;
                }
                $currentI--;
                $currentJ--;
            } elseif ($origin === '2') {
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
