<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillMatcher;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Tests for SkillMatcher — Level-1 metadata listing for system-prompt injection.
 */
final class SkillMatcherTest extends TestCase
{
    private function createSkill(
        string $name,
        string $description = 'Test description',
        bool $disableModelInvocation = false,
        bool $userInvocable = true,
    ): Skill {
        $yamlDisableModel = $disableModelInvocation ? 'true' : 'false';
        $yamlUserInvocable = $userInvocable ? 'true' : 'false';
        $content = <<<SKILL
---
description: $description
user-invocable: $yamlUserInvocable
disable-model-invocation: $yamlDisableModel
paths: []
---

Skill body for $name.
SKILL;
        return Skill::parse($content, $name, "/path/to/$name/SKILL.md");
    }

    private function createRegistry(array $skills): SkillRegistry
    {
        $registry = new SkillRegistry();
        foreach ($skills as $name => $skill) {
            $registry->register([$name => $skill]);
        }
        return $registry;
    }

    // -------------------------------------------------------------------------
    // listForPrompt()
    // -------------------------------------------------------------------------

    public function testListForPromptReturnsFormattedListing(): void
    {
        // Arrange
        $registry = $this->createRegistry([
            'skill-one' => $this->createSkill('skill-one', 'First skill description'),
            'skill-two' => $this->createSkill('skill-two', 'Second skill description'),
        ]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertStringStartsWith("\n\nAvailable skills (invoke via Skill tool):", $listing);
        $this->assertStringContainsString("- skill-one: First skill description", $listing);
        $this->assertStringContainsString("- skill-two: Second skill description", $listing);
    }

    public function testListForPromptFiltersToAutoInvocableOnly(): void
    {
        // Arrange — one auto-invocable, one disableModelInvocation=true (not auto-invocable)
        $registry = $this->createRegistry([
            'auto-skill' => $this->createSkill('auto-skill', 'Auto invocable skill', disableModelInvocation: false),
            'manual-skill' => $this->createSkill('manual-skill', 'Manual-only skill', disableModelInvocation: true),
        ]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertStringContainsString("- auto-skill: Auto invocable skill", $listing);
        $this->assertStringNotContainsString("- manual-skill", $listing);
        $this->assertStringNotContainsString("Manual-only skill", $listing);
    }

    public function testListForPromptReturnsEmptyStringWhenNoSkills(): void
    {
        // Arrange
        $registry = $this->createRegistry([]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertSame('', $listing);
    }

    public function testListForPromptReturnsEmptyStringWhenAllDisabled(): void
    {
        // Arrange — all skills have disableModelInvocation=true
        $registry = $this->createRegistry([
            'manual-one' => $this->createSkill('manual-one', 'Manual skill one', disableModelInvocation: true),
            'manual-two' => $this->createSkill('manual-two', 'Manual skill two', disableModelInvocation: true),
        ]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertSame('', $listing);
    }

    public function testListForPromptExcludesDisabledSkills(): void
    {
        // Arrange
        $registry = $this->createRegistry([
            'enabled-skill' => $this->createSkill('enabled-skill', 'Enabled skill'),
        ]);
        $registry->disable('enabled-skill');

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertSame('', $listing);
    }

    public function testListForPromptFormatsOneSkillPerLine(): void
    {
        // Arrange
        $registry = $this->createRegistry([
            'solo' => $this->createSkill('solo', 'Solo description'),
        ]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert — each skill on its own line after header
        $lines = explode("\n", trim($listing));
        $this->assertCount(2, $lines); // header line + one skill line
        $this->assertSame('- solo: Solo description', $lines[1]);
    }

    public function testListForPromptPreservesSkillNameAndDescriptionExactly(): void
    {
        // Arrange — description with special characters to ensure no formatting corruption
        $registry = $this->createRegistry([
            'my-skill' => $this->createSkill('my-skill', 'Does X & Y with Z (e.g., foo-bar)'),
        ]);

        $matcher = new SkillMatcher();

        // Act
        $listing = $matcher->listForPrompt($registry);

        // Assert
        $this->assertStringContainsString("- my-skill: Does X & Y with Z (e.g., foo-bar)", $listing);
    }
}
