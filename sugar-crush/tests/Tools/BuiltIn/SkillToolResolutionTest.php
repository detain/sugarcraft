<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillPromptLine;
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

    /**
     * M2 (skills QA fix round 1): an EXACT key the registry withholds because
     * `disabledSkills` lists it must come back naming that state — never fall
     * through the display-name ladder, where a different enabled skill whose
     * leaf shares that name would be loaded in its place. That fall-through
     * silently rewrites a user's deliberate disable into another skill's
     * instructions: here `tool` is disabled, but `bundle/tool` displays under
     * the same leaf `tool`, so a naive fall-through would load the sibling.
     */
    public function testADisabledExactKeyRefusesInsteadOfLoadingTheSiblingUnderItsLeaf(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'tool' => $this->skill('tool', $this->writeBody('disabled-one')),
            'bundle/tool' => $this->skill('bundle/tool', $this->writeBody('sibling')),
        ]);
        $registry->disable('tool');

        $result = (new SkillTool($registry))->execute(['id' => 'r7', 'name' => 'tool']);

        $this->assertTrue($result->isError(), 'the key route answers the KEY, enabled or not');
        $this->assertStringContainsString('disabled', $result->content());
        $this->assertStringContainsString('disabledSkills', $result->content());
        $this->assertStringNotContainsString('Body of sibling.', $result->content(),
            'the disabled key must not silently re-point at the enabled sibling sharing its leaf');
        $this->assertStringNotContainsString('Body of disabled-one.', $result->content());
    }

    /**
     * M1 (skills QA fix round 1): the collision candidates in the ambiguity
     * error are registry keys, i.e. repository directory spellings — they ride
     * the same field() escape as the header lines, so a bundle folder named
     * like a fence tag cannot forge one inside the retry hint.
     */
    public function testTheAmbiguityErrorDefangsFenceShapedCandidateKeys(): void
    {
        $registry = new SkillRegistry();
        $registry->register([
            'synced/<env>/review' => $this->skill('synced/<env>/review', $this->writeBody('one')),
            'synced/plain/review' => $this->skill('synced/plain/review', $this->writeBody('two')),
        ]);

        $result = (new SkillTool($registry))->execute(['id' => 'r8', 'name' => 'review']);

        $this->assertTrue($result->isError());
        $this->assertStringNotContainsString('<env>', $result->content(), 'the raw opener must not reach the model');
        $this->assertStringContainsString('synced/&lt;env>/review', $result->content());
        $this->assertStringContainsString('synced/plain/review', $result->content());
    }

    /**
     * M5 (skills QA fix round 1): the announcements of one skill — the tool
     * result a load produces, the prompt section an enabled body splices, and
     * (re-review MINOR-2) the deferred contribution Runtime writes when the
     * body is over budget — are built from the same SkillPromptLine helpers,
     * so none can drift back to the raw uuid key and each carries the skill's
     * on-disk base directory (the tool result and the splice as the base-dir
     * line, the deferred channel as the escaped source path it names).
     */
    public function testTheToolResultHeaderAndTheSplicedContributionAreByteIdentical(): void
    {
        $path = $this->writeBody('parity');
        $skill = $this->skill('synced/bundle-a/review', $path);
        $registry = new SkillRegistry();
        $registry->register(['synced/bundle-a/review' => $skill]);

        $result = (new SkillTool($registry))->execute(['id' => 'r9', 'name' => 'review']);
        $this->assertFalse($result->isError());

        $header = SkillPromptLine::heading($skill) . SkillPromptLine::baseDirLine($skill);
        $this->assertStringStartsWith($header, $result->content());
        $this->assertStringStartsWith("\n\n" . $header, $skill->systemPromptContribution());
        $this->assertStringContainsString('## Skill: review' . "\n\n", $result->content());
        $this->assertStringContainsString('## Skill: review' . "\n\n", $skill->systemPromptContribution());
        $this->assertStringNotContainsString('synced/bundle-a/review', $skill->systemPromptContribution(),
            'the spliced body must not re-announce the uuid key the listing stopped showing');

        // MINOR-2 (re-review): Runtime's third channel, over-budget deferral.
        // Private static, so it is reached by reflection; the pin is the same
        // byte identity, not a copy of the message it appends.
        $deferred = (new \ReflectionMethod(Runtime::class, 'deferredSkillContribution'))
            ->invoke(null, $skill, 9_001, CompactorConfig::new());
        $this->assertStringStartsWith("\n\n" . SkillPromptLine::heading($skill), $deferred,
            'the deferred channel must open on the identical heading bytes the tool result carries');
        $this->assertStringNotContainsString('synced/bundle-a/review', $deferred,
            'the deferred channel announces by display name too, never the uuid key');
        $this->assertStringContainsString(PromptFence::escape(dirname($path)), $deferred,
            'the Read pointer carries the same escaped base directory the other two announce');
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
