<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\CompactorConfig;

final class CompactorConfigTest extends TestCase
{
    public function testNewReturnsInstanceWithDefaults(): void
    {
        $config = CompactorConfig::new();

        $this->assertSame(70, $config->reminderThreshold);
        $this->assertSame(85, $config->backgroundCompactionThreshold);
        $this->assertSame(95, $config->foregroundBlockingThreshold);
        $this->assertSame(10, $config->recentPreserveCount);
        $this->assertSame(5000, $config->skillBudgetPerSkill);
        $this->assertSame(25000, $config->skillBudgetCombined);
        $this->assertSame(2000, $config->toolOutputMaxChars);
    }

    public function testDefaultValuesViaConstructor(): void
    {
        $config = new CompactorConfig();

        $this->assertSame(70, $config->reminderThreshold);
        $this->assertSame(85, $config->backgroundCompactionThreshold);
        $this->assertSame(95, $config->foregroundBlockingThreshold);
        $this->assertSame(10, $config->recentPreserveCount);
        $this->assertSame(5000, $config->skillBudgetPerSkill);
        $this->assertSame(25000, $config->skillBudgetCombined);
        $this->assertSame(2000, $config->toolOutputMaxChars);
    }

    public function testAllAccessorsReturnSetValues(): void
    {
        $config = new CompactorConfig(
            reminderThreshold: 60,
            backgroundCompactionThreshold: 80,
            foregroundBlockingThreshold: 90,
            recentPreserveCount: 5,
            skillBudgetPerSkill: 3000,
            skillBudgetCombined: 15000,
        );

        $this->assertSame(60, $config->reminderThreshold);
        $this->assertSame(80, $config->backgroundCompactionThreshold);
        $this->assertSame(90, $config->foregroundBlockingThreshold);
        $this->assertSame(5, $config->recentPreserveCount);
        $this->assertSame(3000, $config->skillBudgetPerSkill);
        $this->assertSame(15000, $config->skillBudgetCombined);
    }

    public function testWithReminderThresholdReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withReminderThreshold(75);

        $this->assertNotSame($original, $modified);
        $this->assertSame(75, $modified->reminderThreshold);
        $this->assertSame(70, $original->reminderThreshold);
        // Other values unchanged
        $this->assertSame(85, $modified->backgroundCompactionThreshold);
        $this->assertSame(95, $modified->foregroundBlockingThreshold);
        $this->assertSame(10, $modified->recentPreserveCount);
        $this->assertSame(5000, $modified->skillBudgetPerSkill);
        $this->assertSame(25000, $modified->skillBudgetCombined);
    }

    public function testWithBackgroundCompactionThresholdReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withBackgroundCompactionThreshold(88);

        $this->assertNotSame($original, $modified);
        $this->assertSame(88, $modified->backgroundCompactionThreshold);
        $this->assertSame(85, $original->backgroundCompactionThreshold);
    }

    public function testWithForegroundBlockingThresholdReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withForegroundBlockingThreshold(99);

        $this->assertNotSame($original, $modified);
        $this->assertSame(99, $modified->foregroundBlockingThreshold);
        $this->assertSame(95, $original->foregroundBlockingThreshold);
    }

    public function testWithRecentPreserveCountReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withRecentPreserveCount(20);

        $this->assertNotSame($original, $modified);
        $this->assertSame(20, $modified->recentPreserveCount);
        $this->assertSame(10, $original->recentPreserveCount);
    }

    public function testWithSkillBudgetPerSkillReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withSkillBudgetPerSkill(4000);

        $this->assertNotSame($original, $modified);
        $this->assertSame(4000, $modified->skillBudgetPerSkill);
        $this->assertSame(5000, $original->skillBudgetPerSkill);
    }

    public function testWithSkillBudgetCombinedReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withSkillBudgetCombined(20000);

        $this->assertNotSame($original, $modified);
        $this->assertSame(20000, $modified->skillBudgetCombined);
        $this->assertSame(25000, $original->skillBudgetCombined);
    }

    public function testWithSummaryUserMaxCharsReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withSummaryUserMaxChars(120);

        $this->assertNotSame($original, $modified);
        $this->assertSame(120, $modified->summaryUserMaxChars);
        $this->assertSame(80, $original->summaryUserMaxChars);
    }

    public function testWithSummaryAssistantMaxCharsReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withSummaryAssistantMaxChars(150);

        $this->assertNotSame($original, $modified);
        $this->assertSame(150, $modified->summaryAssistantMaxChars);
        $this->assertSame(100, $original->summaryAssistantMaxChars);
    }

    public function testWithToolOutputMaxCharsReturnsNewInstance(): void
    {
        $original = CompactorConfig::new();
        $modified = $original->withToolOutputMaxChars(640);

        $this->assertNotSame($original, $modified);
        $this->assertSame(640, $modified->toolOutputMaxChars);
        $this->assertSame(2000, $original->toolOutputMaxChars);
        // Unchanged neighbours: the new arg rides on every other value.
        $this->assertSame(100, $modified->summaryAssistantMaxChars);
        $this->assertSame(80, $modified->summaryUserMaxChars);
    }

    /**
     * The bound survives a wither that has nothing to do with it.
     *
     * Each `with*()` rebuilds the whole object through named arguments, so a new
     * property MISSING from one of those calls is not a compile error and not a
     * failed test anywhere else — it is the bound quietly resetting to its default
     * the first time anyone tunes an unrelated knob. Every existing setter is
     * called here for exactly that reason.
     */
    public function testToolOutputMaxCharsSurvivesEveryUnrelatedWither(): void
    {
        $config = CompactorConfig::new()->withToolOutputMaxChars(7);
        $this->assertSame(7, $config->toolOutputMaxChars, 'fixture: a deliberately absurd bound to spot a reset');

        $this->assertSame(7, $config->withReminderThreshold(61)->toolOutputMaxChars);
        $this->assertSame(7, $config->withBackgroundCompactionThreshold(81)->toolOutputMaxChars);
        $this->assertSame(7, $config->withForegroundBlockingThreshold(91)->toolOutputMaxChars);
        $this->assertSame(7, $config->withRecentPreserveCount(3)->toolOutputMaxChars);
        $this->assertSame(7, $config->withSkillBudgetPerSkill(1234)->toolOutputMaxChars);
        $this->assertSame(7, $config->withSkillBudgetCombined(4321)->toolOutputMaxChars);
        $this->assertSame(7, $config->withSummaryUserMaxChars(77)->toolOutputMaxChars);
        $this->assertSame(7, $config->withSummaryAssistantMaxChars(88)->toolOutputMaxChars);
    }

    public function testChainingWithMethods(): void
    {
        $original = CompactorConfig::new();
        $modified = $original
            ->withReminderThreshold(65)
            ->withRecentPreserveCount(15)
            ->withSkillBudgetPerSkill(4500);

        $this->assertSame(65, $modified->reminderThreshold);
        $this->assertSame(15, $modified->recentPreserveCount);
        $this->assertSame(4500, $modified->skillBudgetPerSkill);
        // Unchanged defaults
        $this->assertSame(85, $modified->backgroundCompactionThreshold);
        $this->assertSame(95, $modified->foregroundBlockingThreshold);
        $this->assertSame(25000, $modified->skillBudgetCombined);
        // Original unchanged
        $this->assertSame(70, $original->reminderThreshold);
        $this->assertSame(10, $original->recentPreserveCount);
        $this->assertSame(5000, $original->skillBudgetPerSkill);
    }

    public function testThresholdValuesAreInValidRange(): void
    {
        // Thresholds are percentages and should be 0-100
        // This is a constraint enforced by usage, not by the class itself
        // but we verify the defaults are in range
        $config = CompactorConfig::new();

        $this->assertGreaterThanOrEqual(0, $config->reminderThreshold);
        $this->assertLessThanOrEqual(100, $config->reminderThreshold);
        $this->assertGreaterThanOrEqual(0, $config->backgroundCompactionThreshold);
        $this->assertLessThanOrEqual(100, $config->backgroundCompactionThreshold);
        $this->assertGreaterThanOrEqual(0, $config->foregroundBlockingThreshold);
        $this->assertLessThanOrEqual(100, $config->foregroundBlockingThreshold);
    }

    public function testThresholdsAreOrderedCorrectly(): void
    {
        $config = CompactorConfig::new();

        $this->assertLessThan(
            $config->backgroundCompactionThreshold,
            $config->reminderThreshold,
            'Reminder threshold should be less than background compaction threshold',
        );
        $this->assertGreaterThan(
            $config->backgroundCompactionThreshold,
            $config->foregroundBlockingThreshold,
            'Foreground blocking threshold should exceed background compaction threshold',
        );
    }
}
