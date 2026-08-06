<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Configuration for automatic context compaction.
 *
 * Controls the tiered threshold system that triggers compaction at
 * different levels of context usage, and budgets for skill content
 * during compaction passes.
 *
 * All values are immutable after construction — use with*() methods
 * to produce derived instances.
 */
final readonly class CompactorConfig
{
    /**
     * @param int $reminderThreshold        Context usage percentage (0-100) at which
     *                                      a reminder is sent to the lead agent.
     *                                      Default: 70.
     * @param int $backgroundCompactionThreshold Context usage percentage at which
     *                                      automatic compaction begins in the background.
     *                                      Default: 85.
     * @param int $foregroundBlockingThreshold Context usage percentage at which
     *                                      foreground compaction blocks new input.
     *                                      Default: 95.
     * @param int $recentPreserveCount     Number of most-recent exchanges to keep
     *                                      full (uncompacted) during stage one.
     *                                      Default: 10.
     * @param int $skillBudgetPerSkill      Max tokens per skill during compaction.
     *                                      Default: 5000.
     * @param int $skillBudgetCombined     Combined budget cap across all skills
     *                                      still in context after compaction.
     *                                      Default: 25000.
     */
    public function __construct(
        public int $reminderThreshold = 70,
        public int $backgroundCompactionThreshold = 85,
        public int $foregroundBlockingThreshold = 95,
        public int $recentPreserveCount = 10,
        public int $skillBudgetPerSkill = 5000,
        public int $skillBudgetCombined = 25000,
    ) {}

    /**
     * Factory creating a config with default values.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Create a new config with a different reminderThreshold value.
     */
    public function withReminderThreshold(int $reminderThreshold): self
    {
        return new self(
            reminderThreshold: $reminderThreshold,
            backgroundCompactionThreshold: $this->backgroundCompactionThreshold,
            foregroundBlockingThreshold: $this->foregroundBlockingThreshold,
            recentPreserveCount: $this->recentPreserveCount,
            skillBudgetPerSkill: $this->skillBudgetPerSkill,
            skillBudgetCombined: $this->skillBudgetCombined,
        );
    }

    /**
     * Create a new config with a different backgroundCompactionThreshold value.
     */
    public function withBackgroundCompactionThreshold(int $backgroundCompactionThreshold): self
    {
        return new self(
            reminderThreshold: $this->reminderThreshold,
            backgroundCompactionThreshold: $backgroundCompactionThreshold,
            foregroundBlockingThreshold: $this->foregroundBlockingThreshold,
            recentPreserveCount: $this->recentPreserveCount,
            skillBudgetPerSkill: $this->skillBudgetPerSkill,
            skillBudgetCombined: $this->skillBudgetCombined,
        );
    }

    /**
     * Create a new config with a different foregroundBlockingThreshold value.
     */
    public function withForegroundBlockingThreshold(int $foregroundBlockingThreshold): self
    {
        return new self(
            reminderThreshold: $this->reminderThreshold,
            backgroundCompactionThreshold: $this->backgroundCompactionThreshold,
            foregroundBlockingThreshold: $foregroundBlockingThreshold,
            recentPreserveCount: $this->recentPreserveCount,
            skillBudgetPerSkill: $this->skillBudgetPerSkill,
            skillBudgetCombined: $this->skillBudgetCombined,
        );
    }

    /**
     * Create a new config with a different recentPreserveCount value.
     */
    public function withRecentPreserveCount(int $recentPreserveCount): self
    {
        return new self(
            reminderThreshold: $this->reminderThreshold,
            backgroundCompactionThreshold: $this->backgroundCompactionThreshold,
            foregroundBlockingThreshold: $this->foregroundBlockingThreshold,
            recentPreserveCount: $recentPreserveCount,
            skillBudgetPerSkill: $this->skillBudgetPerSkill,
            skillBudgetCombined: $this->skillBudgetCombined,
        );
    }

    /**
     * Create a new config with a different skillBudgetPerSkill value.
     */
    public function withSkillBudgetPerSkill(int $skillBudgetPerSkill): self
    {
        return new self(
            reminderThreshold: $this->reminderThreshold,
            backgroundCompactionThreshold: $this->backgroundCompactionThreshold,
            foregroundBlockingThreshold: $this->foregroundBlockingThreshold,
            recentPreserveCount: $this->recentPreserveCount,
            skillBudgetPerSkill: $skillBudgetPerSkill,
            skillBudgetCombined: $this->skillBudgetCombined,
        );
    }

    /**
     * Create a new config with a different skillBudgetCombined value.
     */
    public function withSkillBudgetCombined(int $skillBudgetCombined): self
    {
        return new self(
            reminderThreshold: $this->reminderThreshold,
            backgroundCompactionThreshold: $this->backgroundCompactionThreshold,
            foregroundBlockingThreshold: $this->foregroundBlockingThreshold,
            recentPreserveCount: $this->recentPreserveCount,
            skillBudgetPerSkill: $this->skillBudgetPerSkill,
            skillBudgetCombined: $skillBudgetCombined,
        );
    }
}
