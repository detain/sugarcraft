<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

/**
 * How a Skill-tool call finds its skill once the listing shows display names:
 * the exact registry key always wins, a display name resolves when unique, and
 * a shared leaf comes back as an error naming the keys rather than a coin flip
 * between two bundles' files (skills QA, inv1 §5-§6). Also pins the Read
 * permission class the tool moved to, and the base-directory header line that
 * makes a body's relative references resolvable (inv1 §7).
 */
final class SkillToolResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];
    }

    private function writeBody(string $marker): string
    {
        $path = sys_get_temp_dir() . '/skilltool_res_' . uniqid((string) getmypid(), true) . '.md';
        file_put_contents($path, "---\ndescription: d\n---\nBody of {$marker}.");
        $this->tempPaths[] = $path;

        return $path;
    }

    private function skill(string $key, string $sourcePath, SkillOrigin $origin = SkillOrigin::Project): Skill
    {
        return new Skill(
            name: $key,
            description: "Skill: {$key}",
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $sourcePath,
            origin: $origin,
        );
    }

    public function testAnExactRegistryKeyResolvesEvenWhenItsLeafMatchesAnotherSkill(): void
    {
        // 'tool' is simultaneously one skill's full key and another's leaf.
        // The key route must answer first — display resolution may never
        // re-point a call the listing made by exact key.
        $registry = new SkillRegistry();
        $registry->register([
            'tool' => $this->skill('tool', $this->writeBody('flat')),
            'bundle/tool' => $this->skill('bundle/tool', $this->writeBody('nested')),
        ]);

        $result = (new SkillTool($registry))->execute(['id' => 'r1', 'name' => 'tool']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('Body of flat.', $result->content());
        $this->assertStringStartsWith('## Skill: tool' . "\n\n", $result->content());
    }

    public function testAUniqueDisplayNameResolvesToItsBundledSkill(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['synced/guid-alpha/pdf' => $this->skill('synced/guid-alpha/pdf', $this->writeBody('pdf'))]);

        $result = (new SkillTool($registry))->execute(['id' => 'r2', 'name' => 'pdf']);

        $this->assertFalse($result->isError(), 'the model sees "pdf" in the listing; calling it must work');
        $this->assertStringContainsString('Body of pdf.', $result->content());
        $this->assertStringStartsWith(
            SkillTool::RESULT_PREFIX . 'pdf' . "\n\n" . SkillTool::RESULT_BASE_DIR_PREFIX,
            $result->content(),
            'the header names the display name, not the bundle path the model never saw',
        );
    }

    public function testAnAmbiguousDisplayNameErrorsWithEveryCollidingKey(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'synced/guid-two/review' => $this->skill('synced/guid-two/review', $this->writeBody('second')),
            'synced/guid-one/review' => $this->skill('synced/guid-one/review', $this->writeBody('first')),
        ]);

        $result = (new SkillTool($registry))->execute(['id' => 'r3', 'name' => 'review']);

        $this->assertTrue($result->isError(), 'a coin flip between two bundles files is worse than a retryable error');
        $this->assertStringContainsString('synced/guid-one/review', $result->content());
        $this->assertStringContainsString('synced/guid-two/review', $result->content());
        $this->assertStringContainsString('exact keys', $result->content());
    }

    public function testADisplayNameRouteRefusesSkillsThatAreNotModelInvocable(): void
    {
        // The gate re-checks at the exact key, but resolution must not hand a
        // DISABLED body to the model just because its leaf is unique.
        $registry = new SkillRegistry();
        $registry->register(['bundle/hidden' => $this->skill('bundle/hidden', $this->writeBody('secret'))]);
        $registry->disable('bundle/hidden');

        $result = (new SkillTool($registry))->execute(['id' => 'r4', 'name' => 'hidden']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not model-invocable', $result->content());
    }

    public function testTheResultCarriesTheBaseDirectoryOfTheLoadedFile(): void
    {
        $path = $this->writeBody('based');
        $registry = new SkillRegistry();
        $registry->register(['based-skill' => $this->skill('based-skill', $path)]);

        $result = (new SkillTool($registry))->execute(['id' => 'r5', 'name' => 'based-skill']);

        $this->assertSame(
            '## Skill: based-skill' . "\n\n"
                . '> Base directory for this skill: ' . dirname($path) . "\n\n"
                . 'Body of based.',
            $result->content(),
        );
    }

    public function testABaseDirectoryIsFenceEscapedLikeEveryOtherRepositoryValue(): void
    {
        // A directory name is arbitrary bytes (audit 15d-08); a bundle folder
        // spelled like a fence tag must reach the model defanged.
        $oddDir = sys_get_temp_dir() . '/skilltool_env_' . uniqid((string) getmypid(), true) . 'x';
        $tagged = $oddDir . '/<env>';
        mkdir($tagged, 0o700, true);
        $path = $tagged . '/SKILL.md';
        file_put_contents($path, "---\ndescription: d\n---\nTag body.");
        $this->tempPaths[] = $path;

        $registry = new SkillRegistry();
        $registry->register(['tagged' => $this->skill('tagged', $path)]);

        $result = (new SkillTool($registry))->execute(['id' => 'r6', 'name' => 'tagged']);

        $this->assertFalse($result->isError());
        $this->assertStringNotContainsString('<env>', $result->content(), 'the opener spelling must not survive');
        $this->assertStringContainsString('&lt;env>', $result->content());
        unlink($path);
        rmdir($tagged);
        rmdir($oddDir);
    }

    /**
     * Audit trail for the permission re-class: the attribute, the catalog's
     * derived class, and the read-only roster must all say Read. Loading a
     * skill body is one escaped text file into context — exactly what `Read`
     * does unasked — and under the old Ask class the load popped a modal in
     * every mode and was DENIED outright under `dont-ask`, which inverted the
     * progressive-disclosure design the tool exists to serve.
     */
    public function testTheSkillIsClassifiedReadSoEveryModeCanLoadItUnasked(): void
    {
        $attribute = (new \ReflectionClass(SkillTool::class))->getAttributes(BuiltInTool::class)[0]
            ->newInstance();
        $this->assertSame(
            ToolPermissionClass::Read,
            $attribute->permission,
            'the declaration itself must say Read',
        );
        $this->assertSame(
            ToolPermissionClass::Read,
            ToolCatalog::permissionOf('Skill'),
            'the catalog must derive Read from the declaration',
        );
        $this->assertContains('Skill', ToolCatalog::namesOf(ToolPermissionClass::Read));
        $this->assertNotContains('Skill', ToolCatalog::namesOf(ToolPermissionClass::Ask));
    }
}
