<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Context\Rule;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;
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

    public function testTheReadCapBoundsEveryFileTouchedEvenWhenNoneParse(): void
    {
        // P6.S2 review fix (MINOR). The old cap counted ACCEPTED rules, so a
        // directory of files that all fail to parse never ticked it and every
        // byte of all of them was read at prompt-build time - unbounded work
        // from a cloned repo. The counter now ticks at the READ, before the
        // parse, so the cap bounds reads regardless of parse outcome.
        $cap = (new ReflectionClass(RuleLoader::class))->getConstant('MAX_FILES');
        self::assertIsInt($cap);

        $root = $this->sandbox . '/repo-malformed';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);
        // cap + 3 files, EACH a frontmatter block that parses to a scalar (the
        // same malformed shape the parse-failure test uses), so not one of them
        // can ever be accepted. A parse-based cap would read all cap+3.
        for ($i = 0; $i < $cap + 3; $i++) {
            $this->emitRule(sprintf($rules . '/bad%02d.md', $i), "---\nthis is just a plain scalar\n---\nBODY\n");
        }

        $loader = new RuleLoader($root);
        $loaded = $loader->loadProjectRules();
        self::assertSame([], $this->ruleNames($loaded), 'none of these files can parse to a rule');

        $skipped = $loader->skippedFiles();
        $capSkips = array_values(array_filter(
            $skipped,
            static fn(string $r): bool => str_contains($r, 'file cap'),
        ));
        $parseSkips = array_values(array_filter(
            $skipped,
            static fn(string $r): bool => str_contains($r, 'Failed to load rule'),
        ));

        // POSITIVE CONTROL against the old read-everything behaviour: exactly
        // `cap` files were read (so `cap` parse failures recorded) and the extra
        // 3 were refused BEFORE reading (3 cap trips). If the counter were still
        // keyed on accepted rules, cap would stay 0 and all cap+3 would be read:
        // there would be cap+3 parse skips and ZERO cap skips - this assertion
        // reddens on that revert.
        self::assertCount($cap, $parseSkips, 'exactly the cap were read far enough to fail parsing');
        self::assertCount(3, $capSkips, 'the files past the read cap are refused without being read');
    }

    // -- Caps: per-file bytes -------------------------------------------------

    public function testTheByteCeilingRefusesJustPastItAndAdmitsJustUnder(): void
    {
        // P6.S2 review fix (MINOR). readRule() stats the file and refuses one
        // past MAX_FILE_BYTES before reading it (the Read tool's stat-before-read
        // precedent), so a 300 MB `.sugar-crush/rules/x.md` is never pulled into
        // memory at prompt-build time.
        $ceiling = (new ReflectionClass(RuleLoader::class))->getConstant('MAX_FILE_BYTES');
        self::assertIsInt($ceiling);

        $root = $this->sandbox . '/repo-bytes';
        $rules = $root . '/.sugar-crush/rules';
        mkdir($rules, 0o755, true);

        // A valid rule padded to EXACTLY the ceiling (boundary is `>`, so a
        // file whose byte size equals the ceiling is admitted), and one a byte
        // over it (refused whole).
        $prefix = "---\nname: padded\n---\n";
        $under = $prefix . str_repeat('x', $ceiling - strlen($prefix) - 1) . "\n";
        $this->emitRule($rules . '/under.md', $under);
        self::assertSame($ceiling, filesize($rules . '/under.md'), 'the just-under file is exactly at the ceiling in real bytes');

        $over = $prefix . str_repeat('y', $ceiling - strlen($prefix)) . "\n";
        $this->emitRule($rules . '/over.md', $over);
        self::assertSame($ceiling + 1, filesize($rules . '/over.md'), 'the just-over file is one byte past the ceiling in real bytes');

        $loader = new RuleLoader($root);
        $loaded = $loader->loadProjectRules();

        self::assertSame(['padded'], $this->ruleNames($loaded), 'the at-ceiling file loads; the over-ceiling file is refused');

        $skipped = $loader->skippedFiles();
        $overSkip = $skipped[realpath($rules . '/over.md')] ?? '';
        self::assertStringContainsString('byte', $overSkip, 'the oversized file is recorded as a byte-ceiling skip, not silent');
        // POSITIVE CONTROL: the recorded reason names the real measured size,
        // so a revert of the ceiling (which would read and parse the valid
        // over-ceiling file into a loaded rule) reddens BOTH the loaded-name
        // pin above AND this presence check.
        self::assertStringContainsString((string) ($ceiling + 1), $overSkip, 'the refusal quotes the file\'s real byte count');
    }

    public function testTheByteCeilingBoundsTheRootRulesFileToo(): void
    {
        // P6.S2 fix 2 (NIT-4): the pair above covers the directory tier.
        // loadRootRules() reaches the SAME readRule() by its own route (a bare
        // file, no walk, no key derivation), and until now nothing in the suite
        // had ever put a RULES.md at the ceiling - so a ceiling that bound one
        // tier and not the other would have stayed invisible. RULES.md is the
        // single file an untrusted clone is most likely to ship fat, which makes
        // this the tier the bound most needs to hold on.
        $ceiling = (new ReflectionClass(RuleLoader::class))->getConstant('MAX_FILE_BYTES');
        self::assertIsInt($ceiling);

        $root = $this->sandbox . '/repo-root-bytes';
        mkdir($root, 0o755, true);
        $rulesFile = $root . '/RULES.md';
        $prefix = "---\nname: rootpadded\n---\n";

        // Just-under: the boundary is `>`, so a file whose byte size equals the
        // ceiling is admitted whole.
        $this->emitRule($rulesFile, $prefix . str_repeat('x', $ceiling - strlen($prefix) - 1) . "\n");
        self::assertSame($ceiling, filesize($rulesFile), 'the just-under RULES.md is exactly at the ceiling in real bytes');
        $underLoader = new RuleLoader($root);
        self::assertSame(['rootpadded'], $this->ruleNames($underLoader->loadRootRules()), 'a RULES.md at the ceiling loads');
        self::assertSame([], $underLoader->skippedFiles(), 'an admitted RULES.md leaves the skip ledger empty');
        self::assertSame([], $underLoader->refusedPaths(), 'and nothing here is a containment event');

        // Just-over: one byte past, refused on the stat, before the read.
        $this->emitRule($rulesFile, $prefix . str_repeat('y', $ceiling - strlen($prefix)) . "\n");
        // Same path rewritten, so the stat cache must be dropped or the size
        // assertion below would re-measure the previous file.
        clearstatcache(true, $rulesFile);
        self::assertSame($ceiling + 1, filesize($rulesFile), 'the just-over RULES.md is one byte past the ceiling in real bytes');

        $loader = new RuleLoader($root);
        self::assertSame([], $this->ruleNames($loader->loadRootRules()), 'the oversized RULES.md is refused whole, not truncated');

        $skipped = $loader->skippedFiles();
        self::assertCount(1, $skipped, 'the refusal is recorded, not silently skipped');
        self::assertSame(
            [realpath($rulesFile)],
            array_keys($skipped),
            'the ledger keeps the resolved path as its key - same path => reason shape the directory tiers use',
        );
        $reason = (string) reset($skipped);
        self::assertStringContainsString('byte', $reason, 'recorded as a byte-ceiling skip');
        self::assertStringContainsString((string) ($ceiling + 1), $reason, 'the refusal quotes the file\'s real byte count');
        self::assertStringContainsString((string) $ceiling, $reason, 'and the ceiling it exceeded');
        self::assertSame([], $loader->refusedPaths(), 'a byte-ceiling skip is not a containment refusal');
    }

    // -- P6.S3: the rulebooks directory (the user tier's second spelling) ----

    /**
     * `~/.sugar-crush/rulebooks/*.md` is walked by the same
     * {@see RuleLoader::loadFromDirectory()} as `rules/` and carries tier `user`,
     * because a rulebook is operator-controlled by definition - the provenance the
     * tier records, not a shortcut around adding a fourth one.
     *
     * The assertions here are the ones that only hold if the directory is REALLY
     * wired through the existing machinery: the key derives from the basename the
     * same way, the tier string is `user`, and a load with an empty toggle set
     * returns it. Revert the directory walk and every one of these reddens.
     */
    public function testUserRulebooksDirectoryLoadsCarryingTheUserTier(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $this->emitRule($packs . '/terse.md', "---\nname: Terse\n---\nBE TERSE.\n");

        $loaded = (new RuleLoader($root))->loadUserRulebooks();

        self::assertSame(['terse'], array_map(static fn (Rule $r): string => $r->key, $loaded), 'the key is the basename stem');
        self::assertSame('user', $loaded[0]->tier, 'a rulebook is tier user, not a fourth tier');
        self::assertSame('terse', $loaded[0]->key, 'the key is the basename minus .md - the toggle handle');
        self::assertSame($packs . '/terse.md', $loaded[0]->path, 'the path is the file it was read from');
    }

    /**
     * An absent `rulebooks/` is the ordinary case (nobody has made a pack yet) and
     * must be silent, not a refusal - exactly as an absent `rules/` is. If this
     * ever recorded a refusal, every user without a rulebook directory would get
     * a phantom entry in the refusal ledger on every prompt build.
     */
    public function testAnAbsentRulebooksDirectoryIsNotARefusal(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);

        $loader = new RuleLoader($root);

        self::assertSame([], $loader->loadUserRulebooks());
        self::assertSame([], $loader->refusedPaths(), 'a tier nobody configured is not a security event');
        self::assertSame([], $loader->skippedFiles());
    }

    /**
     * Load order across the two user directories, pinned because the prompt
     * renders in this order and the golden-adjacent byte order depends on it:
     * `rules/` (sorted) then `rulebooks/` (sorted), project, root.
     */
    public function testLoadEmitsRulesDirectoryPacksThenRulebooksDirectoryPacks(): void
    {
        $root = $this->sandbox . '/repo';
        $rules = $this->home . '/.sugar-crush/rules';
        mkdir($rules, 0o700, true);
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        mkdir($root . '/.sugar-crush/rules', 0o755, true);
        $this->emitRule($rules . '/b_standing.md', "B Standing\n");
        $this->emitRule($rules . '/a_standing.md', "A Standing\n");
        $this->emitRule($packs . '/z_pack.md', "Z Pack\n");
        $this->emitRule($packs . '/m_pack.md', "M Pack\n");
        $this->emitRule($root . '/.sugar-crush/rules/p_project.md', "P Project\n");
        $this->emitRule($root . '/RULES.md', "R Root\n");

        $loaded = (new RuleLoader($root))->load();

        self::assertSame(
            ['a_standing', 'b_standing', 'm_pack', 'z_pack', 'p_project', 'RULES.md'],
            $this->ruleNames($loaded),
            'each directory is filename-sorted and rules/ precedes rulebooks/, which precede the repo tiers',
        );
    }

    /**
     * The same stem in both directories is TWO packs, not one: de-duplication is
     * by `realpath()`, and these are two different files the operator wrote twice.
     *
     * What they DO share is the toggle handle, because the handle is the key. So
     * the second half of this test pins the collision behaviour rather than
     * leaving it to be discovered: one `/rules x` turns both spellings off, which
     * is the only answer that cannot leave a pack the user believes is off still
     * riding in the prompt.
     */
    public function testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        $rules = $this->home . '/.sugar-crush/rules';
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($rules, 0o700, true);
        mkdir($packs, 0o700, true);
        $this->emitRule($rules . '/x.md', "FROM RULES DIR\n");
        $this->emitRule($packs . '/x.md', "FROM RULEBOOKS DIR\n");

        $loader = new RuleLoader($root);
        self::assertCount(2, $loader->load(), 'two files, two packs - realpath dedup does not merge them');

        $toggled = (new RuleLoader($root, rulesState: RulesState::new(['x'])))->load();
        self::assertSame([], $toggled, 'the one name both share turns both off');
    }

    // -- P6.S3: the session toggle set ---------------------------------------

    /**
     * Both polarities at the loader boundary: a named pack leaves `load()`, and
     * toggling the same name again puts it back. A one-polarity test could be
     * satisfied by a loader that simply never returns user rules at all.
     */
    public function testTheSessionDisabledSetRemovesAPackAndTogglingAgainRestoresIt(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $this->emitRule($packs . '/on.md', "ONE\n");
        $this->emitRule($packs . '/other.md', "TWO\n");

        $state = RulesState::new();
        self::assertSame(['on', 'other'], $this->ruleNames((new RuleLoader($root, rulesState: $state))->load()));

        self::assertFalse($state->toggle('on'), 'the toggle reports the state it switched TO');
        self::assertSame(['other'], $this->ruleNames((new RuleLoader($root, rulesState: $state))->load()));

        self::assertTrue($state->toggle('on'), 'and switching it back reports on');
        self::assertSame(['on', 'other'], $this->ruleNames((new RuleLoader($root, rulesState: $state))->load()));
    }

    /**
     * AND-SEMANTICS (requirement 6). A pack whose own frontmatter says
     * `enabled: false` stays out of `load()` even when the session set is empty -
     * i.e. under the toggle position that would otherwise switch it on.
     *
     * The direction that matters is frontmatter-wins, and the test says so in both
     * halves: off-by-frontmatter with the session on = out, and a session disable
     * of the same pack changes nothing about that verdict.
     */
    public function testAFrontmatterDisabledPackStaysOutOfLoadUnderAnOnToggle(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $this->emitRule($packs . '/shy.md', "---\nenabled: false\n---\nSHY\n");

        // Session OFF first, then toggled back ON: the position a user reaches by
        // typing `/rules shy` twice. The frontmatter must hold it out both times.
        $fresh = RulesState::new(['shy']);
        self::assertSame([], $this->ruleNames((new RuleLoader($root, rulesState: $fresh))->load()));

        // `toggle()` on a pack the file already disabled flips the SESSION bit and
        // must not resurrect the pack: the conjunction is an AND, not an override.
        self::assertTrue($fresh->toggle('shy'), 'the session bit switched to on');
        self::assertFalse($fresh->isDisabled('shy'), 'the session no longer holds it back');
        self::assertSame(
            [],
            $this->ruleNames((new RuleLoader($root, rulesState: $fresh))->load()),
            'frontmatter enabled:false still wins - a session toggle cannot enable a pack that disabled itself',
        );
    }

    /**
     * A `null` state - every App that predates rulebooks, every embedder that
     * never allocates one - must load EXACTLY what the loader loaded before this
     * step existed. This is the no-behaviour-change half of the session-scoping
     * requirement, asserted against a non-empty toggle set rather than in a vacuum.
     */
    public function testNullRulesStateLoadsEveryEnabledPackAsThoughTheStepNeverHappened(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root, 0o755, true);
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $this->emitRule($packs . '/a.md', "---\nenabled: false\n---\nA\n");
        $this->emitRule($packs . '/b.md', "B\n");

        self::assertSame(
            ['b'],
            $this->ruleNames((new RuleLoader($root))->load()),
            'with no state at all, only frontmatter decides',
        );
        self::assertSame(
            ['b'],
            $this->ruleNames((new RuleLoader($root, rulesState: RulesState::new(['zzz'])))->load()),
            'a set naming nothing present changes nothing either',
        );
    }

    /**
     * The session set is scoped to the user tier. A project or root file is the
     * repository's voice, and a name the operator typed into `/rules` is not a
     * place where they revoke a checkout's authority - or, the other way round,
     * where a cloned repository picks its own name to dodge a pack the operator
     * switched off.
     *
     * Both non-user tiers are pinned with a file deliberately named to collide
     * with the disabled pack, so a loader that applied the subtraction tier-blind
     * cannot pass.
     */
    public function testTheSessionSetCannotReachTheProjectOrRootTier(): void
    {
        $root = $this->sandbox . '/repo';
        mkdir($root . '/.sugar-crush/rules', 0o755, true);
        $this->emitRule($root . '/.sugar-crush/rules/terse.md', "PROJECT TERSE\n");
        $this->emitRule($root . '/RULES.md', "ROOT TERSE\n");
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        $this->emitRule($packs . '/terse.md', "USER TERSE\n");

        $loaded = (new RuleLoader($root, rulesState: RulesState::new(['terse'])))->load();

        self::assertSame(
            ['terse', 'RULES.md'],
            $this->ruleNames($loaded),
            'the user pack named terse is gone; the project file of the same name and the root file are untouched',
        );
        self::assertSame(['project', 'root'], array_values(array_map(static fn (Rule $r): string => $r->tier, $loaded)));
    }

    /**
     * Requirement 8, stated as the arithmetic the loader doc-block now spells out:
     * `MAX_FILES` bounds EACH directory walked, so the aggregate is
     * (directories x cap) and a big `rulebooks/` cannot consume `rules/`'s slots.
     *
     * The positive control is the point of the test. Under a hoisted GLOBAL
     * counter - the ruling this brief withdrew - the first directory would spend
     * the whole budget and the second would load ZERO, so `cap` in each is only
     * obtainable with the per-directory counter that exists. The second half
     * asserts each overflow names ITS OWN directory, which is what makes the
     * refusal actionable instead of mysterious.
     */
    public function testEachUserDirectoryKeepsItsOwnFileCapSoNeitherStarvesTheOther(): void
    {
        $cap = (new ReflectionClass(RuleLoader::class))->getConstant('MAX_FILES');
        self::assertIsInt($cap);

        $root = $this->sandbox . '/repo-caps';
        mkdir($root, 0o755, true);
        $rules = $this->home . '/.sugar-crush/rules';
        $packs = $this->home . '/.sugar-crush/rulebooks';
        mkdir($rules, 0o700, true);
        mkdir($packs, 0o700, true);

        foreach ([$rules => 'r', $packs => 'p'] as $dir => $stem) {
            for ($i = 0; $i < $cap; $i++) {
                $this->emitRule(sprintf($dir . '/%s%02d.md', $stem, $i), "BODY\n");
            }
            // One past the ceiling in EACH directory.
            $this->emitRule($dir . '/zz_overflow.md', "OVERFLOW\n");
        }

        $loader = new RuleLoader($root);
        $loaded = $loader->load();

        self::assertCount(2 * $cap, $loaded, 'the aggregate is directories x cap, not cap');
        self::assertCount($cap, (new RuleLoader($root))->loadUserRules(), 'rules/ got its own full budget');
        self::assertCount($cap, (new RuleLoader($root))->loadUserRulebooks(), 'rulebooks/ got its own full budget');

        // WHICH of a directory's files is refused depends on filesystem walk
        // order, so the assertion is about ATTRIBUTION: each refusal must name a
        // path inside the directory that hit its own ceiling, and each directory
        // must be represented exactly once. A hoisted global counter would put both
        // refusals in the second directory (the first would have spent the budget),
        // which this fails.
        $refusedPaths = array_keys($loader->skippedFiles());
        $inRules = array_values(array_filter($refusedPaths, static fn (string $p): bool => str_starts_with($p, $rules . '/')));
        $inPacks = array_values(array_filter($refusedPaths, static fn (string $p): bool => str_starts_with($p, $packs . '/')));

        self::assertCount(1, $inRules, 'exactly one rules/ file was refused past its own directory cap');
        self::assertCount(1, $inPacks, 'exactly one rulebooks/ file was refused past its own directory cap');
        self::assertStringContainsString(
            'this tier already reached its ' . $cap . '-file cap',
            (string) $loader->skippedFiles()[$inRules[0]],
            'the refusal quotes the ceiling that directory reached',
        );
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
