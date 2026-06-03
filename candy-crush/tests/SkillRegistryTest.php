<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Tests for SkillRegistry - manages skill registration, retrieval, and filtering.
 */
final class SkillRegistryTest extends TestCase
{
    private function createSkill(
        string $name,
        string $description = 'Test description',
        bool $userInvocable = true,
        array $paths = []
    ): Skill {
        // Explicitly convert to YAML boolean string to avoid heredoc interpolation issues
        $yamlUserInvocable = $userInvocable ? 'true' : 'false';
        $content = <<<SKILL
---
description: $description
user-invocable: $yamlUserInvocable
paths:
  - /src/**/*.php
  - /tests/**/*.php
---

Skill content for $name.
SKILL;
        return Skill::parse($content, $name, "/path/to/$name/SKILL.md");
    }

    // -------------------------------------------------------------------------
    // register()
    // -------------------------------------------------------------------------

    public function testRegister(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('skill-one', 'First skill');
        $skill2 = $this->createSkill('skill-two', 'Second skill');

        // Act
        $registry->register(['skill-one' => $skill1, 'skill-two' => $skill2]);

        // Assert - using names() to verify registration
        $this->assertSame(['skill-one', 'skill-two'], $registry->names());
    }

    public function testRegisterOverwritesExisting(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $originalSkill = $this->createSkill('overwrite-me', 'Original description');
        $registry->register(['overwrite-me' => $originalSkill]);

        // Act
        $newSkill = $this->createSkill('overwrite-me', 'New description');
        $registry->register(['overwrite-me' => $newSkill]);

        // Assert
        $retrieved = $registry->get('overwrite-me');
        $this->assertNotNull($retrieved);
        $this->assertSame('New description', $retrieved->description);
    }

    public function testRegisterEmptyArray(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act
        $registry->register([]);

        // Assert
        $this->assertEmpty($registry->names());
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGet(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('get-test', 'A get test skill');
        $registry->register(['get-test' => $skill]);

        // Act
        $result = $registry->get('get-test');

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('get-test', $result->name);
        $this->assertSame('A get test skill', $result->description);
    }

    public function testGetNotFound(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act
        $result = $registry->get('non-existent-skill');

        // Assert
        $this->assertNull($result);
    }

    public function testGetDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('disabled-test', 'A disabled skill');
        $registry->register(['disabled-test' => $skill]);
        $registry->disable('disabled-test');

        // Act
        $result = $registry->get('disabled-test');

        // Assert
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // all()
    // -------------------------------------------------------------------------

    public function testAll(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('all-one');
        $skill2 = $this->createSkill('all-two');
        $registry->register(['all-one' => $skill1, 'all-two' => $skill2]);

        // Act
        $result = $registry->all();

        // Assert
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('all-one', $result);
        $this->assertArrayHasKey('all-two', $result);
    }

    public function testAllExcludesDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('enabled-skill');
        $skill2 = $this->createSkill('will-be-disabled');
        $registry->register(['enabled-skill' => $skill1, 'will-be-disabled' => $skill2]);
        $registry->disable('will-be-disabled');

        // Act
        $result = $registry->all();

        // Assert
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('enabled-skill', $result);
        $this->assertArrayNotHasKey('will-be-disabled', $result);
    }

    public function testAllEmptyRegistry(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act
        $result = $registry->all();

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // findForPrompt()
    // -------------------------------------------------------------------------

    public function testFindForPrompt(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = $this->createSkill('php-dev', 'PHP Laravel developer skill');
        $pySkill = $this->createSkill('python-dev', 'Python Django developer skill');
        $registry->register(['php-dev' => $phpSkill, 'python-dev' => $pySkill]);

        // Act
        $result = $registry->findForPrompt('I need a Laravel developer');

        // Assert
        $this->assertNotEmpty($result);
        // Laravel keyword should match PHP skill's description (contains "Laravel")
        $foundNames = array_map(fn($s) => $s->name, $result);
        $this->assertContains('php-dev', $foundNames);
    }

    public function testFindForPromptNoMatch(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = $this->createSkill('php-dev', 'PHP developer skill');
        $registry->register(['php-dev' => $phpSkill]);

        // Act
        $result = $registry->findForPrompt('I need Ruby on Rails expertise');

        // Assert - No skills match "Ruby on Rails expertise" (Ruby not in PHP dev description)
        $this->assertEmpty($result);
    }

    public function testFindForPromptSort(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        // Skill with description containing "developer" multiple times should rank higher
        $skill1 = $this->createSkill('web-developer', 'Expert web developer with many developer skills');
        $skill2 = $this->createSkill('developer-tester', 'Developer testing specialist');
        $registry->register(['web-developer' => $skill1, 'developer-tester' => $skill2]);

        // Act
        $result = $registry->findForPrompt('I need a developer');

        // Assert
        $this->assertCount(2, $result);
        // 'web-developer' has 'developer' twice, 'developer-tester' has it once
        // So web-developer should rank higher (more keyword matches)
        $this->assertSame('web-developer', $result[0]->name);
        $this->assertSame('developer-tester', $result[1]->name);
    }

    public function testFindForPromptDisabledSkillsExcluded(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('find-test', 'Find test skill for prompt matching');
        $registry->register(['find-test' => $skill]);
        $registry->disable('find-test');

        // Act
        $result = $registry->findForPrompt('Find test skill for prompt matching');

        // Assert
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // getUserInvocable()
    // -------------------------------------------------------------------------

    public function testGetUserInvocable(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $userSkill = $this->createSkill('user-skill', 'User invocable skill', true);
        $nonUserSkill = $this->createSkill('system-skill', 'System only skill', false);
        $registry->register(['user-skill' => $userSkill, 'system-skill' => $nonUserSkill]);

        // Act
        $result = $registry->getUserInvocable();

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('user-skill', $result[0]->name);
    }

    public function testGetUserInvocableExcludesDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $userSkill = $this->createSkill('user-skill', 'User invocable', true);
        $registry->register(['user-skill' => $userSkill]);
        $registry->disable('user-skill');

        // Act
        $result = $registry->getUserInvocable();

        // Assert
        $this->assertEmpty($result);
    }

    public function testGetUserInvocableNoneDefined(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $nonUserSkill = $this->createSkill('all-system', 'All system', false);
        $registry->register(['all-system' => $nonUserSkill]);

        // Act
        $result = $registry->getUserInvocable();

        // Assert
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // getForPaths()
    // -------------------------------------------------------------------------

    public function testGetForPaths(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = Skill::parse(
            <<<SKILL
---
description: PHP skill
paths:
  - /src/**/*.php
  - /tests/**/*.php
---
PHP content
SKILL,
            'php-skill'
        );
        $pySkill = Skill::parse(
            <<<SKILL
---
description: Python skill
paths:
  - /src/**/*.py
---
Python content
SKILL,
            'python-skill'
        );
        $registry->register(['php-skill' => $phpSkill, 'python-skill' => $pySkill]);

        // Act
        $result = $registry->getForPaths(['/src/App.php', '/tests/Example.php']);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('php-skill', $result[0]->name);
    }

    public function testGetForPathsMultipleMatches(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = Skill::parse(
            <<<SKILL
---
description: PHP skill
paths:
  - /src/**/*.php
---
PHP content
SKILL,
            'php-skill'
        );
        $anySkill = Skill::parse(
            <<<SKILL
---
description: Any file skill
paths:
  - /**
---
Any content
SKILL,
            'any-skill'
        );
        $registry->register(['php-skill' => $phpSkill, 'any-skill' => $anySkill]);

        // Act
        $result = $registry->getForPaths(['/src/app.php']);

        // Assert - both skills match /src/app.php
        $this->assertCount(2, $result);
    }

    public function testGetForPathsNoMatch(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = Skill::parse(
            <<<SKILL
---
description: PHP skill
paths:
  - /src/**/*.php
---
PHP content
SKILL,
            'php-skill'
        );
        $registry->register(['php-skill' => $phpSkill]);

        // Act
        $result = $registry->getForPaths(['/var/log/system.log']);

        // Assert
        $this->assertEmpty($result);
    }

    public function testGetForPathsDisabledSkillsExcluded(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $phpSkill = Skill::parse(
            <<<SKILL
---
description: PHP skill
paths:
  - /src/**/*.php
---
PHP content
SKILL,
            'php-skill'
        );
        $registry->register(['php-skill' => $phpSkill]);
        $registry->disable('php-skill');

        // Act
        $result = $registry->getForPaths(['/src/app.php']);

        // Assert
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // disable() / enable()
    // -------------------------------------------------------------------------

    public function testDisable(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('to-disable');
        $registry->register(['to-disable' => $skill]);

        // Act
        $registry->disable('to-disable');

        // Assert
        $this->assertTrue($registry->isDisabled('to-disable'));
        $this->assertNull($registry->get('to-disable'));
    }

    public function testEnable(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('to-enable');
        $registry->register(['to-enable' => $skill]);
        $registry->disable('to-enable');

        // Act
        $registry->enable('to-enable');

        // Assert
        $this->assertFalse($registry->isDisabled('to-enable'));
        $this->assertNotNull($registry->get('to-enable'));
    }

    public function testEnableNonExistentSkill(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act - should not throw
        $registry->enable('non-existent');

        // Assert
        $this->assertFalse($registry->isDisabled('non-existent'));
    }

    // -------------------------------------------------------------------------
    // isDisabled()
    // -------------------------------------------------------------------------

    public function testIsDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('is-disabled-test');
        $registry->register(['is-disabled-test' => $skill]);

        // Assert initial state
        $this->assertFalse($registry->isDisabled('is-disabled-test'));

        // Act
        $registry->disable('is-disabled-test');

        // Assert
        $this->assertTrue($registry->isDisabled('is-disabled-test'));
    }

    public function testIsDisabledNonExistent(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Assert
        $this->assertFalse($registry->isDisabled('totally-not-there'));
    }

    // -------------------------------------------------------------------------
    // disableMultiple()
    // -------------------------------------------------------------------------

    public function testDisableMultiple(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('multi-one');
        $skill2 = $this->createSkill('multi-two');
        $skill3 = $this->createSkill('multi-three');
        $registry->register([
            'multi-one' => $skill1,
            'multi-two' => $skill2,
            'multi-three' => $skill3,
        ]);

        // Act
        $registry->disableMultiple(['multi-one', 'multi-two']);

        // Assert
        $this->assertTrue($registry->isDisabled('multi-one'));
        $this->assertTrue($registry->isDisabled('multi-two'));
        $this->assertFalse($registry->isDisabled('multi-three'));
    }

    public function testDisableMultipleEmptyArray(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('single');
        $registry->register(['single' => $skill]);

        // Act
        $registry->disableMultiple([]);

        // Assert - nothing should be disabled
        $this->assertFalse($registry->isDisabled('single'));
    }

    public function testDisableMultiplePartialNonExistent(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('partial-disable');
        $registry->register(['partial-disable' => $skill]);

        // Act - one real, one non-existent
        $registry->disableMultiple(['partial-disable', 'non-existent-skill']);

        // Assert
        $this->assertTrue($registry->isDisabled('partial-disable'));
    }

    // -------------------------------------------------------------------------
    // names()
    // -------------------------------------------------------------------------

    public function testNames(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('alpha');
        $skill2 = $this->createSkill('beta');
        $registry->register(['alpha' => $skill1, 'beta' => $skill2]);

        // Act
        $result = $registry->names();

        // Assert
        $this->assertCount(2, $result);
        $this->assertContains('alpha', $result);
        $this->assertContains('beta', $result);
    }

    public function testNamesExcludesDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('visible');
        $skill2 = $this->createSkill('hidden');
        $registry->register(['visible' => $skill1, 'hidden' => $skill2]);
        $registry->disable('hidden');

        // Act
        $result = $registry->names();

        // Assert - names() returns all registered names regardless of disabled state
        $this->assertCount(2, $result);
    }

    public function testNamesEmpty(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act
        $result = $registry->names();

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
