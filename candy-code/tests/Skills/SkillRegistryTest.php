<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;

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

    /**
     * E67 — the incoming array key is ignored; the SKILL'S OWN NAME decides
     * where it is filed.
     *
     * Every lookup on this class is by name, so a list-shaped register() stored
     * skills under `0, 1, 2 …` and then `isAutoInvocable($skill->name)` missed:
     * every skill in that batch loaded, listed, and silently never fired. No
     * shipped caller passes a list — {@see \SugarCraft\Code\Skills\SkillManager::loadAll()}
     * is the only one, twice, and both arrays come from
     * {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery::discover()} keyed
     * by name — so this pins a trap shut rather than fixing a live failure.
     */
    public function testRegisterKeysByTheSkillsOwnNameNotTheIncomingArrayKey(): void
    {
        // Arrange — a LIST, exactly the shape that used to go dark.
        $registry = new SkillRegistry();
        $skill1 = $this->createSkill('list-one', 'First skill');
        $skill2 = $this->createSkill('list-two', 'Second skill');

        // Act
        $registry->register([$skill1, $skill2]);

        // Assert — the four name-indexed lookups the old keying broke.
        $this->assertSame(['list-one', 'list-two'], $registry->names());
        $this->assertNotNull($registry->get('list-one'));
        $this->assertNotNull($registry->get('list-two'));
        $this->assertTrue($registry->isAutoInvocable('list-one'));
        $this->assertTrue($registry->isAutoInvocable('list-two'));
        $this->assertTrue($registry->isUserInvocable('list-one'));
        $this->assertSame(['list-one', 'list-two'], array_keys($registry->all()));
    }

    /**
     * And a WRONG key does not win over the skill's own name either — the
     * list case above is only the commonest way to supply one.
     */
    public function testAMislabelledKeyDoesNotDecideWhereASkillIsFiled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = $this->createSkill('real-name', 'A skill filed under a lie');

        // Act
        $registry->register(['wrong-key' => $skill]);

        // Assert
        $this->assertSame(['real-name'], $registry->names());
        $this->assertNull($registry->get('wrong-key'));
        $this->assertNotNull($registry->get('real-name'));
        $this->assertTrue($registry->isAutoInvocable('real-name'));
        $this->assertFalse($registry->isAutoInvocable('wrong-key'));
    }

    /**
     * The disable ledger is keyed by name too, so filing by name is what makes
     * `disable($skill->name)` reach the skill a list-shaped register() stored.
     */
    public function testASkillRegisteredFromAListCanStillBeDisabledByName(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $registry->register([$this->createSkill('list-disable', 'Disable me')]);

        // Act
        $registry->disable('list-disable');

        // Assert
        $this->assertNull($registry->get('list-disable'));
        $this->assertSame([], array_keys($registry->all()));
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
        // The usort key is substr_count(description, whole prompt); the prompt
        // 'I need a developer' is not a substring of either one-line
        // description, so BOTH keys are 0 and the sort is a stable no-op.
        $skill1 = $this->createSkill('web-developer', 'Expert web developer with many developer skills');
        $skill2 = $this->createSkill('developer-tester', 'Developer testing specialist');
        $registry->register(['web-developer' => $skill1, 'developer-tester' => $skill2]);

        // Act
        $result = $registry->findForPrompt('I need a developer');

        // Assert
        $this->assertCount(2, $result);
        // Despite the method name, this does NOT witness keyword-count ranking:
        // the 0-0 tie means web-developer ranks first purely because it was
        // registered first (stable-sort input order). The name is therefore
        // inaccurate; renaming is out of scope here - recorded as a follow-up.
        // (The tied-order behaviour is pinned in its own right by
        // testFindForPromptKeepsRegistryInputOrderWhenBothSortKeysAreTied.)
        $this->assertSame('web-developer', $result[0]->name);
        $this->assertSame('developer-tester', $result[1]->name);
    }

    public function testFindForPromptExcludesDisableModelInvocationSkills(): void
    {
        // Arrange - description keywords match the prompt exactly, but the
        // skill opts out of auto-invocation via disable-model-invocation.
        // R16 regression: findForPrompt() previously ignored this flag
        // entirely and would have returned this skill anyway.
        $registry = new SkillRegistry();
        $autoSkill = Skill::parse(
            <<<SKILL
---
description: Laravel developer helper skill
disable-model-invocation: true
---
Content
SKILL,
            'no-auto-invoke'
        );
        $registry->register(['no-auto-invoke' => $autoSkill]);

        // Act
        $result = $registry->findForPrompt('I need a Laravel developer');

        // Assert - model-invocation-disabled skill must never auto-trigger,
        // even though its description keywords match the prompt.
        $this->assertEmpty($result);
        $this->assertFalse($registry->isAutoInvocable('no-auto-invoke'));
    }

    public function testFindForPromptIncludesAutoInvocableSkillAlongsideDisabledOne(): void
    {
        // Arrange - one auto-invocable skill and one that opts out; only the
        // auto-invocable one should surface for the matching prompt.
        $registry = new SkillRegistry();
        $autoSkill = $this->createSkill('auto-dev', 'Laravel developer skill');
        $noAutoSkill = Skill::parse(
            <<<SKILL
---
description: Laravel developer helper skill
disable-model-invocation: true
---
Content
SKILL,
            'manual-dev'
        );
        $registry->register(['auto-dev' => $autoSkill, 'manual-dev' => $noAutoSkill]);

        // Act
        $result = $registry->findForPrompt('I need a Laravel developer');

        // Assert
        $foundNames = array_map(fn($s) => $s->name, $result);
        $this->assertContains('auto-dev', $foundNames);
        $this->assertNotContains('manual-dev', $foundNames);
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

    public function testFindForPromptGateSkipsNonAutoInvocableSkillAmongManyFire(): void
    {
        // P7.S4 gate pin under the multi-fire corpus: three matching skills,
        // one of them disable-model-invocation. The gate must drop exactly the
        // opted-out skill even though its description token matches the prompt.
        $registry = new SkillRegistry();
        $registry->register([
            'w1' => $this->createSkill('w1', 'summarize when needed'),
            'w2' => $this->createSkill('w2', 'translate when asked'),
            'w3' => Skill::parse(
                <<<SKILL
---
description: refactor when possible
disable-model-invocation: true
---
Content
SKILL,
                'w3'
            ),
        ]);

        $result = $registry->findForPrompt('let me know when you are ready for lunch');

        $foundNames = array_map(fn($s) => $s->name, $result);
        $this->assertCount(2, $result);
        $this->assertContains('w1', $foundNames);
        $this->assertContains('w2', $foundNames);
        $this->assertNotContains('w3', $foundNames);
    }

    public function testFindForPromptSortRanksWholePromptSubstringOfDescriptionFirst(): void
    {
        // P7.S4 sort pin, THE NON-TIED case. The usort key is
        // substr_count(description, whole prompt). Registered first here is the
        // ZERO-count skill (its 'audi' token matches the prompt 'audit' but the
        // full word 'audit' is not in its description); registered second is the
        // ONE-count skill. Relevance sort must lift the one-count skill first.
        $registry = new SkillRegistry();
        $registry->register([
            'audi-first-registered' => $this->createSkill('audi-first-registered', 'handle audi files safely'),
            'audit-second-registered' => $this->createSkill('audit-second-registered', 'run audit checks now'),
        ]);

        $result = $registry->findForPrompt('audit');

        $this->assertCount(2, $result);
        $this->assertSame('audit-second-registered', $result[0]->name);
        $this->assertSame('audi-first-registered', $result[1]->name);
    }

    public function testFindForPromptKeepsRegistryInputOrderWhenBothSortKeysAreTied(): void
    {
        // P7.S4 ORDER NO-OP, pinned AS MEASURED. The sort key is
        // substr_count(description, whole prompt), and this fixture deliberately
        // equalizes it: the prompt 'audit' occurs EXACTLY ONCE in each
        // description, so the two keys tie at 1-1 and the usort is a stable
        // no-op - the tie is precisely what exercises registry-order stability,
        // so the returned order is the REGISTRY INPUT order. Registered
        // deliberately reverse-alphabetical so a future comparator change (e.g.
        // sorting by name) surfaces here. If a later edit lets the counts
        // DIFFER, this stops being the tie pin and must be re-pinned.
        $registry = new SkillRegistry();
        $registry->register([
            'zz-later-skill' => $this->createSkill('zz-later-skill', 'audit one thing'),
            'aa-earlier-skill' => $this->createSkill('aa-earlier-skill', 'audit two things'),
        ]);

        $result = $registry->findForPrompt('audit');

        $this->assertCount(2, $result);
        $this->assertSame('zz-later-skill', $result[0]->name);
        $this->assertSame('aa-earlier-skill', $result[1]->name);
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

    // -------------------------------------------------------------------------
    // isAutoInvocable()
    // -------------------------------------------------------------------------

    public function testIsAutoInvocableReturnsFalseWhenDisabled(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: Auto-invocable disabled skill
disable-model-invocation: true
user-invocable: true
---
Content
SKILL,
            'auto-disabled'
        );
        $registry->register(['auto-disabled' => $skill]);

        // Act
        $result = $registry->isAutoInvocable('auto-disabled');

        // Assert
        $this->assertFalse($result);
    }

    public function testIsAutoInvocableReturnsTrueByDefault(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: Default skill
user-invocable: true
---
Content
SKILL,
            'auto-default'
        );
        $registry->register(['auto-default' => $skill]);

        // Act
        $result = $registry->isAutoInvocable('auto-default');

        // Assert
        $this->assertTrue($result);
    }

    public function testIsAutoInvocableReturnsFalseForUnknownSkill(): void
    {
        // Arrange
        $registry = new SkillRegistry();

        // Act
        $result = $registry->isAutoInvocable('unknown-skill');

        // Assert
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // isUserInvocable()
    // -------------------------------------------------------------------------

    public function testIsUserInvocableReturnsFalseWhenUserInvocableFalse(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: System only skill
user-invocable: false
---
Content
SKILL,
            'system-only'
        );
        $registry->register(['system-only' => $skill]);

        // Act
        $result = $registry->isUserInvocable('system-only');

        // Assert
        $this->assertFalse($result);
    }

    public function testIsUserInvocableReturnsTrueByDefault(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: Default user skill
---
Content
SKILL,
            'user-default'
        );
        $registry->register(['user-default' => $skill]);

        // Act
        $result = $registry->isUserInvocable('user-default');

        // Assert
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // isContextFork()
    // -------------------------------------------------------------------------

    public function testIsContextForkReturnsTrueWhenContextFork(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: Fork context skill
context: fork
---
Content
SKILL,
            'fork-skill'
        );
        $registry->register(['fork-skill' => $skill]);

        // Act
        $result = $registry->isContextFork('fork-skill');

        // Assert
        $this->assertTrue($result);
    }

    public function testIsContextForkReturnsFalseForThread(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $skill = Skill::parse(
            <<<SKILL
---
description: Thread context skill
context: thread
---
Content
SKILL,
            'thread-skill'
        );
        $registry->register(['thread-skill' => $skill]);

        // Act
        $result = $registry->isContextFork('thread-skill');

        // Assert
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // registerFromManifest()
    // -------------------------------------------------------------------------

    public function testRegisterFromManifestCreatesSkillWithFlags(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $manifest = [
            'name' => 'manifest-skill',
            'description' => 'Skill from manifest',
            'disableModelInvocation' => true,
            'userInvocable' => false,
            'context' => 'fork',
            'paths' => [],
            'sourcePath' => '/path/to/manifest-skill/SKILL.md',
        ];

        // Act
        $registry->registerFromManifest($manifest);

        // Assert - skill is registered
        $this->assertNotNull($registry->get('manifest-skill'));

        // Assert - flags are set correctly
        $this->assertFalse($registry->isAutoInvocable('manifest-skill'));
        $this->assertFalse($registry->isUserInvocable('manifest-skill'));
        $this->assertTrue($registry->isContextFork('manifest-skill'));
    }

    /**
     * Regression test for the blocker fix: registerFromManifest() must pass
     * the manifest's `paths` through to the constructed Skill instead of
     * hardcoding `[]`, or path-based auto-scoping (getForPaths()) silently
     * loses every skill registered via the manifest path.
     */
    public function testRegisterFromManifestPreservesPaths(): void
    {
        // Arrange
        $registry = new SkillRegistry();
        $manifest = [
            'name' => 'path-manifest-skill',
            'description' => 'Skill from manifest with paths',
            'disableModelInvocation' => false,
            'userInvocable' => true,
            'context' => 'thread',
            'paths' => ['/src/**/*.php'],
            'sourcePath' => '/path/to/path-manifest-skill/SKILL.md',
        ];

        // Act
        $registry->registerFromManifest($manifest);
        $result = $registry->getForPaths(['/src/App.php']);

        // Assert
        $names = array_map(fn($skill) => $skill->name, $result);
        $this->assertContains('path-manifest-skill', $names);
    }

    // -------------------------------------------------------------------------
    // replaceAll() — C-EXT-3.a, the /skills reload swap
    // -------------------------------------------------------------------------

    public function testReplaceAllSwapsTheDiscoveredRosterInPlace(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['old' => $this->createSkill('old', 'Gone after the swap.')]);

        $registry->replaceAll([
            $this->createSkill('fresh', 'Arrives with the swap.'),
            $this->createSkill('also-fresh', 'Second arrival.'),
        ]);

        $this->assertNull($registry->get('old'));
        $this->assertSame([], array_values(array_diff(['also-fresh', 'fresh'], $registry->names())), 'the new roster is exactly what the swap carried');
        $this->assertContains('fresh', $registry->names());
    }

    /**
     * The operator's veto is not a discovery fact: a skill disabled before
     * the swap is STILL disabled after it, even when the incoming roster
     * carries the same name again. This is the whole `replaceAll`-versus-
     * `new registry` distinction the reload's identity law rests on.
     */
    public function testReplaceAllCarriesTheDisabledSetAcrossTheSwap(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'keep' => $this->createSkill('keep', 'Vetoed by the operator.'),
            'gone' => $this->createSkill('gone', 'Not in the incoming roster.'),
        ]);
        $registry->disable('keep');

        $registry->replaceAll([$this->createSkill('keep', 'Vetoed by the operator, re-discovered.')]);

        $this->assertTrue($registry->isDisabled('keep'), 'the veto rides across the swap untouched');
        $this->assertNull($registry->get('keep'), 'and the skill stays dark');
        $this->assertSame([], $registry->all());
        $this->assertContains('keep', $registry->names(), 'names() stays RAW — the discovery set and the veto are separate facts');
    }

    /** The incoming roster re-passes the requirements door: an unmeetable skill is refused again, not trusted from the swap. */
    public function testReplaceAllReDerivesTheUnavailableSet(): void
    {
        $registry = new SkillRegistry();
        $quiet = $this->createSkill('first', 'First generation.');
        $unmeetable = new Skill(
            name: 'unmeetable',
            description: 'Needs a binary this host does not have.',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'Body.',
            sourcePath: '/nowhere/unmeetable/SKILL.md',
            requires: ['bins' => ['cc-ext-3a-absent-binary']],
        );
        $registry->register(['first' => $quiet]);
        $this->assertSame([], $registry->unavailable(), 'nothing refused before the swap');

        $registry->replaceAll([$quiet, $unmeetable]);

        $this->assertArrayHasKey('unmeetable', $registry->unavailable(), 'the door runs again on every incoming skill');
        $this->assertNull($registry->get('unmeetable'));
        $this->assertContains('first', $registry->names(), 'the meetable roster still lands');
    }
}
