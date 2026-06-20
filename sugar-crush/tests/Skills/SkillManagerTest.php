<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * @see SkillManager
 *
 * NOTE: SkillLoader is 'final' so we cannot mock it. We use a real SkillLoader
 * pointed at a non-existent directory to simulate an empty loader.
 *
 * KNOWN BUG: App::withEnabledSkills() uses a buggy mutate() method that tries
 * to modify readonly properties on a cloned instance. PHP 8.1+ does not allow
 * this. Methods that call withEnabledSkills() (applyToApp, enable, disable when
 * skill exists) cannot be fully tested until the App bug is fixed.
 */
final class SkillManagerTest extends TestCase
{
    private SkillManager $manager;
    private SkillRegistry $registry;
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->registry = new SkillRegistry();
        // Use a real SkillLoader - it's final so we can't mock
        $loader = new SkillLoader();
        $this->manager = new SkillManager($loader, $this->registry);
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    private function createSkill(string $name, string $description = 'Test skill'): Skill
    {
        $content = <<<SKILL
---
description: $description
user-invocable: true
paths: []
---

Skill content for $name.
SKILL;
        return Skill::parse($content, $name, "/path/to/$name/SKILL.md");
    }

    // =========================================================================
    // getSkillsForTask() - delegates to registry.findForPrompt()
    // =========================================================================

    public function testGetSkillsForTask(): void
    {
        // Arrange
        $skill = $this->createSkill('php-dev', 'PHP Laravel developer');
        $this->registry->register(['php-dev' => $skill]);

        // Act
        $result = $this->manager->getSkillsForTask('I need a Laravel developer');

        // Assert
        $this->assertNotEmpty($result);
        $this->assertSame('php-dev', $result[0]->name);
    }

    public function testGetSkillsForTaskNoMatch(): void
    {
        // Arrange
        $skill = $this->createSkill('php-dev', 'PHP developer');
        $this->registry->register(['php-dev' => $skill]);

        // Act
        $result = $this->manager->getSkillsForTask('I need Ruby expertise');

        // Assert
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getSkillsForPaths() - delegates to registry.getForPaths()
    // =========================================================================

    public function testGetSkillsForPaths(): void
    {
        // Arrange
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
        $this->registry->register(['php-skill' => $phpSkill]);

        // Act
        $result = $this->manager->getSkillsForPaths(['/src/App.php']);

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('php-skill', $result[0]->name);
    }

    public function testGetSkillsForPathsNoMatch(): void
    {
        // Arrange
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
        $this->registry->register(['php-skill' => $phpSkill]);

        // Act
        $result = $this->manager->getSkillsForPaths(['/var/log/system.log']);

        // Assert
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getUserInvocable() - delegates to registry.getUserInvocable()
    // =========================================================================

    public function testGetUserInvocable(): void
    {
        // Arrange
        $userSkill = $this->createSkill('user-skill', 'User invocable skill');
        $nonUserSkill = Skill::parse(
            <<<SKILL
---
description: System only skill
user-invocable: false
paths: []
---
System content
SKILL,
            'system-skill'
        );
        $this->registry->register(['user-skill' => $userSkill, 'system-skill' => $nonUserSkill]);

        // Act
        $result = $this->manager->getUserInvocable();

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('user-skill', $result[0]->name);
    }

    public function testGetUserInvocableEmpty(): void
    {
        // Arrange - no skills registered

        // Act
        $result = $this->manager->getUserInvocable();

        // Assert
        $this->assertEmpty($result);
    }

    // =========================================================================
    // disableFromConfig() - delegates to registry.disableMultiple()
    // =========================================================================

    public function testDisableFromConfig(): void
    {
        // Arrange
        $skill1 = $this->createSkill('skill-one', 'First skill');
        $skill2 = $this->createSkill('skill-two', 'Second skill');
        $this->registry->register(['skill-one' => $skill1, 'skill-two' => $skill2]);

        // Act
        $this->manager->disableFromConfig(['skill-one']);

        // Assert
        $this->assertTrue($this->registry->isDisabled('skill-one'));
        $this->assertFalse($this->registry->isDisabled('skill-two'));
    }

    // =========================================================================
    // Methods blocked by App::withEnabledSkills() bug
    // =========================================================================

    /**
     * NOTE: The following methods cannot be fully tested due to App bug:
     * - applyToApp() - calls withEnabledSkills()
     * - enable() - calls withEnabledSkills() when skill exists
     * - disable() - calls withEnabledSkills()
     *
     * Only the early-return cases can be tested:
     * - enable() with non-existent skill returns same app (WORKS)
     */
    public function testEnableNonexistentSkillReturnsSameApp(): void
    {
        // Arrange
        $app = App::new($this->provider, 'gpt-4');

        // Act
        $result = $this->manager->enable($app, 'non-existent-skill');

        // Assert - same instance because skill doesn't exist (early return)
        $this->assertSame($app, $result);
    }
}
