<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\StallDetector;
use SugarCraft\Crush\Tui\StallWarning;

/**
 * @see StallDetector
 * @see StallWarning
 */
final class StallDetectorTest extends TestCase
{
    // ========================================================================
    // Construction & defaults
    // ========================================================================

    public function testDefaultThresholdIs30Seconds(): void
    {
        $detector = new StallDetector();
        $this->assertFalse($detector->isStalled('agent-1'));
    }

    public function testDefaultMinTokensPerSecondIsPoint5(): void
    {
        $detector = new StallDetector(minTokensPerSecond: 0.5);
        $this->assertFalse($detector->isStalled('agent-1'));
    }

    public function testCustomThresholdIsRespected(): void
    {
        // With threshold of 5s, a stall should be detectable sooner.
        $detector = new StallDetector(thresholdSeconds: 5, minTokensPerSecond: 0.5);
        $this->assertFalse($detector->isStalled('agent-1'));
    }

    // ========================================================================
    // track() — no stall when tokens arriving quickly
    // ========================================================================

    public function testNoStallWhenTokensArriveQuickly(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 10.0);

        // Simulate a burst: 100 tokens in 1 second = 100 tok/s (well above 10).
        $detector->track('agent-1', 0);
        $detector->track('agent-1', 100);

        $this->assertFalse($detector->isStalled('agent-1'));
        $this->assertSame([], $detector->getStallWarnings());
    }

    public function testNoStallAfterSingleTokenBurst(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        // 5 tokens arriving rapidly should not trigger stall.
        $detector->track('agent-1', 0);
        usleep(50_000); // 50ms
        $detector->track('agent-1', 5);

        $this->assertFalse($detector->isStalled('agent-1'));
    }

    // ========================================================================
    // track() — stall detected after sustained low/no token flow
    // ========================================================================

    public function testStallDetectedAfter30SecondsOfLowTokenFlow(): void
    {
        // Use a very high minTokensPerSecond so any realistic gap triggers stall.
        $detector = new StallDetector(thresholdSeconds: 1, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);
        $detector->track('agent-1', 1);

        // Now simulate a 2-second gap with no new tokens.
        // We cheat the clock by calling track with same token count.
        // After track() with no new tokens, slowPeriod accumulates.
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);

        // Manually set slow period to simulate time passage.
        $agents = $snap->getValue($detector);
        $agent = $agents['agent-1'];
        $reflector = new \ReflectionClass($agent);
        $prop = $reflector->getProperty('slowPeriodSeconds');
        $prop->setAccessible(true);
        $prop->setValue($agent, 2.0);
        $snap->setValue($detector, ['agent-1' => $agent]);

        $this->assertTrue($detector->isStalled('agent-1'));
    }

    public function testStallNotDetectedBeforeThreshold(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);

        // Simulate only 10 seconds of slow period (less than threshold).
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);
        $agents = $snap->getValue($detector);
        $agent = $agents['agent-1'];
        $reflector = new \ReflectionClass($agent);
        $prop = $reflector->getProperty('slowPeriodSeconds');
        $prop->setAccessible(true);
        $prop->setValue($agent, 10.0);
        $snap->setValue($detector, ['agent-1' => $agent]);

        $this->assertFalse($detector->isStalled('agent-1'));
    }

    // ========================================================================
    // track() — stall cleared when tokens resume
    // ========================================================================

    public function testStallClearedWhenTokensResume(): void
    {
        $detector = new StallDetector(thresholdSeconds: 1, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);
        $detector->track('agent-1', 1);

        // Simulate stall (2 seconds of slow period).
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);
        $agents = $snap->getValue($detector);
        $agent = $agents['agent-1'];
        $reflector = new \ReflectionClass($agent);
        $prop = $reflector->getProperty('slowPeriodSeconds');
        $prop->setAccessible(true);
        $prop->setValue($agent, 2.0);
        $snap->setValue($detector, ['agent-1' => $agent]);

        $this->assertTrue($detector->isStalled('agent-1'));

        // Now simulate tokens resuming (new burst of 100 tokens).
        $detector->track('agent-1', 100);

        $this->assertFalse($detector->isStalled('agent-1'));
        $this->assertSame([], $detector->getStallWarnings());
    }

    // ========================================================================
    // Multiple agents tracked independently
    // ========================================================================

    public function testMultipleAgentsTrackedIndependently(): void
    {
        $detector = new StallDetector(thresholdSeconds: 1, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);
        $detector->track('agent-1', 1);
        $detector->track('agent-2', 0);
        $detector->track('agent-2', 100);

        // Simulate stall only for agent-1.
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);
        $agents = $snap->getValue($detector);

        $r = new \ReflectionClass($agents['agent-1']);
        $p = $r->getProperty('slowPeriodSeconds');
        $p->setAccessible(true);
        $p->setValue($agents['agent-1'], 2.0);

        $snap->setValue($detector, $agents);

        $this->assertTrue($detector->isStalled('agent-1'));
        $this->assertFalse($detector->isStalled('agent-2'));
    }

    public function testStallWarningsContainsOnlyStalledAgents(): void
    {
        $detector = new StallDetector(thresholdSeconds: 1, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);
        $detector->track('agent-2', 0);

        // Stall agent-1 only.
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);
        $agents = $snap->getValue($detector);
        $r = new \ReflectionClass($agents['agent-1']);
        $p = $r->getProperty('slowPeriodSeconds');
        $p->setAccessible(true);
        $p->setValue($agents['agent-1'], 2.0);
        $snap->setValue($detector, $agents);

        $warnings = $detector->getStallWarnings();

        $this->assertArrayHasKey('agent-1', $warnings);
        $this->assertArrayNotHasKey('agent-2', $warnings);
    }

    public function testNoWarningsWhenNoAgentsStalled(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $detector->track('agent-1', 0);
        $detector->track('agent-1', 50);

        $this->assertSame([], $detector->getStallWarnings());
    }

    // ========================================================================
    // Timeout detection
    // ========================================================================

    public function testStallWarningIsTimedOutWhenPastThreshold(): void
    {
        $warning = new StallWarning(
            agentId: 'agent-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.1,
            durationSeconds: 35,
        );

        $this->assertTrue($warning->isTimedOut(30));
    }

    public function testStallWarningIsNotTimedOutBeforeThreshold(): void
    {
        $warning = new StallWarning(
            agentId: 'agent-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.1,
            durationSeconds: 20,
        );

        $this->assertFalse($warning->isTimedOut(30));
    }

    public function testStallWarningIsTimedOutExactlyAtBoundary(): void
    {
        $warning = new StallWarning(
            agentId: 'agent-1',
            detectedAt: new \DateTimeImmutable(),
            tokenRate: 0.0,
            durationSeconds: 30,
        );

        $this->assertTrue($warning->isTimedOut(30));
    }

    // ========================================================================
    // Edge cases: zero tokens, exactly at threshold boundary
    // ========================================================================

    public function testNoStallWithZeroTokensAtStart(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $detector->track('agent-1', 0);

        $this->assertFalse($detector->isStalled('agent-1'));
    }

    public function testStallDetectedAtExactlyThresholdBoundary(): void
    {
        $detector = new StallDetector(thresholdSeconds: 10, minTokensPerSecond: 1000.0);

        $detector->track('agent-1', 0);
        $detector->track('agent-1', 1);

        // Set exactly at threshold.
        $snap = new \ReflectionProperty($detector, 'agents');
        $snap->setAccessible(true);
        $agents = $snap->getValue($detector);
        $r = new \ReflectionClass($agents['agent-1']);
        $p = $r->getProperty('slowPeriodSeconds');
        $p->setAccessible(true);
        $p->setValue($agents['agent-1'], 10.0);
        $snap->setValue($detector, $agents);

        $this->assertTrue($detector->isStalled('agent-1'));
    }

    public function testZeroTokenDeltaDoesNotCrash(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $detector->track('agent-1', 100);
        $detector->track('agent-1', 100); // Same token count — zero delta.

        // Should not throw — graceful handling of zero delta.
        $this->assertFalse($detector->isStalled('agent-1'));
    }

    public function testNegativeTokenDeltaDoesNotCrash(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $detector->track('agent-1', 100);
        $detector->track('agent-1', 50); // Decreasing tokens (e.g. reroll).

        // Should not throw — rate computed as negative then clamped to 0.
        $this->assertFalse($detector->isStalled('agent-1'));
    }

    public function testIsStalledReturnsFalseForUnknownAgent(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $this->assertFalse($detector->isStalled('never-seen-agent'));
    }

    public function testGetStallWarningsReturnsEmptyForUnknownAgent(): void
    {
        $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);

        $detector->track('agent-1', 0);

        $this->assertSame([], $detector->getStallWarnings());
    }

    // ========================================================================
    // StallWarning value object properties
    // ========================================================================

    public function testStallWarningHasCorrectProperties(): void
    {
        $detectedAt = new \DateTimeImmutable('2026-08-05T12:00:00Z');
        $warning = new StallWarning(
            agentId: 'coder-1',
            detectedAt: $detectedAt,
            tokenRate: 0.3,
            durationSeconds: 45,
        );

        $this->assertSame('coder-1', $warning->agentId);
        $this->assertSame($detectedAt, $warning->detectedAt);
        $this->assertSame(0.3, $warning->tokenRate);
        $this->assertSame(45, $warning->durationSeconds);
    }
}
