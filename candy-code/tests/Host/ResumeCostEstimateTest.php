<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\ContextMeter;
use SugarCraft\Code\Host\ResumeCostEstimate;
use SugarCraft\Code\Message;

/**
 * C-SES-7.d: the resume-cost arithmetic, pinned as the pure function it is.
 * Every branch of {@see ResumeCostEstimate::of()} — the disabled knob, the
 * warm cache, the priced threshold, the unpriced `≥`, the unknown-activity
 * cold rule, calibration scaling — and both renderings of
 * {@see ResumeCostEstimate::formatTokens()}. The clock is ALWAYS injected
 * and every instant is built from a fixed epoch, so the TTL edge is pinned
 * to the second and no test drifts with the wall clock.
 */
final class ResumeCostEstimateTest extends TestCase
{
    /** Fixed "now" epoch; every relative instant is measured from it. */
    private const T0 = 1_700_000_000;

    /** The design's own example: ~84k tokens at $5/M is ~$0.42 (measures 84_010). */
    private const HEAVY_CHARS = 336_000;

    /** The meter's measured figures: chars/4 plus 10 framing tokens per message. */
    private const LIGHT_CHARS = 2_560;

    private ContextMeter $meter;

    protected function setUp(): void
    {
        $this->meter = ContextMeter::new();
    }

    // =====================================================================
    // The opt-out knob and the nothing-to-send case
    // =====================================================================

    public function testZeroThresholdSilencesEvenTheColdestPricedResume(): void
    {
        $this->assertNull($this->estimate(self::HEAVY_CHARS, 0.005, 3_600, 0.0));
        // Below zero reads the same intent: the knob is off.
        $this->assertNull($this->estimate(self::HEAVY_CHARS, 0.005, 3_600, -1.0));
    }

    public function testAnEmptyTranscriptIsNothingToWarnAbout(): void
    {
        $estimate = ResumeCostEstimate::of(
            $this->meter,
            [],
            0.005,
            $this->ago(3_600),
            ResumeCostEstimate::DEFAULT_WARN_USD,
            now: $this->now(),
        );
        $this->assertNull($estimate);
    }

    // =====================================================================
    // The cache TTL edge
    // =====================================================================

    public function testAResumeInsideTheCacheWindowIsNotChargedAsFreshInput(): void
    {
        // One second shy of the five-minute TTL: the re-send is a cache read.
        $this->assertNull($this->estimate(
            self::HEAVY_CHARS,
            0.005,
            ResumeCostEstimate::CACHE_TTL_SECONDS - 1,
            ResumeCostEstimate::DEFAULT_WARN_USD,
        ));
    }

    public function testTheTtlEdgeItselfIsColdAndTheMonsterResumeFires(): void
    {
        // EXACTLY the TTL is no longer inside it — the cache has lapsed.
        $estimate = $this->estimate(
            self::HEAVY_CHARS,
            0.005,
            ResumeCostEstimate::CACHE_TTL_SECONDS,
            ResumeCostEstimate::DEFAULT_WARN_USD,
        );
        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isPriced());
        $this->assertSame(84_010, $estimate->tokens);
        $this->assertEqualsWithDelta(0.42, $estimate->costUsd, 0.001);
        // The design's sentence shape: "84k" tokens and the real number.
        $this->assertSame('84k', $estimate->formatTokens());
    }

    // =====================================================================
    // The priced threshold
    // =====================================================================

    public function testAPricedResumeCheaperThanTheThresholdStaysSilent(): void
    {
        // 650 tokens at half a cent per 1k is fractions of a cent.
        $this->assertNull($this->estimate(self::LIGHT_CHARS, 0.005, 3_600, ResumeCostEstimate::DEFAULT_WARN_USD));
    }

    public function testAPricedResumePastTheThresholdFiresAndOneJustBelowItDoesNot(): void
    {
        // 650 tokens at 40 cents/1k is $0.26 — a cent over the default line.
        $over = $this->estimate(self::LIGHT_CHARS, 0.4, 3_600, 0.25);
        $this->assertNotNull($over);
        $this->assertEqualsWithDelta(0.26, $over->costUsd, 0.001);
        // Raise the line one cent and the same resume is nobody's business.
        $this->assertNull($this->estimate(self::LIGHT_CHARS, 0.4, 3_600, 0.27));
    }

    public function testAThresholdRaisedByTheUserPricesTheSameResumeQuietAgain(): void
    {
        $this->assertNull($this->estimate(self::HEAVY_CHARS, 0.005, 3_600, 10.0));
    }

    // =====================================================================
    // The unpriced `≥` branch
    // =====================================================================

    public function testAnUnpricedModelFiresOnAnyStaleResumeWithNoInventedNumber(): void
    {
        $estimate = $this->estimate(self::LIGHT_CHARS, null, 3_600, ResumeCostEstimate::DEFAULT_WARN_USD);
        $this->assertNotNull($estimate);
        // The whole point of the branch: there IS no dollar figure, only the
        // threshold as a floor. `isPriced()` false drives the notice's `≥`.
        $this->assertFalse($estimate->isPriced());
        $this->assertNull($estimate->costUsd);
        $this->assertSame(ResumeCostEstimate::DEFAULT_WARN_USD, $estimate->warnUsd);
        $this->assertSame('650', $estimate->formatTokens());
    }

    public function testTheUnpricedBranchStillHeedsTheOptOutKnob(): void
    {
        $this->assertNull($this->estimate(self::HEAVY_CHARS, null, 3_600, 0.0));
    }

    // =====================================================================
    // The unknown-activity cold rule
    // =====================================================================

    public function testAResumeWithNoTimestampIsTreatedAsCold(): void
    {
        // Legacy store / attach: nothing can say WHEN the session last moved.
        // The re-send charges like a cold one, so the notice says so.
        $estimate = ResumeCostEstimate::of(
            $this->meter,
            [Message::user(str_repeat('x', self::HEAVY_CHARS))],
            0.005,
            null,
            ResumeCostEstimate::DEFAULT_WARN_USD,
            now: $this->now(),
        );
        $this->assertNotNull($estimate);
        $this->assertTrue($estimate->isPriced());
    }

    public function testTheColdRuleStillHeedsThePricedThreshold(): void
    {
        // Cheap-and-unknown stays quiet: "unknown" means cold, not expensive.
        $estimate = ResumeCostEstimate::of(
            $this->meter,
            [Message::user(str_repeat('x', self::LIGHT_CHARS))],
            0.005,
            null,
            ResumeCostEstimate::DEFAULT_WARN_USD,
            now: $this->now(),
        );
        $this->assertNull($estimate);
    }

    // =====================================================================
    // Calibration parity and the renderings
    // =====================================================================

    public function testTheCalibrationScalesTheEstimateLikeEveryOtherContextTier(): void
    {
        $uncalibrated = $this->estimate(150_000, 0.005, 3_600, 0.1);
        $calibrated = ResumeCostEstimate::of(
            $this->meter,
            [Message::user(str_repeat('x', 150_000))],
            0.005,
            $this->ago(3_600),
            0.1,
            calibration: 2.0,
            now: $this->now(),
        );
        $this->assertNotNull($uncalibrated);
        $this->assertNotNull($calibrated);
        // 150k chars measure 37_510 raw (the meter's per-message framing
        // included); the session calibration multiplies the same raw figure.
        $this->assertSame(37_510, $uncalibrated->tokens);
        $this->assertSame(75_020, $calibrated->tokens);
        $this->assertEqualsWithDelta(2.0, $calibrated->costUsd / $uncalibrated->costUsd, 0.001);
    }

    public function testFormatTokensPrintsRawBelowOneThousandAndRoundedKiloTalliesAtOrAboveIt(): void
    {
        // 999 tokens print as digits — "1k" would round a nothing up to a something.
        $digits = $this->coldUnpriced(3_956);
        $this->assertNotNull($digits);
        $this->assertSame(999, $digits->tokens);
        $this->assertSame('999', $digits->formatTokens());

        // Exactly 1000 is a k, and a 1,449 still rounds back down to one.
        $this->assertSame('1k', $this->renderTokens(3_960));
        $this->assertSame('1k', $this->renderTokens(5_756));
        // 1,510 rounds up.
        $this->assertSame('2k', $this->renderTokens(6_000));
    }

    // =====================================================================

    /**
     * The one-call shape: $chars of user text, priced at $costPer1k (null =
     * no price on file), last moved $idleSeconds before the fixed now
     * (null = no timestamp at all), against the $warnUsd threshold.
     */
    private function estimate(int $chars, ?float $costPer1k, ?int $idleSeconds, float $warnUsd): ?ResumeCostEstimate
    {
        return ResumeCostEstimate::of(
            $this->meter,
            [Message::user(str_repeat('x', $chars))],
            $costPer1k,
            $idleSeconds === null ? null : $this->ago($idleSeconds),
            $warnUsd,
            now: $this->now(),
        );
    }

    /** Guaranteed to fire: cold, unpriced, threshold at the default. */
    private function coldUnpriced(int $chars): ?ResumeCostEstimate
    {
        return $this->estimate($chars, null, 3_600, ResumeCostEstimate::DEFAULT_WARN_USD);
    }

    private function renderTokens(int $chars): string
    {
        $estimate = $this->coldUnpriced($chars);
        $this->assertNotNull($estimate);

        return $estimate->formatTokens();
    }

    /** An instant $seconds before the fixed now. */
    private function ago(int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . (self::T0 - $seconds));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . self::T0);
    }
}
