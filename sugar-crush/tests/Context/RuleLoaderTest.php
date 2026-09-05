<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Context\Rule;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\Triggers\IntentTrigger;
use SugarCraft\Crush\Context\Triggers\KeywordTrigger;
use SugarCraft\Crush\Context\Triggers\PathTrigger;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Behaviour of {@see RuleLoader} across the three tiers it was built for.
 *
 * This is the "does it load the right rules in the right order, deduped and
 * capped" suite. The security half - what it REFUSES and whether a refusal is
 * RECORDED - lives in RuleLoaderContainmentTest, filed beside the boundary it
 * exercises rather than duplicated here.
 *
 * Every test CALLS a load method and asserts the exact rule names/values it
 * returns; none asserts that a symbol merely exists. The deletion experiment for
 * this step (revert the change, watch these go red) is recorded in the worklog,
 * not here.
 */
final class RuleLoaderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox;

    /** Controlled empty home so any test calling load() reads a known user tier. */
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_ruleload_behav_' . uniqid('', true);
        mkdir($this->sandbox, 0o755, true);

        // The user tier is only empty here if $HOME points somewhere we control:
        // otherwise load() would read the developer's real ~/.sugar-crush/rules
        // and the suite would be green until the day they create a matching file.
        // `.sugar-crush` exists but NOT `.sugar-crush/rules`, so an absent user
        // tier is empty-and-not-refused (the normal case), and a test that wants
        // user content creates the rules directory itself.
        $this->home = $this->sandbox . '/home';
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->wipeTemp($this->sandbox);

        parent::tearDown();
    }

    // -- Tier loading and ordering -------------------------------------------

    public function testProjectTierLoadsEveryMarkdownFileOrderedByFilename(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        // Written out of order; the loader must emit them sorted by filename.
        $this->emitRule($rules . '/zulu.md', "---\nname: zulu\n---\nZ\n");
        $this->emitRule($rules . '/alpha.md', "---\nname: alpha\n---\nA\n");
        $this->emitRule($rules . '/mike.md', "---\nname: mike\n---\nM\n");

        $loaded = (new RuleLoader($root))->loadProjectRules();

        self::assertSame(['alpha', 'mike', 'zulu'], $this->ruleNames($loaded));
    }

    public function testRootTierReadsSingleRulesFileAndAbsentYieldsNothing(): void
    {
        $root = $this->sandbox . '/repo-root';
        mkdir($root, 0o755, true);

        // Absent first: no RULES.md is the normal case, not a refusal.
        self::assertSame([], (new RuleLoader($root))->loadRootRules());
        self::assertSame([], (new RuleLoader($root))->refusedPaths(), 'an absent root file is not a refusal');

        $this->emitRule($root . '/RULES.md', "---\nname: top\n---\nTOP\n");
        $loaded = (new RuleLoader($root))->loadRootRules();

        self::assertSame(['top'], $this->ruleNames($loaded));
    }

    public function testUserTierProjectTierAndRootTierLoadInThatOrder(): void
    {
        $home = $this->sandbox . '/home';
        mkdir($home . '/.sugar-crush/rules', 0o700, true);
        $this->emitRule($home . '/.sugar-crush/rules/u.md', "---\nname: userone\n---\nU\n");

        $root = $this->sandbox . '/repo';
        mkdir($root . '/.sugar-crush/rules', 0o755, true);
        $this->emitRule($root . '/.sugar-crush/rules/p.md', "---\nname: projone\n---\nP\n");
        $this->emitRule($root . '/RULES.md', "---\nname: rootone\n---\nR\n");

        $this->useHomeSandbox($home, create: false);

        $loaded = (new RuleLoader($root))->load();

        self::assertSame(['userone', 'projone', 'rootone'], $this->ruleNames($loaded), 'load order is user, then project, then root');
    }

    // -- Frontmatter parsing --------------------------------------------------

    public function testEveryDocumentedFrontmatterKeyIsParsedOntoTheRule(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/rich.md', "---\nname: Rich\ndescription: A described rule\nenabled: true\nmodels:\n  - gpt-4\n  - claude-sonnet-4-6\n---\nBODY TEXT\n");

        $loaded = (new RuleLoader($root))->loadProjectRules();

        self::assertCount(1, $loaded);
        $rule = $loaded[0];
        self::assertSame('Rich', $rule->name);
        self::assertSame('A described rule', $rule->description);
        self::assertTrue($rule->enabled);
        self::assertSame(['gpt-4', 'claude-sonnet-4-6'], $rule->models);
        self::assertSame('BODY TEXT', trim($rule->body));
        self::assertSame('project', $rule->tier);
        self::assertSame($rules . '/rich.md', $rule->path);
    }

    public function testNameFallsBackToFilenameStemWhenFrontmatterOmitsIt(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules . '/nested', 0o755, true);
        $this->emitRule($rules . '/general.md', "---\ndescription: no name key\n---\nGEN\n");
        $this->emitRule($rules . '/nested/deep.md', "body with no frontmatter fence at all\n");

        $loaded = (new RuleLoader($root))->loadProjectRules();

        self::assertSame(['general', 'nested/deep'], $this->ruleNames($loaded), 'stem relative to the tier root, filename-ordered (general sorts before nested/)');
    }

    public function testEnabledFalseRuleIsParsedButExcludedFromLoad(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/on.md', "---\nname: on\nenabled: true\n---\nON\n");
        $this->emitRule($rules . '/off.md', "---\nname: off\nenabled: false\n---\nOFF\n");
        $this->emitRule($rules . '/bare.md', "---\nname: bare\n---\nBARE\n");

        $loaded = (new RuleLoader($root))->load();

        self::assertSame(['bare', 'on'], $this->ruleNames($loaded), 'absent enabled is true; explicit false is excluded');
    }

    public function testAParseFailureIsRecordedAndDoesNotHideItsGoodSiblings(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/good.md', "---\nname: good\n---\nGOOD\n");
        // A frontmatter block that parses to a scalar, not a mapping -> Rule::new
        // throws, which the loader must turn into a recorded skip, not an abort.
        $this->emitRule($rules . '/bad.md', "---\nthis is just a plain scalar\n---\nBODY\n");

        $loader = new RuleLoader($root);
        $loaded = $loader->loadProjectRules();

        self::assertSame(['good'], $this->ruleNames($loaded), 'one malformed file must not swallow the directory');
        $skipped = $loader->skippedFiles();
        self::assertArrayHasKey(realpath($rules . '/bad.md'), $skipped, 'the bad file is recorded, not silent');
        self::assertStringContainsString('Failed to load rule', $skipped[realpath($rules . '/bad.md')]);
    }

    // -- Triggers built (not fired) ------------------------------------------

    public function testTriggerFrontmatterBuildsTheThreeP6S1ValueObjectsInCanonicalOrder(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        // Keys written in reverse of canonical order to prove the order is fixed,
        // not the order the file spelled them in.
        $this->emitRule($rules . '/tri.md', "---\nname: tri\ndescription: an intent\nkeywords:\n  - think\npaths:\n  - 'src/**'\n---\nBODY\n");

        $rule = (new RuleLoader($root))->loadProjectRules()[0];

        self::assertCount(3, $rule->triggers, 'keywords, paths and description each become a trigger');
        self::assertInstanceOf(KeywordTrigger::class, $rule->triggers[0]);
        self::assertInstanceOf(PathTrigger::class, $rule->triggers[1]);
        self::assertInstanceOf(IntentTrigger::class, $rule->triggers[2]);
        self::assertSame(['think'], $rule->triggers[0]->words);
        self::assertSame(['src/**'], $rule->triggers[1]->globs);
        self::assertSame('an intent', $rule->triggers[2]->description);
    }

    public function testARuleWithNoTriggerKeysCarriesAnEmptyTriggerList(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/plain.md', "---\nname: plain\n---\nSTANDING RULE\n");

        $rule = (new RuleLoader($root))->loadProjectRules()[0];

        self::assertSame([], $rule->triggers, 'a standing rule is gated on nothing');
    }

    // -- De-duplication: pass 1 (exact realpath) -----------------------------

    public function testDedupPass1EmitsAFileReachedByBothTiersExactlyOnce(): void
    {
        // Pointing repoRoot at the home makes the project rules directory and the
        // user rules directory the SAME physical directory, so load() reads one
        // realpath twice. The first (user) wins; the second is recorded. Only the
        // exact-realpath pass can catch this, and only load() runs it -
        // loadProjectRules() on its own has no twin to de-dup against.
        $home = $this->sandbox . '/home';
        mkdir($home . '/.sugar-crush/rules', 0o700, true);
        $this->emitRule($home . '/.sugar-crush/rules/shared.md', "---\nname: shared\n---\nS\n");

        $loader = new RuleLoader($home);
        $loaded = $loader->load();

        self::assertSame(['shared'], $this->ruleNames($loaded), 'same realpath across tiers emits once');
        $skips = $loader->skippedFiles();
        self::assertCount(1, $skips);
        self::assertStringContainsString('exact same file', reset($skips));
    }

    // -- De-duplication: pass 2 (case-insensitive realpath) ------------------

    public function testDedupPass2CaseInsensitiveEmitsOnlyOneOfTwoCaseVariants(): void
    {
        // On this case-SENSITIVE filesystem `foo.md` and `FOO.md` are two distinct
        // files with two distinct realpaths, so pass 1 (exact realpath) cannot
        // fire; only the lowercased pass recognises them as one intended rule.
        // The pair is exactly the upstream `rules.md` / `RULES.md` collision the
        // plan cites as the reason a second pass exists.
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/foo.md', "---\nname: foo-lower\n---\nlower\n");
        $this->emitRule($rules . '/FOO.md', "---\nname: FOO-upper\n---\nupper\n");

        $loader = new RuleLoader($root);
        $merged = $loader->load();

        // The two realpaths are distinct, so both survive the tier walk; the
        // lowercased pass collapses them to first-seen-wins by filename order.
        self::assertCount(1, $merged, 'two case variants collapse to one');
        $skips = $loader->skippedFiles();
        self::assertCount(1, $skips);
        self::assertStringContainsString('case variant', reset($skips));
    }

    // -- Caps: depth ----------------------------------------------------------

    public function testDepthCapLoadsAtTheBoundaryAndStopsJustPastIt(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        // at-cap is nested four directories below the tier root (MAX_DEPTH); the
        // over file is nested five. Both live inside the honoured directory, so
        // the only thing that separates them is the walk depth.
        mkdir($rules . '/a/b/c/d', 0o755, true);
        mkdir($rules . '/a/b/c/d/e', 0o755, true);
        $this->emitRule($rules . '/a/b/c/d/atcap.md', "---\nname: atcap\n---\nA\n");
        $this->emitRule($rules . '/a/b/c/d/e/over.md', "---\nname: over\n---\nO\n");

        $loaded = (new RuleLoader($root))->loadProjectRules();

        self::assertTrue(is_file($rules . '/a/b/c/d/e/over.md'), 'the past-cap file really exists on disk, so its absence below is depth, not a broken fixture');
        self::assertSame(['atcap'], $this->ruleNames($loaded), 'depth 4 reached, depth 5 not');
    }

    // -- Caps: file count -----------------------------------------------------

    public function testFileCountCapLoadsExactlyToTheCapAndSkipsOnePastIt(): void
    {
        $cap = (new ReflectionClass(RuleLoader::class))->getConstant('MAX_FILES');
        self::assertIsInt($cap);

        $root = $this->sandbox . '/repo-under';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        for ($i = 0; $i < $cap; $i++) {
            $this->emitRule(sprintf($rules . '/r%02d.md', $i), sprintf("---\nname: r%02d\n---\nB\n", $i));
        }
        $underLoader = new RuleLoader($root);
        $under = $underLoader->loadProjectRules();
        self::assertCount($cap, $under, 'exactly at the cap: every file loads, none skipped');
        self::assertSame([], $underLoader->skippedFiles(), 'a load that fits leaves the skip ledger empty');

        // One file past the cap.
        $this->emitRule($rules . '/r99_overflow.md', "---\nname: overflow\n---\nOVER\n");
        $loader = new RuleLoader($root);
        $over = $loader->loadProjectRules();
        $skipped = $loader->skippedFiles();

        self::assertCount($cap, $over, 'still exactly the cap loaded');
        self::assertCount(1, $skipped, 'the file past the cap is recorded, not silently dropped');
        self::assertStringContainsString('file cap', reset($skipped));
    }

    // -- Rule value object immutability / fail-fast ---------------------------

    public function testWithReturnsANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/m.md', "---\nname: m\n---\nB\n");

        $rule = (new RuleLoader($root))->loadProjectRules()[0];
        $toggled = $rule->withEnabled(false);

        self::assertNotSame($rule, $toggled, 'withEnabled returns a new instance');
        self::assertTrue($rule->enabled, 'the original is unchanged');
        self::assertFalse($toggled->enabled);
        self::assertSame($rule->name, $toggled->name, 'untouched fields carry through');
    }

    public function testWithTriggersRejectsANonTriggerElement(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        $this->emitRule($rules . '/m.md', "---\nname: m\n---\nB\n");
        $rule = (new RuleLoader($root))->loadProjectRules()[0];

        $this->expectException(\InvalidArgumentException::class);
        $rule->withTriggers(['not a trigger']);
    }

    public function testRuleRejectsAnUnknownTier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rule::new('/tmp/whatever.md', 'cosmic', "body\n", 'whatever');
    }

    // -- helpers (distinct names; no sibling collision) ----------------------

    private function emitRule(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private function ruleNames(array $rules): array
    {
        return array_map(static fn (Rule $r): string => $r->name, $rules);
    }

    private function wipeTemp(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);

                continue;
            }
            $this->wipeTemp($path);
        }
        @rmdir($dir);
    }
}
