<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillMatcher;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * The curation SkillMatcher::listForPrompt() applies before rendering:
 * tier-grouped, alphabetical ordering, exact-duplicate lines collapsed to the
 * shadow winner, and lines keyed by Skill::displayName() so a synced bundle's
 * uuid path never eats the reader's budget (skills QA, inv1 §4-§6).
 */
final class SkillMatcherListingTest extends TestCase
{
    private function skill(string $key, string $description, SkillOrigin $origin = SkillOrigin::Project): Skill
    {
        return new Skill(
            name: $key,
            description: $description,
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: '/skills/' . $key . '/SKILL.md',
            origin: $origin,
        );
    }

    private function registryWith(Skill ...$skills): SkillRegistry
    {
        $registry = new SkillRegistry();
        foreach ($skills as $skill) {
            $registry->register([$skill->name => $skill]);
        }

        return $registry;
    }

    /**
     * @return list<string> the skill lines, header stripped
     */
    private function lines(string $listing): array
    {
        $this->assertStringStartsWith("\n\nAvailable skills (invoke via Skill tool):\n", $listing);
        $parts = explode("\n", $listing);
        $this->assertSame('', $parts[0]);
        $this->assertSame('', $parts[1]);
        $this->assertSame('Available skills (invoke via Skill tool):', $parts[2]);

        return \array_slice($parts, 3);
    }

    public function testTheBuiltInTierLeadsThenProjectThenUserAlphabeticallyInsideEach(): void
    {
        // Registered in an order no grouping would produce, with display names
        // chosen so the alphabetical leg cannot ride registration order.
        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
            $this->skill('zeta', 'Z user tier', SkillOrigin::User),
            $this->skill('bravo', 'B project tier', SkillOrigin::Project),
            $this->skill('alpha', 'A project tier', SkillOrigin::Project),
            $this->skill('built-one', 'B built-in tier', SkillOrigin::BuiltIn),
        ));

        $this->assertSame([
            '- [built-in] built-one: B built-in tier',
            '- [project] alpha: A project tier',
            '- [project] bravo: B project tier',
            '- [user] zeta: Z user tier',
        ], $this->lines($listing));
    }

    public function testDisplayNameIsTheLeafOfANestedKeyAndTheWholeOfAPlainOne(): void
    {
        $this->assertSame('docx', $this->skill('synced/c63a5ebc-f2535d4c/docx', 'd')->displayName());
        $this->assertSame('pdf', $this->skill('pdf', 'd')->displayName(), 'a flat name is display-identical to its key');
        $this->assertSame('deep', $this->skill('a/b/c/deep', 'd')->displayName(), 'the LAST segment wins, however deep the bundle');
    }

    public function testANestedKeyListsUnderItsLeafNotItsBundlePath(): void
    {
        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
            $this->skill('synced/c63a5ebc-f2535d4c/docx', 'Word documents.'),
        ));

        $this->assertSame(['- [project] docx: Word documents.'], $this->lines($listing));
        $this->assertStringNotContainsString('c63a5ebc', $listing, 'the bundle path is identity, not display');
    }

    public function testTwoBundleKeysWithAnIdenticalPairCollapseToOneLine(): void
    {
        // The inv1 shape: the same skill imported twice lands under two uuid
        // keys and renders byte-identical lines. One line survives — the model
        // cannot act twice on one fact — and both keys stay reachable through
        // SkillTool by exact key (pinned in SkillToolResolutionTest).
        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
            $this->skill('synced/guid-one-pair/docx', 'Word documents.'),
            $this->skill('synced/guid-two-pair/docx', 'Word documents.'),
        ));

        $this->assertSame(['- [project] docx: Word documents.'], $this->lines($listing));
    }

    public function testTheCrossTierTieKeepsTheShadowWinner(): void
    {
        // SkillOrigin::precedence() makes the user tier beat the project tier
        // for a name; the one surviving line must carry the winner's badge,
        // whichever order the registry saw them in.
        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
            $this->skill('docx', 'Word documents.', SkillOrigin::Project),
            $this->skill('synced/import/docx', 'Word documents.', SkillOrigin::User),
        ));

        $this->assertSame(['- [user] docx: Word documents.'], $this->lines($listing));
    }

    public function testADifferentDescriptionIsNeverDedupedAway(): void
    {
        // Same leaf, different promises: two skills the model must be able to
        // tell apart. Collapsing them would hide a real option.
        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
            $this->skill('synced/one/review', 'Reviews PHP.'),
            $this->skill('synced/two/review', 'Reviews SQL.'),
        ));

        $this->assertSame([
            '- [project] review: Reviews PHP.',
            '- [project] review: Reviews SQL.',
        ], $this->lines($listing));
    }

    public function testExcludedSkillsStillCurateFromTheRemainder(): void
    {
        $registry = $this->registryWith(
            $this->skill('a', 'Alpha body.'),
            $this->skill('b', 'Bravo body.'),
        );

        $listing = (new SkillMatcher())->listForPrompt($registry, ['a']);

        $this->assertSame(['- [project] b: Bravo body.'], $this->lines($listing));
    }
}
