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
    // loadAll() - registers Stage-1 manifests only, never the eager full
    // body (crush_feat.md section 7.E3)
    // =========================================================================

    /**
     * Regression test for the fix in crush_feat.md section 7.E3: before
     * this fix, SkillManager::loadAll() called SkillLoader::loadAll(),
     * which reads every SKILL.md's full body off disk (via
     * Skill::fromFile()) and registers it verbatim -- so the registered
     * Skill's ->content held the entire body text after loadAll() ran.
     * That defeated the whole point of the three-stage progressive
     * disclosure design: every session paid the full I/O + YAML-parse cost
     * up front regardless of whether any skill was ever invoked.
     *
     * Against the OLD SkillManager::loadAll() implementation this test
     * fails, because $registry->get('marker-skill')->content would equal
     * the marker body text instead of the empty string a manifest-only
     * registration produces.
     */
    public function testLoadAllRegistersManifestOnlyNotFullBody(): void
    {
        // Arrange
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid();
        $bodyMarker = 'MANAGER_LOAD_ALL_BODY_SHOULD_STAY_UNREAD_' . uniqid();
        mkdir($projectRoot . '/.sugar-crush/skills/marker-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/marker-skill/SKILL.md',
            "---\ndescription: Marker skill\n---\n\n{$bodyMarker}"
        );

        try {
            // Act
            $this->manager->loadAll($projectRoot);

            // Assert -- skill is registered (manifest reached the registry)...
            $skill = $this->registry->get('marker-skill');
            $this->assertNotNull($skill);
            $this->assertSame('Marker skill', $skill->description);

            // ...but its body was never read off disk.
            $this->assertSame('', $skill->content);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * Regression test: SkillManager::loadAll() (the production loading path
     * -- SkillLoader::loadAllManifests() -> SkillRegistry::registerFromManifest())
     * must preserve a skill's `paths` frontmatter so path-based auto-scoping
     * (getSkillsForPaths() / SkillRegistry::getForPaths()) keeps matching it.
     * Before this fix, loadSkillManifest() never returned a `paths` key and
     * registerFromManifest() hardcoded `paths: []`, so this always returned
     * empty despite the pattern matching exactly.
     */
    public function testLoadAllPreservesPathsForPathBasedScoping(): void
    {
        // Arrange
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid();
        mkdir($projectRoot . '/.sugar-crush/skills/path-scoped-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/path-scoped-skill/SKILL.md',
            "---\ndescription: Path scoped skill\npaths:\n  - /src/**/*.php\n---\n\nBody."
        );

        try {
            // Act
            $this->manager->loadAll($projectRoot);
            $result = $this->manager->getSkillsForPaths(['/src/App.php']);

            // Assert -- built-in skills with their own **/*.php patterns also
            // legitimately match /src/App.php now that paths flow through, so
            // assert our project skill is among the matches rather than the
            // only one.
            $names = array_map(fn($skill) => $skill->name, $result);
            $this->assertContains('path-scoped-skill', $names);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    public function testLoadAllProjectSkillOverridesRegistrySafely(): void
    {
        // Arrange
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid();
        mkdir($projectRoot . '/.sugar-crush/skills/override-skill', 0777, true);
        file_put_contents(
            $projectRoot . '/.sugar-crush/skills/override-skill/SKILL.md',
            "---\ndescription: Overridden by project\n---\n\nBody."
        );

        try {
            // Act
            $this->manager->loadAll($projectRoot);
            $result = $this->manager->getSkillsForTask('Overridden by project');

            // Assert
            $this->assertNotEmpty($result);
            $this->assertSame('override-skill', $result[0]->name);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    private function removeTestProject(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
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
