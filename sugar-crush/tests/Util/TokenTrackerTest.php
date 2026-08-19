<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * @see TokenTracker
 */
final class TokenTrackerTest extends TestCase
{
    // =========================================================================
    // Initial State Tests
    // =========================================================================

    public function testInitialStateIsAllZeros(): void
    {
        $tracker = new TokenTracker();

        $this->assertSame(0, $tracker->inputTokens());
        $this->assertSame(0, $tracker->outputTokens());
        $this->assertSame(0, $tracker->totalTokens());
        $this->assertSame(0.0, $tracker->totalCost());
    }

    // =========================================================================
    // addUsage Tests
    // =========================================================================

    public function testAddUsageIncrementsCounters(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(100, 50, 0.0025);

        $this->assertSame(100, $tracker->inputTokens());
        $this->assertSame(50, $tracker->outputTokens());
        $this->assertSame(150, $tracker->totalTokens());
        $this->assertSame(0.0025, $tracker->totalCost());
    }

    public function testAddUsageAccumulatesMultipleCalls(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(100, 50, 0.0025);
        $tracker->addUsage(200, 100, 0.0050);
        $tracker->addUsage(50, 25, 0.0010);

        $this->assertSame(350, $tracker->inputTokens());
        $this->assertSame(175, $tracker->outputTokens());
        $this->assertSame(525, $tracker->totalTokens());
        $this->assertEqualsWithDelta(0.0085, $tracker->totalCost(), 0.0001);
    }

    public function testAddUsageWithZeroValues(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(0, 0, 0.0);

        $this->assertSame(0, $tracker->totalTokens());
        $this->assertSame(0.0, $tracker->totalCost());
    }

    // =========================================================================
    // Accessor Method Tests
    // =========================================================================

    public function testTotalTokensReturnsCombinedCount(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(500, 300, 0.01);

        $this->assertSame(800, $tracker->totalTokens());
    }

    public function testInputTokensReturnsOnlyInputCount(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(500, 300, 0.01);

        $this->assertSame(500, $tracker->inputTokens());
    }

    public function testOutputTokensReturnsOnlyOutputCount(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(500, 300, 0.01);

        $this->assertSame(300, $tracker->outputTokens());
    }

    public function testTotalCostReturnsSumOfCosts(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(100, 50, 0.0025);
        $tracker->addUsage(200, 100, 0.0075);

        $this->assertEqualsWithDelta(0.01, $tracker->totalCost(), 0.0001);
    }

    // =========================================================================
    // reset Tests
    // =========================================================================

    public function testResetClearsAllCounters(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(500, 300, 0.01);
        $tracker->reset();

        $this->assertSame(0, $tracker->inputTokens());
        $this->assertSame(0, $tracker->outputTokens());
        $this->assertSame(0, $tracker->totalTokens());
        $this->assertSame(0.0, $tracker->totalCost());
    }

    public function testResetThenAddUsageWorksCorrectly(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(100, 50, 0.001);
        $tracker->reset();
        $tracker->addUsage(200, 100, 0.002);

        $this->assertSame(200, $tracker->inputTokens());
        $this->assertSame(100, $tracker->outputTokens());
        $this->assertSame(300, $tracker->totalTokens());
        $this->assertEqualsWithDelta(0.002, $tracker->totalCost(), 0.0001);
    }

    // =========================================================================
    // summary Tests
    // =========================================================================

    public function testSummaryReturnsFormattedString(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(100, 50, 0.0025);

        $summary = $tracker->summary();

        $this->assertIsString($summary);
        $this->assertStringContainsString('100', $summary);
        $this->assertStringContainsString('50', $summary);
        $this->assertStringContainsString('0.0025', $summary);
    }

    public function testSummaryShowsCorrectFormat(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(1000, 500, 0.0150);

        $summary = $tracker->summary();

        // Expected format: "Tokens: %d in / %d out | Cost: $%.4f"
        $this->assertMatchesRegularExpression('/Tokens: 1000 in \/ 500 out \| Cost: \$0\.0150/', $summary);
    }

    public function testSummaryWithZeros(): void
    {
        $tracker = new TokenTracker();

        $summary = $tracker->summary();

        $this->assertStringContainsString('0 in', $summary);
        $this->assertStringContainsString('0 out', $summary);
        $this->assertStringContainsString('0.0000', $summary);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testHandlesLargeValues(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(1_000_000, 500_000, 25.00);

        $this->assertSame(1_000_000, $tracker->inputTokens());
        $this->assertSame(500_000, $tracker->outputTokens());
        $this->assertSame(1_500_000, $tracker->totalTokens());
        $this->assertEqualsWithDelta(25.00, $tracker->totalCost(), 0.01);
    }

    public function testHandlesSmallCostValues(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(1, 1, 0.00001);

        $this->assertEqualsWithDelta(0.00001, $tracker->totalCost(), 0.000001);
    }

    public function testMultipleAddUsageCallsWithVaryingCosts(): void
    {
        $tracker = new TokenTracker();

        // Simulate multiple API calls with different costs
        $tracker->addUsage(1500, 500, 0.0200);  // expensive model
        $tracker->addUsage(100, 50, 0.0001);     // cheap model
        $tracker->addUsage(200, 100, 0.0025);    // medium model

        $this->assertSame(1800, $tracker->inputTokens());
        $this->assertSame(650, $tracker->outputTokens());
        $this->assertSame(2450, $tracker->totalTokens());
        $this->assertEqualsWithDelta(0.0226, $tracker->totalCost(), 0.0001);
    }

    // =====================================================================
    // The unsplit bucket (crush_code.md Phase 5 item 7)
    // =====================================================================

    /**
     * The reason {@see TokenTracker::addTotalUsage()} exists at all. Every
     * provider on this codebase's live paths reports one `usage.total_tokens`
     * figure with no input/output breakdown, and routing it through
     * `addUsage($total, 0, $cost)` would have made `outputTokens()` report zero
     * for a real completion while `inputTokens()` claimed the whole turn.
     *
     * So the halves stay empty and say so, and the total still counts.
     */
    public function testATotalOnlyReportKeepsTheHalvesEmptyAndStillCounts(): void
    {
        $tracker = new TokenTracker();

        $tracker->addTotalUsage(1500, 0.0300);

        $this->assertSame(0, $tracker->inputTokens(), 'no provider named an input half, so none is claimed');
        $this->assertSame(0, $tracker->outputTokens(), 'and none is claimed for output either');
        $this->assertSame(1500, $tracker->unsplitTokens());
        $this->assertSame(1500, $tracker->totalTokens(), 'the session total must still see them');
        $this->assertEqualsWithDelta(0.0300, $tracker->totalCost(), 0.0001);
    }

    /**
     * A tracker fed both shapes keeps them apart and totals them together — the
     * three-bucket rule the class docblock states, read back.
     */
    public function testTheThreeBucketsStayApartAndTotalTogether(): void
    {
        $tracker = new TokenTracker();

        $tracker->addUsage(100, 40, 0.01);
        $tracker->addTotalUsage(500, 0.02);

        $this->assertSame(100, $tracker->inputTokens());
        $this->assertSame(40, $tracker->outputTokens());
        $this->assertSame(500, $tracker->unsplitTokens());
        $this->assertSame(640, $tracker->totalTokens());
        $this->assertEqualsWithDelta(0.03, $tracker->totalCost(), 0.0001);
    }

    /**
     * `reset()` has to clear all three or a `/new` would carry the unsplit half
     * of the previous session forward invisibly.
     */
    public function testResetClearsTheUnsplitBucketToo(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(1, 2, 0.5);
        $tracker->addTotalUsage(300, 0.5);

        $tracker->reset();

        $this->assertSame(0, $tracker->unsplitTokens());
        $this->assertSame(0, $tracker->totalTokens());
        $this->assertSame(0.0, $tracker->totalCost());
    }

    /**
     * The summary's wording is unchanged, byte for byte, for a tracker that only
     * ever saw split reports — so the extension cannot have moved the goalposts
     * for a caller that was already happy.
     */
    public function testTheSummaryIsUnchangedWhenNothingUnsplitWasRecorded(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(1500, 500, 0.0225);

        $this->assertSame('Tokens: 1500 in / 500 out | Cost: $0.0225', $tracker->summary());
    }

    /**
     * And when there IS an unsplit bucket it is named rather than folded in. The
     * two-figure form alone would have reported `0 in / 0 out` for a session that
     * really did spend 1,500 tokens, which is the exact shape of every real
     * provider run here.
     */
    public function testTheSummaryNamesTheUnsplitBucketRatherThanHidingIt(): void
    {
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(1500, 0.0225);

        $this->assertSame('Tokens: 0 in / 0 out + 1500 unsplit | Cost: $0.0225', $tracker->summary());
    }

    /** Both shapes at once, so the summary accounts for every token it holds. */
    public function testTheSummaryAccountsForEveryTokenWhenBothShapesArePresent(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(10, 4, 0.001);
        $tracker->addTotalUsage(86, 0.002);

        $this->assertSame('Tokens: 10 in / 4 out + 86 unsplit | Cost: $0.0030', $tracker->summary());
        $this->assertSame(100, $tracker->totalTokens(), 'and the figures in it sum to the total');
    }
}
