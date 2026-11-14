<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmithWatermanMatcher::class)]
#[CoversClass(SahilmMatcher::class)]
final class PerformanceTest extends TestCase
{
    private SmithWatermanMatcher $sw;
    private SahilmMatcher $sm;

    protected function setUp(): void
    {
        $this->sw = new SmithWatermanMatcher();
        $this->sm = new SahilmMatcher();
    }

    #[Test]
    public function testSmithWatermanLongCandidateCompletesInReasonableTime(): void
    {
        // ~2000 char candidate; O(n²) matrix becomes ~4M cells.
        // mb_str_split pre-split (Step 2) eliminates the O(n³) mb_substr scan.
        // Raise the DoS cap above the input length so the whole candidate is
        // aligned (the default 1000-char cap would search only its prefix).
        $matcher = new SmithWatermanMatcher(maxCandidateLength: 5000);
        $longCandidate = str_repeat('abcdefghij', 200);
        $query = 'app';

        $start = microtime(true);
        $result = $matcher->match($query, $longCandidate);
        $elapsed = microtime(true) - $start;

        // Smoke test: result must be non-null and have valid indices
        $this->assertNotNull($result, 'Long candidate should produce a match');
        $this->assertNotEmpty($result->indices());
        $this->assertGreaterThan(0, $result->score);

        // Tripwire (fenced, FUZZY-10): suspect O(n³) mb_substr re-introduction.
        $this->assertWithinPerfBudget($elapsed, 'SmithWaterman match on 2000-char string');
    }

    #[Test]
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

        // Tripwire (fenced, FUZZY-10).
        $this->assertWithinPerfBudget($elapsed, 'SahilmMatcher match on 2000-char string');
    }

    #[Test]
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

        // Tripwire (fenced, FUZZY-10): ~2000 calls should finish fast with pre-split.
        $this->assertWithinPerfBudget($elapsed, 'SmithWaterman matchAll on 2000 candidates');
    }

    #[Test]
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

        // Tripwire (fenced, FUZZY-10).
        $this->assertWithinPerfBudget($elapsed, 'SahilmMatcher matchAll on 2000 candidates');
    }


    /**
     * FUZZY-10 (finding sugar-fuzzy-028): wall-clock tripwires under an opt-in
     * fence. The 2.0s budget is headroom against algorithmic blow-up (mb_substr
     * re-introduction, O(n^3) regressions), NOT signal we want raising on shared
     * CI — the K=24 parallel battery on loaded runners can spike past 2.0s with
     * zero code change (flake by construction). Default: timing legs run, the
     * budget is not asserted, and the functional completion asserts in each
     * test stay unconditional. Set SUGARFUZZY_PERF_BUDGET=<seconds> to arm the
     * tripwire (perf-watch runs); a real regression then still goes red.
     */
    private function assertWithinPerfBudget(float $elapsed, string $context): void
    {
        $budget = getenv('SUGARFUZZY_PERF_BUDGET');

        if ($budget === false || $budget === '' || !is_numeric($budget)) {
            return;
        }

        $this->assertLessThan((float) $budget, $elapsed, sprintf(
            '%s took %.3fs — over the armed %ss budget',
            $context,
            $elapsed,
            $budget
        ));
    }
}
