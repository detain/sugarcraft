<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillMatcher;
use SugarCraft\Code\Skills\SkillOrigin;
use SugarCraft\Code\Skills\SkillRegistry;

/**
 * The curation SkillMatcher::listForPrompt() applies before rendering:
 * tier-grouped, newest-file-first within a tier (ISS-022), ties alphabetical,
 * exact-duplicate lines collapsed to the shadow winner, lines keyed by
 * Skill::displayName() so a synced bundle's uuid path never eats the reader's
 * budget (skills QA, inv1 §4-§6), and the joined listing held to an aggregate
 * byte budget with the tail collapsed to a counted line (ISS-022).
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

    public function testTheAggregateBudgetCollapsesTheTailIntoOneCountedLine(): void
    {
        // ISS-022: the listing was clipped per line and never as a whole — 98
        // skills cost ~40 KB on every request. Now the SUM is bounded, and
        // what the budget cannot name is announced as a count, never a
        // silent gap. The notice-channel twin of this drop is deliberately
        // NOT pinned here: the announcement is once per process through a
        // function-local static, so asserting it would make the suite depend
        // on which test asked for an overflow first.
        $registry = $this->registryWith(
            $this->skill('a', 'Alpha line body.'),
            $this->skill('b', 'Bravo line body.'),
            $this->skill('c', 'Charlie line body.'),
            $this->skill('d', 'Delta line body.'),
        );
        $lines = (new SkillMatcher())->listForPrompt($registry, excludeNames: [], maxTotalBytes: 0);
        // Budget 0 cannot seat line two with room reserved for the collapse
        // line, so exactly one skill and the count survive.
        $this->assertSame([
            '- [project] a: Alpha line body.',
            '- 3 more skills; call skills_search to find them',
        ], $this->lines($lines));

        $firstTwo = strlen('- [project] a: Alpha line body.') + 1 + strlen('- [project] b: Bravo line body.');
        $reserve = strlen('- 999999999 more skills; call skills_search to find them');
        $roomy = $firstTwo + 1 + $reserve + strlen('- [project] c: Charlie line body.') - 1;
        $this->assertSame([
            '- [project] a: Alpha line body.',
            '- [project] b: Bravo line body.',
            '- 2 more skills; call skills_search to find them',
        ], $this->lines((new SkillMatcher())->listForPrompt($registry, excludeNames: [], maxTotalBytes: $roomy)));
    }

    public function testTheDefaultBudgetKeepsAnOrdinaryRosterWhole(): void
    {
        // The 8 KiB default is a ceiling for essays, not for rosters: a
        // typical dozen lines must ride it untouched, with no collapse line.
        $skills = [];
        for ($i = 0; $i < 12; ++$i) {
            $skills[] = $this->skill('skill-' . $i, 'Description number ' . $i . ' of the ordinary roster.');
        }

        $listing = (new SkillMatcher())->listForPrompt($this->registryWith(...$skills));

        $this->assertCount(12, $this->lines($listing));
        $this->assertStringNotContainsString('more skills', $listing);
    }

    public function testRecencyOrdersTheTierAndThePathlessSkillSortsLastInsideIt(): void
    {
        // ISS-022: with an aggregate budget the order decides WHO SURVIVES, so
        // the tier is walked newest-file-first (memory's index argument). The
        // fixtures elsewhere all carry unreadable paths, which is exactly the
        // floor timestamp — this test gives three skills real files with three
        // different mtimes and one skill whose source file vanished.
        $dir = \sys_get_temp_dir() . '/memctx-recency-' . getmypid() . '-' . uniqid('', true);
        mkdir($dir, 0700);
        $old = $dir . '/old.md';
        $new = $dir . '/new.md';
        $mid = $dir . '/mid.md';
        foreach ([$old => time() - 86400, $mid => time() - 3600, $new => time()] as $path => $when) {
            file_put_contents($path, 'x');
            touch($path, $when);
        }

        $withPath = function (string $key, string $description, string $sourcePath): Skill {
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
                sourcePath: $sourcePath,
                origin: SkillOrigin::Project,
            );
        };

        try {
            $listing = (new SkillMatcher())->listForPrompt($this->registryWith(
                // Registered oldest-first: recency must REORDER, not echo input.
                $withPath('oldest', 'Old note.', $old),
                $this->skill('manifest-vanished', 'No readable source file.'), // unreadable path = floor timestamp
                $withPath('newest', 'Fresh note.', $new),
                $withPath('middle', 'Aging note.', $mid),
            ));

            $this->assertSame([
                '- [project] newest: Fresh note.',
                '- [project] middle: Aging note.',
                '- [project] oldest: Old note.',
                '- [project] manifest-vanished: No readable source file.',
            ], $this->lines($listing));
        } finally {
            foreach ([$old, $mid, $new] as $path) {
                unlink($path);
            }
            rmdir($dir);
        }
    }

    public function testListingMaxBytesReadsTheSettingAndRefusesNonsense(): void
    {
        // Same resolver shape as ProjectMemoryWriter::maxContentBytes():
        // nonsense costs the default, never a fatal, and no clamp widens a
        // lowered (or raised) budget. Floats that ARE whole integers land —
        // JSON round-trips through some config readers hand them back.
        $this->assertSame(12345, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => 12345]));
        $this->assertSame(1024, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => 1024.0]));
        $this->assertSame(8192, SkillMatcher::listingMaxBytes([]));
        $this->assertSame(8192, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => 0]));
        $this->assertSame(8192, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => -5]));
        $this->assertSame(8192, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => 'nope']));
        $this->assertSame(8192, SkillMatcher::listingMaxBytes(['skills.listingMaxBytes' => 8192.5]));
        $this->assertSame(SkillMatcher::DEFAULT_LISTING_MAX_BYTES, SkillMatcher::listingMaxBytes([]));
    }
}
