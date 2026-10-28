<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\ForeignCommandDiscovery;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Skills\SkillSource;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-4.c: bundle command rows are keyed `<bundle>:<file>`, so two bundles
 * shipping `review.md` no longer collapse last-write-wins into whichever
 * directory the walk read second — BOTH files survive as distinct rows. The
 * flat `/review` spelling is admitted only while nothing else claims it:
 * another bundle's same-named file kills it in both directions (the ambiguity
 * is the collision this step names), and so does any plain foreign row of any
 * tier, which keeps PLG-1's "the operator's tree wins" true as a property
 * rather than as an accident of band order.
 *
 * The NAME_PATTERN widen rides here too: one leading
 * `[A-Za-z0-9][A-Za-z0-9_-]*:` segment is legal, a second colon anywhere is
 * not, and the traversal refusals the pattern existed for stay refused — the
 * colon must not become a door. TurnController's whole-name-first try is what
 * makes a namespaced row reachable as typed (CONFIRMED at tip, not assumed);
 * its colon-invocation arm still fires on a whole-name miss, and Chat's menu
 * prefix strips only the leading slash, so a colon row reaches the popup
 * whole.
 *
 * Attribution law (review-1 cure): the loader's commandSources map grows a
 * flat key ONLY when walk() admits the alias (admitBundleAliasSource() at
 * the admission leg) — a refused candidate writes nothing, so a refused
 * bundle path can never shadow a plain winner's report rows.
 */
final class BundleCommandNamespaceTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-namespace-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /** Build a real bundle directory (manifest + surfaces) and read it back. */
    private function bundleAt(string $dir, string $name, string $tier, ?string $ownedBy): Bundle
    {
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode(['name' => $name]));

        $bundle = Bundle::fromDir($dir, $tier, $ownedBy);
        self::assertNotNull($bundle, "the fixture at {$dir} must be a valid bundle");

        return $bundle;
    }

    /** @param array<string, string> $rows command name => body */
    private function commandRows(string $dir, array $rows): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach ($rows as $name => $body) {
            file_put_contents($dir . '/' . $name . '.md', "---\ndescription: a row\n---\n" . $body);
        }
    }

    /**
     * The FOREIGN map — the law this step writes lives there. Going through
     * `loadAll` would merge the built-in roster on top (there is a built-in
     * `/review`, and natives outrank the foreign floor regardless of C-EXT-4.c),
     * which would test CommandRegistry's own precedence rather than the alias
     * law. The ride into `loadAll` is BundleSurfaceWiringTest's existing pin.
     *
     * @param list<Bundle> $bundles
     */
    private function load(array $bundles, ?CommandLoader &$seen = null): array
    {
        $seen = new CommandLoader();
        $seen->useBundles($bundles);

        return (new ForeignCommandDiscovery($seen))->discover($this->project, $bundles);
    }

    /**
     * The loader's private attribution map — the face the launch report's
     * refusals and override rows key on. Read by reflection because its
     * three consumers all run in `loadAll` after a native re-write, so the
     * map's own contents are the observable face of the review-1 cure
     * today.
     *
     * @return array<string, string>
     */
    private function commandSources(CommandLoader $loader): array
    {
        $property = new \ReflectionProperty(CommandLoader::class, 'commandSources');

        /** @var array<string, string> $sources */
        $sources = $property->getValue($loader);

        return $sources;
    }

    public function testTwoBundlesShippingTheSameFileKeepTwoDistinctRows(): void
    {
        $a = $this->bundleAt($this->home . '/dev/revA', 'revA', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['review' => 'from A']);
        $b = $this->bundleAt($this->home . '/dev/revB', 'revB', 'user', $this->home);
        $this->commandRows($b->dir . '/commands', ['review' => 'from B']);

        $commands = $this->load([$a, $b]);

        self::assertArrayHasKey('revA:review', $commands, 'the first bundle keeps a row — last-write-wins used to erase it');
        self::assertArrayHasKey('revB:review', $commands, 'and so does the second');
        self::assertStringContainsString('from A', (string) $commands['revA:review']->template, 'each row carries ITS file, not the walk order winner');
        self::assertStringContainsString('from B', (string) $commands['revB:review']->template);
        self::assertArrayNotHasKey('review', $commands, 'the flat name is ambiguous between two owners, so it stands for neither');

        self::assertSame('revA:review', $commands['revA:review']->name, 'row key and spec name agree — CommandRegistry::filter() keys byName');
        self::assertSame(SkillSource::Bundle, $commands['revA:review']->source);
        self::assertSame(SkillSource::Bundle, $commands['revB:review']->source);
    }

    public function testFlatAliasSurvivesWhileNothingElseClaimsIt(): void
    {
        $a = $this->bundleAt($this->home . '/dev/revkit', 'revkit', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['rev' => 'kit body']);

        $commands = $this->load([$a]);

        self::assertArrayHasKey('revkit:rev', $commands);
        self::assertArrayHasKey('rev', $commands, 'an unclaimed flat spelling keeps working — the widen must not break the old invocation');
        self::assertSame('rev', $commands['rev']->name, 'the alias row is its own spec: names stay unique on both rows');
        self::assertNotSame($commands['revkit:rev'], $commands['rev']);
        self::assertSame(SkillSource::Bundle, $commands['rev']->source);
        self::assertStringContainsString('kit body', (string) $commands['rev']->template);
    }

    public function testAPlainForeignRowOfAnyTierSuppressesTheFlatAlias(): void
    {
        // Bundle-project vs plain project-claude (the PLG-1 seat, same tier).
        $a = $this->bundleAt($this->project . '/bundled', 'bundled', 'project', null);
        $this->commandRows($a->dir . '/commands', ['rev' => 'from the bundle']);
        $this->commandRows($this->project . '/.claude/commands', ['rev' => 'from .claude']);
        // Bundle-project vs plain USER-claude — a claimant from the other
        // band, walked AFTER the bundle, so the suppression must be judged on
        // the merged map, not in load order.
        $this->commandRows($this->home . '/.claude/commands', ['late' => 'from user .claude']);
        $this->commandRows($a->dir . '/commands', ['late' => 'from the bundle'] + []);

        // Rebuild the bundle tree already includes 'late' — commandRows above
        // wrote both; discover merges. Load fresh:
        $commands = $this->load([$a]);

        self::assertSame(SkillSource::Claude, $commands['rev']->source, 'the plain row owns the flat name');
        self::assertArrayHasKey('bundled:rev', $commands, 'the bundle row itself is untouched by the suppression');
        self::assertStringContainsString('from .claude', (string) $commands['rev']->template);

        self::assertSame(SkillSource::Claude, $commands['late']->source, 'a claimant from the USER band suppresses just the same');
        self::assertArrayHasKey('bundled:late', $commands);
    }

    public function testNamespacedRowResolvesAsTypedAndTheColonArmSurvives(): void
    {
        $a = $this->bundleAt($this->home . '/dev/revkit', 'revkit', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['rev' => 'kit body', 'deploy' => 'ship it']);
        $commands = $this->load([$a]);

        $controller = TurnController::new();

        $hit = $controller->resolveCustomCommand('/revkit:rev trim the diff', $commands);
        self::assertNotNull($hit, 'the whole-name try reaches the qualified row as typed');
        self::assertSame($commands['revkit:rev'], $hit[0], 'dispatch resolves the NAMEDPACED row, not the flat alias');
        self::assertSame('trim the diff', $hit[1]);

        // The colon-invocation arm (whole name misses, key is the part before
        // the colon) still serves its original purpose — the widen moved what
        // counts as a whole name, not the fallback.
        $fallback = $controller->resolveCustomCommand('/deploy:now warm', $commands);
        self::assertNotNull($fallback, 'colon-invocation on a bare row still routes through the arm');
        self::assertSame($commands['deploy'], $fallback[0]);
        self::assertSame('now warm', $fallback[1], 'the tail rides PREPENDED to the arguments, per parse()\'s reading');

        // The popup hands resolve() exactly what it completed: Chat's menu
        // prefix strips the leading "/" and NOTHING else, so the colon
        // reaches dispatch whole. Source-scanned because the property is a
        // whole-method shape (a completion that truncated at the colon would
        // silently reroute to the invocation arm).
        $chat = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Chat.php');
        $at = strpos($chat, 'private function slashMenuPrefix(): ?string');
        self::assertIsInt($at, 'the menu prefix method was renamed or un-typed — the pin needs its exact shape');
        $body = substr($chat, $at, 400);
        $body = substr($body, 0, (int) strpos($body, "\n    }"));
        self::assertStringContainsString('return substr($this->inputBuf, 1);', $body, 'slashMenuPrefix no longer returns the typed remainder whole — a colon row would die in the menu');
        self::assertStringNotContainsString('strpos(', $body, 'the prefix must not truncate at any separator — the first one this step grew would silently reroute /bundle:cmd through the invocation arm');
    }

    public function testNamePatternAdmitsExactlyOneLeadingBundleSegment(): void
    {
        $path = $this->tempDir . '/x.md';
        file_put_contents($path, "---\ndescription: probe\n---\nbody");

        foreach (['a:b', 'ab:deploy/staging', 'review'] as $legal) {
            self::assertInstanceOf(
                CommandSpec::class,
                CommandSpec::fromFile($path, $legal),
                "name {$legal} must load — the widen admitted the bundle segment only, and this is one"
            );
        }

        foreach (['a:b:c', ':a', 'a:', '../:x', 'a:../x', 'a:b c', '-a:b'] as $illegal) {
            try {
                CommandSpec::fromFile($path, $illegal);
                self::fail("name {$illegal} must not load");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Unsafe command name', $e->getMessage(), 'the refusal stays the named one');
            }
        }
    }

    public function testBundleNameFoldsIntoTheSegmentGrammar(): void
    {
        $a = $this->bundleAt($this->home . '/dev/mykit', 'My Kit!', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['review' => 'folded']);

        $commands = $this->load([$a]);

        self::assertArrayHasKey('My-Kit:review', $commands, 'spaces and punctuation fold to "-", decoration trims');
        self::assertArrayHasKey('review', $commands, 'the flat alias law is unaffected by the fold');
    }

    public function testABundleNameThatFoldsToNothingRefusesItsFilesWithADisclosure(): void
    {
        $a = $this->bundleAt($this->home . '/dev/junk', '--!!', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['review' => 'homeless']);

        $commands = $this->load([$a], $loader);

        self::assertSame([], array_filter(array_keys($commands), static fn (string $k): bool => str_contains($k, ':')), 'an unusable prefix cannot name a row');
        self::assertArrayNotHasKey('review', $commands, 'and the files do not sneak in unqualified — that is the shadow this step cures');
        self::assertNotEmpty($loader->skippedFiles(), 'the refusal is disclosed per file, not silent');
    }

    public function testRefusedFlatAliasesLeaveNoAttributionEntry(): void
    {
        // review-1 cure, collision face. The staging pass used to write a
        // commandSources entry for EVERY bundle row's flat name, admitted or
        // not; here the flat name is ambiguous between two owners, so the
        // map grew a key for a row no one can invoke — whichever bundle
        // walked last sat under it. Sources are now copied at admission
        // only, so a refused candidate leaves no trace.
        $a = $this->bundleAt($this->home . '/dev/revA', 'revA', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['review' => 'from A']);
        $b = $this->bundleAt($this->home . '/dev/revB', 'revB', 'user', $this->home);
        $this->commandRows($b->dir . '/commands', ['review' => 'from B']);

        $commands = $this->load([$a, $b], $loader);
        self::assertArrayNotHasKey('review', $commands, 'the alias belongs to neither — the refusal under test');

        $sources = $this->commandSources($loader);
        self::assertSame(realpath($a->dir . '/commands/review.md'), $sources['revA:review'], 'the admitted rows keep their own files');
        self::assertSame(realpath($b->dir . '/commands/review.md'), $sources['revB:review']);
        self::assertArrayNotHasKey('review', $sources, 'no loser path, neither bundle\'s, may sit under the refused flat name');
    }

    public function testFlatAliasAttributionIsWrittenAtAdmissionNotStaging(): void
    {
        // review-1 cure, both live faces. 'rev' has one owner and no
        // claimant: admitted, sharing its row's attribution. 'owned' is
        // claimed by a PROJECT-plain row, which discover() walks BEFORE the
        // user bundles — under the old staging the later bundle pass
        // overwrote the plain winner's source with the refused candidate's
        // path, the exact mixed-case misattribution the cure removes.
        $a = $this->bundleAt($this->home . '/dev/revkit', 'revkit', 'user', $this->home);
        $this->commandRows($a->dir . '/commands', ['rev' => 'kit body', 'owned' => 'from the bundle']);
        $this->commandRows($this->project . '/.claude/commands', ['owned' => 'from .claude']);

        $commands = $this->load([$a], $loader);
        self::assertStringContainsString('from .claude', (string) $commands['owned']->template, 'fixture: the plain row is the map winner');
        self::assertArrayHasKey('rev', $commands);

        $sources = $this->commandSources($loader);
        self::assertSame($sources['revkit:rev'], $sources['rev'], 'an admitted alias carries its row\'s attribution');
        self::assertSame(realpath($this->project . '/.claude/commands/owned.md'), $sources['owned'], 'the plain winner keeps its own source — the refused bundle candidate wrote nothing here');
        self::assertStringContainsString('revkit', $sources['revkit:owned'], 'positive control: the bundle\'s qualified row IS attributed, so the leg above is a copy-shape answer, not an absent map');
    }
}
