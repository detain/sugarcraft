<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\ForeignSkillDiscovery;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

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
    use HomeSandboxTrait;

    private SkillManager $manager;
    private SkillRegistry $registry;
    private ProviderInterface $provider;
    private string $sandboxHome;

    protected function setUp(): void
    {
        // loadAll() now walks the user's foreign-skill trees as well
        // (~/.claude/skills, ~/.config/opencode/skills — crush_code.md Phase 2
        // item 6), so HOME is redirected at an empty sandbox for the whole
        // class. Without it every assertion here would depend on what the
        // developer running the suite happens to have installed for another
        // CLI, and `getSkillsForTask('...PHP...')` in particular would start
        // matching their skills instead of this test's fixtures.
        $this->sandboxHome = $this->useHomeSandbox(
            sys_get_temp_dir() . '/sugar-crush-manager-home-' . uniqid('', true),
        );

        $this->registry = new SkillRegistry();
        // Use a real SkillLoader - it's final so we can't mock
        $loader = new SkillLoader();
        $this->manager = new SkillManager($loader, $this->registry);
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        $this->removeTestProject($this->sandboxHome);

        parent::tearDown();
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
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid((string) getmypid(), true);
        $bodyMarker = 'MANAGER_LOAD_ALL_BODY_SHOULD_STAY_UNREAD_' . uniqid((string) getmypid(), true);
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
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid((string) getmypid(), true);
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
        $projectRoot = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid((string) getmypid(), true);
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

    // =========================================================================
    // loadAll() - foreign imports (crush_code.md Phase 2 item 6)
    //
    // ForeignSkillDiscovery, its SkillSource tagging and its own tests all
    // existed; nothing called it, so a skill under .claude/skills or
    // .opencode/skills was invisible to every consumer of this registry.
    // =========================================================================

    public function testLoadAllDiscoversProjectClaudeSkills(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($projectRoot . '/.claude/skills/claude-only', 'A Claude Code skill');

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('claude-only');
            $this->assertNotNull($skill, 'a .claude/skills tree must reach the registry');
            $this->assertSame(SkillSource::Claude, $skill->source, 'the provenance tag must survive the merge');
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    public function testLoadAllDiscoversProjectOpencodeSkills(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($projectRoot . '/.opencode/skills/opencode-only', 'An opencode skill');

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('opencode-only');
            $this->assertNotNull($skill);
            $this->assertSame(SkillSource::Opencode, $skill->source);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    public function testLoadAllDiscoversForeignSkillsInTheUsersHome(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($this->sandboxHome . '/.claude/skills/home-claude', 'A home Claude skill');

        try {
            $this->manager->loadAll($projectRoot);

            $this->assertNotNull($this->registry->get('home-claude'));
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * THE COLLISION RULE: native wins. Wiring a new discovery source in must
     * not silently re-point a name that already resolved — cloning a repo that
     * happens to carry a `.claude/skills/deploy` may not replace the
     * `.sugar-crush/skills/deploy` the user wrote.
     */
    public function testANativeSkillWinsANameCollisionWithAForeignOne(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($projectRoot . '/.claude/skills/deploy', 'Foreign deploy');
        $this->writeSkill($projectRoot . '/.sugar-crush/skills/deploy', 'Native deploy');

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('deploy');
            $this->assertNotNull($skill);
            $this->assertSame('Native deploy', $skill->description);
            $this->assertSame(SkillSource::Native, $skill->source);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * The rule is a COLLISION rule, not a precedence-by-source one: a foreign
     * skill whose name nothing else claims is registered normally.
     */
    public function testAForeignSkillWithNoCollisionIsKept(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($projectRoot . '/.claude/skills/deploy', 'Foreign deploy');
        $this->writeSkill($projectRoot . '/.sugar-crush/skills/release', 'Native release');

        try {
            $this->manager->loadAll($projectRoot);

            $this->assertNotNull($this->registry->get('deploy'));
            $this->assertNotNull($this->registry->get('release'));
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * The foreign discovery is injectable, so a caller that wants a different
     * one (or none of the disk walking at all) can supply it — the seam the
     * default `new ForeignSkillDiscovery($loader)` fills.
     */
    public function testTheForeignDiscoveryIsInjectable(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($projectRoot . '/.claude/skills/claude-only', 'A Claude Code skill');

        $registry = new SkillRegistry();
        $manager = new SkillManager(new SkillLoader(), $registry, new ForeignSkillDiscovery(new SkillLoader()));

        try {
            $manager->loadAll($projectRoot);

            $this->assertNotNull($registry->get('claude-only'));
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * THE CROSS-TREE COLLISION HAD NO TEST AT ALL. Swapping the two
     * `discover*()` calls in {@see SkillManager::loadAll()} used to be a
     * mutation the whole suite survived, which meant the documented "opencode
     * wins over Claude" was an unenforced comment.
     */
    public function testOpencodeWinsANameCollisionWithClaudeAcrossTheUsersTrees(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($this->sandboxHome . '/.claude/skills/db-query', 'The Claude copy');
        $this->writeSkill($this->sandboxHome . '/.config/opencode/skills/db-query', 'The opencode copy');

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('db-query');
            $this->assertNotNull($skill);
            $this->assertSame('The opencode copy', $skill->description);
            $this->assertSame(SkillSource::Opencode, $skill->source);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * The same collision through the layout that actually broke it: a skill
     * directory that is a SYMLINK. The walk used to skip those silently, so on
     * a machine where the opencode tree is linked in from a shared checkout
     * — 8 of 14 entries on the box this was found on — the Claude copy won,
     * and which tree won depended on filesystem shape rather than on the
     * documented order.
     */
    public function testOpencodeStillWinsWhenItsSkillDirectoryIsASymlink(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($this->sandboxHome . '/.claude/skills/db-query', 'The Claude copy');

        $realSkill = $this->sandboxHome . '/shared-checkout/db-query';
        $this->writeSkill($realSkill, 'The opencode copy');
        mkdir($this->sandboxHome . '/.config/opencode/skills', 0777, true);
        if (!@symlink($realSkill, $this->sandboxHome . '/.config/opencode/skills/db-query')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('db-query');
            $this->assertNotNull($skill, 'a symlinked skill directory must still be discovered');
            $this->assertSame('The opencode copy', $skill->description);
            $this->assertSame(SkillSource::Opencode, $skill->source);
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * The user's own imported skill outranks one that arrived with a cloned
     * repository — a clone may ADD a skill, never re-point one you rely on.
     */
    public function testAClonedRepositoryCannotRePointTheUsersOwnImportedSkill(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($this->sandboxHome . '/.claude/skills/db-query', 'The copy I wrote');
        $this->writeSkill($projectRoot . '/.claude/skills/db-query', 'The copy the repo shipped');
        $this->writeSkill($projectRoot . '/.claude/skills/repo-only', 'Something new the repo adds');

        try {
            $this->manager->loadAll($projectRoot);

            $skill = $this->registry->get('db-query');
            $this->assertNotNull($skill);
            $this->assertSame('The copy I wrote', $skill->description);
            $this->assertNotNull($this->registry->get('repo-only'), 'a non-colliding project skill is still imported');
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * THE END-TO-END ESCAPE, at the layer a real launch enters through. A
     * cloned repository carrying `.claude/skills/escape -> $HOME` had
     * loadAll() register a skill whose BODY was a file out of the user's home
     * directory -- and a skill body is prompt context the model reads.
     */
    public function testAClonedRepositorysSymlinkCannotReadTheHomeDirectoryIntoASkillBody(): void
    {
        $projectRoot = $this->makeProject();
        $this->writeSkill($this->sandboxHome . '/private', 'Not a skill at all');
        file_put_contents(
            $this->sandboxHome . '/private/SKILL.md',
            "---\ndescription: leaked\n---\n\nAPI_KEY=hunter2\n",
        );

        mkdir($projectRoot . '/.claude/skills', 0777, true);
        if (!@symlink($this->sandboxHome, $projectRoot . '/.claude/skills/escape')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        try {
            $this->manager->loadAll($projectRoot);

            $this->assertNull($this->registry->get('escape/private'));
            foreach ($this->registry->all() as $skill) {
                $this->assertStringNotContainsString('hunter2', $skill->content);
            }
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    /**
     * ONE LOADER FOR BOTH WALKS. The default ForeignSkillDiscovery shares the
     * loader it was handed rather than building its own, so a caller that
     * configured a loader gets it used for the foreign tree too -- and the
     * skip diagnostics from both walks land in ONE place.
     */
    public function testTheForeignWalkSharesTheLoaderItWasConstructedWith(): void
    {
        $projectRoot = $this->makeProject();
        mkdir($projectRoot . '/.claude/skills/broken', 0777, true);
        file_put_contents($projectRoot . '/.claude/skills/broken/SKILL.md', "---\n: : not: yaml: [\n---\n\nBody.");

        $loader = new SkillLoader(reportSkips: false);
        $manager = new SkillManager($loader, new SkillRegistry());

        try {
            $manager->loadAll($projectRoot);

            $this->assertArrayHasKey($projectRoot . '/.claude/skills/broken/SKILL.md', $loader->skipped());
            $this->assertSame($loader->skipped(), $manager->skipped());
        } finally {
            $this->removeTestProject($projectRoot);
        }
    }

    private function makeProject(): string
    {
        $dir = sys_get_temp_dir() . '/sugar-crush-manager-test-' . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function writeSkill(string $skillDir, string $description): void
    {
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\ndescription: {$description}\n---\n\nBody.\n");
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
            // isLink() first: a symlink to a directory answers true to isDir()
            // and cannot be rmdir()'d.
            $item->isLink() || !$item->isDir()
                ? unlink($item->getPathname())
                : rmdir($item->getPathname());
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
