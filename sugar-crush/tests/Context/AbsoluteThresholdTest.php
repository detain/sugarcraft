<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;

/**
 * Roadmap 2.9: absolute token caps beside the percentage tiers, and per-model
 * overrides of them (DCP's `minContextLimit` / `maxContextLimit` and
 * `modelMinLimits` / `modelMaxLimits`).
 *
 * The windows are a 1M-token model's, the case the step exists for: there the
 * 70% reminder lands at 700,000 estimated tokens, and only a cap brings it back
 * to a size the model still works well at.
 */
final class AbsoluteThresholdTest extends TestCase
{
    private const WINDOW = 1_000_000;

    /**
     * About $tokens estimated tokens of history (ASCII, four characters a token;
     * the per-message 10 is noise at these sizes).
     *
     * @return list<array{role:string,content:string}>
     */
    private static function historyOf(int $tokens): array
    {
        return [['role' => 'user', 'content' => str_repeat('a', $tokens * 4)]];
    }

    public function testTheDefaultsCarryNoCapSoEveryTierIsItsPercentage(): void
    {
        $config = CompactorConfig::new();

        $this->assertNull($config->reminderTokens);
        $this->assertNull($config->backgroundCompactionTokens);
        $this->assertNull($config->foregroundBlockingTokens);
        $this->assertFalse($config->reminderTokensSet);
        $this->assertFalse($config->backgroundCompactionTokensSet);
        $this->assertFalse($config->foregroundBlockingTokensSet);
        $this->assertSame([], $config->modelTokenOverrides);

        $this->assertSame(700_000, $config->reminderTokenThreshold(self::WINDOW));
        $this->assertSame(850_000, $config->backgroundCompactionTokenThreshold(self::WINDOW));
        $this->assertSame(950_000, $config->foregroundBlockingTokenThreshold(self::WINDOW));
    }

    public function testTheUncappedFigureIsTheExactPre29Expression(): void
    {
        // A window that does not divide evenly: the float-then-truncate form
        // every tier used before the caps must land on the same token.
        $config = CompactorConfig::new();

        foreach ([1, 7, 99_999, 131_072, 1_048_570] as $window) {
            $this->assertSame((int) ($window * 70 / 100), $config->reminderTokenThreshold($window));
            $this->assertSame((int) ($window * 85 / 100), $config->backgroundCompactionTokenThreshold($window));
            $this->assertSame((int) ($window * 95 / 100), $config->foregroundBlockingTokenThreshold($window));
        }
    }

    public function testACapBelowThePercentageWinsAndOneAboveItDoesNot(): void
    {
        $config = CompactorConfig::new()->withReminderTokens(50_000);

        $this->assertSame(50_000, $config->reminderTokenThreshold(self::WINDOW));
        // 70% of a 64k window is 44,800 — under the cap, so the percentage holds.
        $this->assertSame(44_800, $config->reminderTokenThreshold(64_000));
    }

    public function testTheReminderTierFiresAtItsCapOnALargeWindow(): void
    {
        $capped = new ContextCompactor(CompactorConfig::new()->withReminderTokens(50_000));
        $uncapped = ContextCompactor::new();

        $this->assertTrue($capped->shouldSendReminder(self::historyOf(60_000), self::WINDOW));
        $this->assertFalse($capped->shouldSendReminder(self::historyOf(40_000), self::WINDOW));
        $this->assertFalse($uncapped->shouldSendReminder(self::historyOf(60_000), self::WINDOW), 'without a cap 60k is far under 70% of 1M');
    }

    public function testTheCompactionTierFiresAtItsCapOnALargeWindow(): void
    {
        $capped = new ContextCompactor(CompactorConfig::new()->withBackgroundCompactionTokens(100_000));

        $this->assertTrue($capped->shouldCompact(self::historyOf(110_000), self::WINDOW));
        $this->assertFalse($capped->shouldCompact(self::historyOf(90_000), self::WINDOW));
        $this->assertFalse(ContextCompactor::new()->shouldCompact(self::historyOf(110_000), self::WINDOW));
    }

    public function testTheBlockingTierFiresAtItsCapOnALargeWindow(): void
    {
        $capped = new ContextCompactor(CompactorConfig::new()->withForegroundBlockingTokens(200_000));

        $this->assertTrue($capped->shouldCompactForeground(self::historyOf(210_000), self::WINDOW));
        $this->assertFalse($capped->shouldCompactForeground(self::historyOf(190_000), self::WINDOW));
        $this->assertFalse(ContextCompactor::new()->shouldCompactForeground(self::historyOf(210_000), self::WINDOW));
    }

    public function testTheOversizedMessageRescueSizesToTheCappedBlockingTier(): void
    {
        // The rescue and the refusal must agree on the line, or a rescued wire
        // sized to 95% of the window is still refused by a 200k cap.
        $capped = new ContextCompactor(CompactorConfig::new()->withForegroundBlockingTokens(200_000));
        $history = self::historyOf(300_000);

        $rescued = $capped->truncateOversizedExchange($history, self::WINDOW);

        $this->assertNotSame($history, $rescued);
        $this->assertFalse($capped->shouldCompactForeground($rescued, self::WINDOW), 'the rescued wire is back under the capped tier');
        $this->assertSame($history, ContextCompactor::new()->truncateOversizedExchange($history, self::WINDOW), 'uncapped, 300k is under 95% of 1M and nothing is touched');
    }

    public function testANonPositiveWindowStillDisablesEveryCappedTier(): void
    {
        $capped = new ContextCompactor(CompactorConfig::smartZone()->withForegroundBlockingTokens(1));
        $history = self::historyOf(10);

        $this->assertFalse($capped->shouldSendReminder($history, 0));
        $this->assertFalse($capped->shouldCompact($history, 0));
        $this->assertFalse($capped->shouldCompactForeground($history, -1));
    }

    public function testTheSmartZoneIsDcpsFiguresAndLeavesTheBlockingTierUncapped(): void
    {
        $config = CompactorConfig::smartZone();

        $this->assertSame(50_000, CompactorConfig::SMART_ZONE_REMINDER_TOKENS);
        $this->assertSame(100_000, CompactorConfig::SMART_ZONE_COMPACTION_TOKENS);
        $this->assertSame(50_000, $config->reminderTokenThreshold(self::WINDOW));
        $this->assertSame(100_000, $config->backgroundCompactionTokenThreshold(self::WINDOW));
        $this->assertSame(950_000, $config->foregroundBlockingTokenThreshold(self::WINDOW));
        $this->assertFalse($config->foregroundBlockingTokensSet);
        // The percentages are untouched — the caps sit beside them.
        $this->assertSame(70, $config->reminderThreshold);
        $this->assertSame(85, $config->backgroundCompactionThreshold);
    }

    public function testWithTokensMarksTheCapConfiguredEvenWhenItIsNull(): void
    {
        $config = CompactorConfig::new()->withReminderTokens(null);

        $this->assertNull($config->reminderTokens);
        $this->assertTrue($config->reminderTokensSet);
        $this->assertFalse($config->backgroundCompactionTokensSet);
    }

    public function testEveryWitherKeepsTheCapsAndTheOverrides(): void
    {
        $base = CompactorConfig::smartZone()
            ->withForegroundBlockingTokens(400_000)
            ->withModelTokenOverride('qwen3', ['reminderTokens' => 80_000]);

        $derived = $base
            ->withReminderThreshold(60)
            ->withBackgroundCompactionThreshold(80)
            ->withForegroundBlockingThreshold(90)
            ->withRecentPreserveCount(4)
            ->withSkillBudgetPerSkill(10)
            ->withSkillBudgetCombined(20)
            ->withSummaryUserMaxChars(30)
            ->withSummaryAssistantMaxChars(40)
            ->withToolOutputMaxChars(50);

        $this->assertSame(50_000, $derived->reminderTokens);
        $this->assertSame(100_000, $derived->backgroundCompactionTokens);
        $this->assertSame(400_000, $derived->foregroundBlockingTokens);
        $this->assertTrue($derived->reminderTokensSet);
        $this->assertSame(['qwen3' => ['reminderTokens' => 80_000]], $derived->modelTokenOverrides);
        $this->assertSame([60, 80, 90, 4, 10, 20, 30, 40, 50], [
            $derived->reminderThreshold,
            $derived->backgroundCompactionThreshold,
            $derived->foregroundBlockingThreshold,
            $derived->recentPreserveCount,
            $derived->skillBudgetPerSkill,
            $derived->skillBudgetCombined,
            $derived->summaryUserMaxChars,
            $derived->summaryAssistantMaxChars,
            $derived->toolOutputMaxChars,
        ]);
        // And the original is untouched.
        $this->assertSame(70, $base->reminderThreshold);
    }

    public function testForModelAppliesTheNamedModelsCaps(): void
    {
        $config = CompactorConfig::new()->withModelTokenOverride('qwen3-next', [
            'reminderTokens' => 60_000,
            'backgroundCompactionTokens' => 120_000,
        ]);

        $resolved = $config->forModel('qwen3-next');

        $this->assertSame(60_000, $resolved->reminderTokenThreshold(self::WINDOW));
        $this->assertSame(120_000, $resolved->backgroundCompactionTokenThreshold(self::WINDOW));
        $this->assertSame(950_000, $resolved->foregroundBlockingTokenThreshold(self::WINDOW), 'a key the override leaves out inherits');
        $this->assertTrue($resolved->reminderTokensSet);
        $this->assertFalse($resolved->foregroundBlockingTokensSet);
    }

    public function testProviderQualifiedKeyBeatsTheBareModelId(): void
    {
        $config = CompactorConfig::new()
            ->withModelTokenOverride('qwen3', ['reminderTokens' => 90_000])
            ->withModelTokenOverride('sglang/qwen3', ['reminderTokens' => 40_000]);

        $this->assertSame(40_000, $config->forModel('qwen3', 'sglang')->reminderTokens);
        $this->assertSame(90_000, $config->forModel('qwen3', 'openai')->reminderTokens);
        $this->assertSame(90_000, $config->forModel('qwen3')->reminderTokens);
    }

    public function testAnOverrideThatNamesNullClearsTheBaseCap(): void
    {
        $config = CompactorConfig::smartZone()->withModelTokenOverride('big-context', ['reminderTokens' => null]);

        $resolved = $config->forModel('big-context');

        $this->assertNull($resolved->reminderTokens);
        $this->assertTrue($resolved->reminderTokensSet);
        $this->assertSame(700_000, $resolved->reminderTokenThreshold(self::WINDOW));
        $this->assertSame(100_000, $resolved->backgroundCompactionTokens, 'the cap the override did not name is inherited');
    }

    public function testAnUnknownOrEmptyModelResolvesToTheSameInstance(): void
    {
        $config = CompactorConfig::smartZone()->withModelTokenOverride('qwen3', ['reminderTokens' => 1_000]);

        $this->assertSame($config, $config->forModel('gpt-4o'));
        $this->assertSame($config, $config->forModel(null));
        $this->assertSame($config, $config->forModel(''));
    }

    public function testACapBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reminderTokens must be at least 1 token');

        CompactorConfig::new()->withReminderTokens(0);
    }

    public function testAConstructorCapBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CompactorConfig(foregroundBlockingTokens: -5);
    }

    public function testAnOverrideWithAnUnknownKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown token override "reminderThreshold"');

        CompactorConfig::new()->withModelTokenOverride('qwen3', ['reminderThreshold' => 50]);
    }

    public function testAnOverrideWithANonPositiveCapIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompactorConfig::new()->withModelTokenOverride('qwen3', ['backgroundCompactionTokens' => 0]);
    }

    public function testAnOverrideWithoutAModelIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompactorConfig::new()->withModelTokenOverride('', ['reminderTokens' => 10]);
    }
}
