<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;

#[CoversClass(SmithWatermanMatcher::class)]
final class SmithWatermanCapsTest extends TestCase
{
    #[Test]
    public function testNewForwardsEveryConstructorOption(): void
    {
        $profile = ScoringProfile::lenient();
        $m = SmithWatermanMatcher::new($profile, maxQueryLength: 5, maxCandidateLength: 11, requireFullQuery: true);

        $this->assertSame($profile, $m->profile());
        $this->assertSame(5, $m->maxQueryLength());
        $this->assertSame(11, $m->maxCandidateLength());
        $this->assertTrue($m->requiresFullQuery());
    }

    #[Test]
    public function testNewDefaultsMatchTheConstructor(): void
    {
        $this->assertEquals(new SmithWatermanMatcher(), SmithWatermanMatcher::new());
        $m = SmithWatermanMatcher::new();
        $this->assertSame(SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH, $m->maxQueryLength());
        $this->assertSame(SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH, $m->maxCandidateLength());
        $this->assertFalse($m->requiresFullQuery());
    }

    #[Test]
    public function testNewRejectsInvalidCaps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Length caps must be >= 1');
        SmithWatermanMatcher::new(maxCandidateLength: 0);
    }

    #[Test]
    public function testDefaultQueryCapTruncatesAPastedQuery(): void
    {
        $cap = SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH;
        $this->assertSame(128, $cap);

        $m = new SmithWatermanMatcher();
        $head = str_repeat('ab', intdiv($cap, 2));
        $candidate = $head . 'tail';
        $query = $head . 'zzzzzzzzzz';

        // Everything past the cap is ignored: the result equals the head-only
        // alignment, while the needle stays what the caller passed.
        $long = $m->match($query, $candidate);
        $head = $m->match($head, $candidate);
        $this->assertNotNull($long);
        $this->assertNotNull($head);
        $this->assertSame($query, $long->needle);
        $this->assertSame($head->score, $long->score);
        $this->assertSame($head->matchedIndices, $long->matchedIndices);
    }

    /**
     * Regression: an at-cap match() used to build two nested PHP int matrices
     * (score + traceback), ~40 MB for 1000×1000. The traceback is now one
     * byte per cell and the scores two rows.
     */
    #[Test]
    public function testAtCapMatchMemoryIsBounded(): void
    {
        $m = new SmithWatermanMatcher(maxQueryLength: 1000, maxCandidateLength: 1000);
        $text = str_repeat('ab', 500);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $result = $m->match($text, $text);
        $peak = memory_get_peak_usage() - $baseline;

        $this->assertNotNull($result);
        $this->assertCount(1000, $result->matchedIndices);
        $this->assertLessThan(8 * 1024 * 1024, $peak, sprintf('at-cap match peaked at %.1f MB', $peak / 1048576));
    }

    #[Test]
    public function testHugeCandidateIsBoundedByTheCap(): void
    {
        $m = new SmithWatermanMatcher();
        $candidate = 'needle' . str_repeat('x', 200_000);

        $start = microtime(true);
        $result = $m->match('needle', $candidate);
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2, 3, 4, 5], $result->matchedIndices);
        $this->assertLessThan(2.0, $elapsed);
    }
}
