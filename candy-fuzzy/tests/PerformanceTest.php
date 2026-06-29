<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Performance regression guard for the hot-loop mb_substr/O(n²-n³) issue.
 *
 * These are NOT benchmarks — they are loose tripwires that fail hard if
 * the per-character mb_substr pattern re-emerges in the matchers.  The
 * thresholds are generous enough to never flake on slow CI runners.
 *
 * Mirrors the finding: "Repeated mb_substr/mb_strtolower inside the
 * Smith-Waterman/Sahilm matrix loop — High".
 */
final class PerformanceTest extends TestCase
{
    private SmithWatermanMatcher $sw;
    private SahilmMatcher $sm;

    protected function setUp(): void
    {
        $this->sw = new SmithWatermanMatcher();
        $this->sm = new SahilmMatcher();
    }

    public function testSmithWatermanLongCandidateCompletesInReasonableTime(): void
    {
        // ~2000 char candidate; O(n²) matrix becomes ~4M cells.
        // mb_str_split pre-split (Step 2) eliminates the O(n³) mb_substr scan.
        $longCandidate = str_repeat('abcdefghij', 200);
        $query = 'app';

        $start = microtime(true);
        $result = $this->sw->match($query, $longCandidate);
        $elapsed = microtime(true) - $start;

        // Smoke test: result must be non-null and have valid indices
        $this->assertNotNull($result, 'Long candidate should produce a match');
        $this->assertNotEmpty($result->indices());
        $this->assertGreaterThan(0, $result->score);

        // Tripwire: should complete in well under 2 seconds even on slow CI.
        // If this fails, suspect O(n³) mb_substr re-introduction in the hot loop.
        $this->assertLessThan(2.0, $elapsed, sprintf(
            'SmithWaterman match on 2000-char string took %.3fs — possible mb_substr regression',
            $elapsed
        ));
    }

    public function testSahilmMatcherLongCandidateCompletesInReasonableTime(): void
    {
        // ~2000 char candidate; O(n) single pass with mb_substr would be O(n²).
        // mb_str_split pre-split (Step 3) makes it O(n).
        $longCandidate = str_repeat('abcdefghij', 200);
        // Use a query that actually appears in the repeating pattern
        $query = 'abcd';

        $start = microtime(true);
        $result = $this->sm->match($query, $longCandidate);
        $elapsed = microtime(true) - $start;

        // Smoke test: result must be non-null and have valid indices
        $this->assertNotNull($result, 'Long candidate should produce a match');
        $this->assertNotEmpty($result->indices());
        $this->assertGreaterThan(0, $result->score);

        // Tripwire: should complete in well under 2 seconds even on slow CI.
        $this->assertLessThan(2.0, $elapsed, sprintf(
            'SahilmMatcher match on 2000-char string took %.3fs — possible mb_substr regression',
            $elapsed
        ));
    }

    public function testSmithWatermanMatchAllOnLargeListCompletesInReasonableTime(): void
    {
        // ~2000 medium candidates; matchAll calls compute() for each.
        // With the pre-split optimization, each call is O(queryLen * candidateLen)
        // instead of O(queryLen * candidateLen * avgCharScan).
        $candidates = [];
        for ($i = 0; $i < 2000; $i++) {
            $candidates[] = "item_{$i}_description";
        }
        $query = 'app';

        $start = microtime(true);
        $results = $this->sw->matchAll($query, $candidates);
        $elapsed = microtime(true) - $start;

        // Results should be non-empty and sorted
        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            $this->assertNotEmpty($result->indices());
        }

        // Tripwire: ~2000 calls with O(n²) each should finish in < 2s with pre-split.
        $this->assertLessThan(2.0, $elapsed, sprintf(
            'SmithWaterman matchAll on 2000 candidates took %.3fs — possible regression',
            $elapsed
        ));
    }

    public function testSahilmMatcherMatchAllOnLargeListCompletesInReasonableTime(): void
    {
        // ~2000 medium candidates; Sahilm is O(queryLen + candidateLen) per candidate.
        $candidates = [];
        for ($i = 0; $i < 2000; $i++) {
            $candidates[] = "item_{$i}_description";
        }
        // Use 'em' which appears in 'item_' and 'description' for many matches
        $query = 'em';

        $start = microtime(true);
        $results = $this->sm->matchAll($query, $candidates);
        $elapsed = microtime(true) - $start;

        // Results should be non-empty and sorted
        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            $this->assertNotEmpty($result->indices());
        }

        // Tripwire: should be very fast with pre-split.
        $this->assertLessThan(2.0, $elapsed, sprintf(
            'SahilmMatcher matchAll on 2000 candidates took %.3fs — possible regression',
            $elapsed
        ));
    }
}