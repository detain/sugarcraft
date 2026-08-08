<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;

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
